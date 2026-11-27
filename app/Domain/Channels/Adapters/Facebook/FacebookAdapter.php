<?php

namespace App\Domain\Channels\Adapters\Facebook;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\ConnectedChannels;
use App\Domain\Channels\Contracts\DescribesSetup;
use App\Domain\Channels\Contracts\ExplainsMissingAccounts;
use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Contracts\ReadsMetrics;
use App\Domain\Channels\Contracts\SkippedAccount;
use App\Domain\Channels\Contracts\UploadsMedia;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\NothingToConnect;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Support\LinkWithUtm;
use App\Domain\Content\Exceptions\MediaFileMissing;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Scheduling\PublishingConfig;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Facebook Pages through the Graph API. Every endpoint used here is described
 * in docs/platform-notes/meta.md, with its source.
 *
 * Only Page access tokens are stored. The user tokens met during connect()
 * are used for that one request and then dropped.
 */
final class FacebookAdapter implements ChannelAdapter, DescribesSetup, ExplainsMissingAccounts, ReadsMetrics, UploadsMedia
{
    public const PLATFORM = 'facebook';

    /** The Page task a user needs to publish. Pages without it are not connected. */
    private const PUBLISH_TASK = 'CREATE_CONTENT';

    private const SKIPPED_FOR_ROLE = 'Your role on this Page does not allow creating posts. Change it under Page access in the settings of the Page.';

    private const SKIPPED_FOR_ACCESS = 'Facebook gave no access to this Page.';

    /**
     * Longest Page post. Not stated in the Graph API documentation; this is
     * the limit Facebook has given for posts since 2011. `max_body_length`
     * in the settings replaces it. See docs/platform-notes/meta.md.
     */
    private const MAX_BODY_LENGTH = 63206;

    /**
     * Pages API calls with a Page token are limited to 4800 calls per 24
     * hours for each user who engaged with the Page. This is that formula at
     * one engaged user, the lowest it can meaningfully be.
     * `rate_limit_calls_per_day` in the settings replaces it.
     */
    private const RATE_LIMIT_CALLS = 4800;

    private const RATE_LIMIT_SECONDS = 86400;

    /**
     * Read from the post itself with the permissions every connected Page
     * has. See "Reading the numbers of a post" in docs/platform-notes/meta.md.
     */
    private const METRICS_OF_THE_POST = [Metric::Reactions, Metric::Comments, Metric::Shares];

    /**
     * Read from the insights of the post, which need one more permission
     * (`insights_scope` in the settings). A Page connected without it gets
     * null for these.
     */
    private const METRICS_OF_INSIGHTS = [Metric::Clicks, Metric::Reach];

    /**
     * The id of a Page post as publish() stores it: digits, or two runs of
     * digits joined by an underscore. An id of any other form is never put
     * into a request.
     */
    private const POST_ID = '/\A\d{1,32}(_\d{1,32})?\z/';

    /** A refusal of the insights of a Page is logged once in this time. */
    private const REFUSAL_LOGGED_EVERY_SECONDS = 86400;

    /** Facebook allows 50 requests in one batch. `metrics.batch_size` in the settings can lower it. */
    private const BATCH_SIZE = 50;

    /** Most result pages read from /me/accounts (100 Pages each). */
    private const MAX_ACCOUNT_PAGES = 10;

    /** A Page id and a photo id are both a "numeric string". */
    private const NUMERIC_ID = '/\A[0-9]{1,32}\z/';

    /**
     * "Invalid parameter" and "Missing or invalid image file". Meta does
     * not document what a post gets whose photo has expired; these are the
     * two documented codes that can mean it. See docs/platform-notes/meta.md.
     */
    private const PHOTO_PROBLEM_CODES = [100, 324];

    /** The code Facebook gives for a parameter it does not take. */
    private const INVALID_PARAMETER = 100;

    private const FILE_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    private readonly GraphClient $graph;

    /**
     * ChannelManager keeps this adapter for the life of the process, so it
     * must not hold anything that is scoped to one request or job. The
     * current workspace is such a thing: it is taken from the container each
     * time it is needed, never kept.
     *
     * @param  array<string, mixed>  $settings  config('noongun.channels.facebook') without the adapter key
     */
    public function __construct(
        private readonly array $settings,
        Factory $http,
        private readonly Repository $config,
        private readonly UrlGenerator $url,
        private readonly Container $container,
    ) {
        $timeout = $this->config->get('noongun.publishing.timeout', 30);

        $this->graph = new GraphClient(
            $http,
            self::PLATFORM,
            rtrim($this->setting('graph_url'), '/').'/'.trim($this->setting('api_version'), '/'),
            $this->setting('app_secret'),
            is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 30,
        );
    }

    public function platform(): string
    {
        return self::PLATFORM;
    }

    public function capabilities(): ChannelCapabilities
    {
        $maxBodyLength = $this->settings['max_body_length'] ?? null;

        // Every value for images is a setting. Without the settings
        // Facebook takes no images.
        $types = $this->mediaTypes();
        $items = $this->positiveSetting('max_media_items') ?? 0;
        $rule = $this->settings['media_link_rule'] ?? null;

        return new ChannelCapabilities(
            maxBodyLength: is_numeric($maxBodyLength) && (int) $maxBodyLength > 0 ? (int) $maxBodyLength : self::MAX_BODY_LENGTH,
            mediaTypes: $items > 0 ? $types : [],
            maxMediaItems: $types !== [] ? $items : 0,
            linkPreviews: true,
            rateLimit: new RateLimit($this->positive($this->settings['rate_limit_calls_per_day'] ?? null, self::RATE_LIMIT_CALLS), self::RATE_LIMIT_SECONDS),
            maxMediaBytes: $this->positiveSetting('max_media_bytes'),
            maxMediaPixels: $this->positiveSetting('max_media_pixels'),
            altTextLength: $this->positiveSetting('alt_text_length'),
            mediaLinkRule: (is_string($rule) ? MediaLinkRule::tryFrom($rule) : null) ?? MediaLinkRule::InText,
            mediaUploadLifetime: $this->positiveSetting('media_upload_lifetime'),
            metrics: [...self::METRICS_OF_THE_POST, ...self::METRICS_OF_INSIGHTS],
        );
    }

    public function metricsFor(Channel $channel): array
    {
        $insightsScope = $this->setting('insights_scope');

        return $insightsScope !== '' && $channel->hasScope($insightsScope)
            ? [...self::METRICS_OF_THE_POST, ...self::METRICS_OF_INSIGHTS]
            : self::METRICS_OF_THE_POST;
    }

    /**
     * Facebook counts every read inside a batch as one call. A variant
     * costs one read of the post and, for a channel with the insights
     * permission, one read of its insights. This is the larger of the two:
     * a channel without the permission is counted as if it had it, so the
     * share of the rate limit is never overspent.
     */
    public function metricCallsPerVariant(): int
    {
        return 2;
    }

    /**
     * One batch request for every 25 variants (50 reads). For each variant:
     *
     * - `GET /{post-id}?fields=...` for reactions, comments and shares;
     * - `GET /{post-id}/insights?metric=...&period=...` for clicks and
     *   reach, only when the channel holds the insights permission.
     *
     * The fields, metric names and period are in the settings under
     * `metrics`. What each answer means for the reading:
     *
     * - The post was read: its three numbers, and shares as zero when
     *   Facebook left the field out, which is how it reports a post nobody
     *   shared.
     * - The post is not there: MetricReading::gone().
     * - The insights were read: clicks and reach. They answered with a
     *   missing permission, an empty list or a refusal: both are null, and
     *   the three numbers of the post still stand.
     * - A read was not finished or failed for a moment: no reading for that
     *   variant this time, so that a number is never replaced by "not given"
     *   because of an outage.
     * - One post may not be read with this token: no reading for it. Every
     *   post of the call answered that way: the call fails, as a missing
     *   permission.
     * - The token was refused, or Facebook is limiting requests: the whole
     *   call fails, whatever else arrived.
     *
     * A paging address in an answer is never requested, and a variant that
     * is not of this channel and workspace is never asked about.
     */
    public function fetchMetrics(Channel $channel, array $variants): array
    {
        $this->guardConfigured();

        if ($channel->platform !== self::PLATFORM) {
            throw ChannelException::permanent(self::PLATFORM, 'This channel is not a Facebook Page.');
        }

        if (blank($channel->access_token)) {
            throw ChannelException::reconnectRequired(self::PLATFORM, 'The Page is not connected. Connect it again.');
        }

        $postFields = $this->metricSetting('post_fields');
        $insightMetrics = $this->insightMetricsFor($channel);
        $readsPerVariant = $insightMetrics === [] ? 1 : 2;

        $readings = [];
        $posts = [];

        foreach ($variants as $variant) {
            if ($variant->channel_id !== $channel->id || $variant->workspace_id !== $channel->workspace_id || blank($variant->external_id)) {
                continue;
            }

            $postId = (string) $variant->external_id;

            if (preg_match(self::POST_ID, $postId) !== 1) {
                // Not an id Facebook gave for a post. It can never be read.
                $readings[$variant->id] = MetricReading::gone();

                continue;
            }

            $posts[$variant->id] = $postId;
        }

        if ($postFields === '' || $posts === []) {
            return $readings;
        }

        $firstFailure = null;
        $insightsRefused = null;
        $notPermitted = [];
        $unfinished = false;

        $variantsPerBatch = max(1, intdiv(min($this->positive($this->metricSettings()['batch_size'] ?? null, self::BATCH_SIZE), self::BATCH_SIZE), $readsPerVariant));

        foreach (array_chunk($posts, $variantsPerBatch, preserve_keys: true) as $chunk) {
            $reads = [];

            foreach ($chunk as $postId) {
                $reads[] = ['path' => $postId, 'query' => ['fields' => $postFields]];

                if ($insightMetrics !== []) {
                    $reads[] = ['path' => $postId.'/insights', 'query' => array_filter([
                        'metric' => implode(',', $insightMetrics),
                        'period' => $this->metricSetting('period'),
                    ], fn (string $value): bool => $value !== '')];
                }
            }

            $answers = $this->graph->batch($reads, (string) $channel->access_token);

            foreach (array_keys($chunk) as $position => $variantId) {
                $post = $answers[$position * $readsPerVariant] ?? null;
                $insights = $insightMetrics === [] ? null : ($answers[$position * $readsPerVariant + 1] ?? null);

                foreach ([$post, $insights] as $answer) {
                    if ($answer !== null && in_array($answer->outcome, [GraphOutcome::TokenRefused, GraphOutcome::RateLimited], true)) {
                        throw $answer->failure ?? ChannelException::permanent(self::PLATFORM, 'Facebook refused the request.');
                    }
                }

                if ($post === null) {
                    $unfinished = true;

                    continue;
                }

                if ($post->outcome === GraphOutcome::NotFound) {
                    $readings[$variantId] = MetricReading::gone();

                    continue;
                }

                // One post that may not be read says nothing about the
                // others: it gets no reading. Only when every post of the
                // call was refused this way is it the Page, see below.
                if ($post->outcome === GraphOutcome::PermissionMissing) {
                    $notPermitted[] = $post->failure;

                    continue;
                }

                if (! $post->answered() || ! is_scalar($post->body['id'] ?? null)) {
                    $firstFailure ??= $post->failure;

                    continue;
                }

                $numbers = $this->engagementIn($post->body, $postFields);

                if ($insightMetrics !== []) {
                    if ($insights === null) {
                        $unfinished = true;

                        continue;
                    }

                    if ($insights->outcome === GraphOutcome::Temporary) {
                        $firstFailure ??= $insights->failure;

                        continue;
                    }

                    if ($insights->answered()) {
                        $numbers += $this->insightsIn($insights->body, $insightMetrics);
                    } elseif ($insights->outcome === GraphOutcome::Refused) {
                        $insightsRefused ??= $insights->failure;
                    }
                }

                $readings[$variantId] = MetricReading::fromPlatform($numbers);
            }
        }

        // Without the permission to read any of the posts there is nothing
        // to read on this Page with this token.
        if (count($notPermitted) === count($posts)) {
            throw $notPermitted[0] ?? ChannelException::reconnectRequired(self::PLATFORM, 'A permission is missing. Connect the Page again.');
        }

        // Most likely a metric name that Meta has retired. An operator has
        // to see why two numbers are missing, once a day for each Page and
        // not on every reading.
        if ($insightsRefused !== null && Cache::add("noongun:facebook:insights-refused:{$channel->id}", true, self::REFUSAL_LOGGED_EVERY_SECONDS)) {
            Log::warning('Facebook refused to give the insights of a post. Reach and clicks are left empty. Check the metric names in the settings.', [
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'message' => $insightsRefused->getMessage(),
                ...$insightsRefused->context(),
            ]);
        }

        // Nothing came back at all: that is a failure of the call, so the
        // job waits before it asks again instead of counting the variants
        // as read.
        if ($readings === [] && $firstFailure !== null) {
            throw $firstFailure;
        }

        if ($readings === [] && $unfinished) {
            throw ChannelException::retryable(self::PLATFORM, 'Facebook did not finish reading the posts in time.');
        }

        return $readings;
    }

    public function setupSteps(): array
    {
        return [];
    }

    public function missingSettings(): array
    {
        return array_values(array_filter([
            $this->setting('app_id') === '' ? 'FACEBOOK_APP_ID' : null,
            $this->setting('app_secret') === '' ? 'FACEBOOK_APP_SECRET' : null,
        ]));
    }

    /**
     * Built from APP_URL, not from the current request, because Meta compares
     * it character by character with the address registered in the app.
     */
    public function redirectUri(): string
    {
        $appUrl = $this->config->get('app.url');

        return rtrim(is_string($appUrl) ? $appUrl : '', '/')
            .$this->url->route('channels.callback', ['platform' => self::PLATFORM], false);
    }

    /**
     * /me/accounts does not say what it left out, so this is shown after
     * every connect. The cause is recorded in docs/platform-notes/meta.md.
     */
    public function missingAccountsHint(): string
    {
        return 'A Page you ticked on Facebook is not here? Facebook leaves out Pages that belong to a business portfolio unless the app also has the business_management permission. The README says how to add it.';
    }

    public function authorizationUrl(Workspace $workspace, string $state): string
    {
        $this->guardConfigured();

        $query = http_build_query([
            'client_id' => $this->setting('app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', $this->scopes()),
            // Ask again for a permission that was declined on an earlier attempt.
            'auth_type' => 'rerequest',
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($this->setting('dialog_url'), '/').'/'.trim($this->setting('api_version'), '/').'/dialog/oauth?'.$query;
    }

    public function connect(Workspace $workspace, #[SensitiveParameter] string $code): Collection
    {
        $this->guardConfigured();

        $shortLived = $this->tokenFrom($this->graph->send('GET', 'oauth/access_token', [
            'client_id' => $this->setting('app_id'),
            'redirect_uri' => $this->redirectUri(),
            'client_secret' => $this->setting('app_secret'),
            'code' => $code,
        ], safeToRepeat: true));

        $longLived = $this->tokenFrom($this->graph->send('GET', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->setting('app_id'),
            'client_secret' => $this->setting('app_secret'),
            'fb_exchange_token' => $shortLived,
        ], safeToRepeat: true));

        ['pages' => $granted, 'skipped' => $skipped] = $this->pagesOf($longLived);
        $pages = [];

        foreach ($granted as $page) {
            // A new connection has to be proven: an answer that does not say
            // the token is valid, or names no permissions, is refused.
            $inspection = $this->inspect($page['token']);
            $inspection['scopes'] ??= [];
            $missing = array_values(array_diff($this->requiredScopes(), $inspection['scopes']));

            if ($inspection['valid'] !== true || $missing !== []) {
                throw ChannelException::permanent(
                    self::PLATFORM,
                    $inspection['valid'] !== true
                        ? "Facebook gave a token for {$page['name']} that is not valid. Connect again."
                        : 'Facebook did not grant every permission that is needed ('.implode(', ', $missing).'). Connect again and allow all of them.',
                    ['page_id' => $page['id'], 'missing_scopes' => $missing],
                );
            }

            $pages[] = [...$page, ...$inspection];
        }

        if ($pages === []) {
            throw NothingToConnect::because(
                self::PLATFORM,
                'Facebook returned no Page that this account can publish to. Choose at least one Page in the Facebook dialog, and make sure the account can create content on it.',
                $skipped,
            );
        }

        // Channel::recordConnection() decides the status: only a channel the
        // user already has connected is active straight away.
        $channels = $this->container->make(CurrentWorkspace::class)->run($workspace, fn (): Collection => DB::transaction(
            fn (): Collection => (new Collection($pages))->map(fn (array $page): Channel => Channel::recordConnection(
                self::PLATFORM,
                $page['id'],
                [
                    'display_name' => $page['name'],
                    'access_token' => $page['token'],
                    'refresh_token' => null,
                    'token_expires_at' => $page['expires_at'],
                    'scopes' => $page['scopes'],
                ],
            ))->values(),
        ));

        return ConnectedChannels::with($channels, $skipped);
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $this->guardConfigured(PublishFailed::class);

        $channel = $variant->channel;

        if ($channel === null || $channel->platform !== self::PLATFORM) {
            throw PublishFailed::permanent(self::PLATFORM, 'This post is not addressed to a Facebook Page.');
        }

        if (blank($channel->access_token)) {
            throw PublishFailed::reconnectRequired(self::PLATFORM, 'The Page is not connected. Connect it again.');
        }

        $images = $variant->images()->values();

        if ($images->isNotEmpty()) {
            return $this->publishWithImages($variant, $images);
        }

        $parameters = ['message' => $variant->body];

        if (filled($variant->link)) {
            $parameters['link'] = LinkWithUtm::apply((string) $variant->link, $variant->utm ?? []);
        }

        // One call, no read-after-write. Creating a post is not safe to repeat:
        // a failure is retryable only when Facebook certainly made no post.
        $response = $this->graph->send('POST', $channel->external_id.'/feed', $parameters, $channel->access_token, PublishFailed::class, safeToRepeat: false);

        $id = $response['id'] ?? null;

        // An id of another form is not stored: it would later become part
        // of an address. The post may still be there, so this is an unknown
        // outcome like a missing id, never a success.
        if (! is_string($id) || preg_match(self::POST_ID, $id) !== 1) {
            // Not retryable: the post may be public, and a second call would duplicate it.
            throw PublishFailed::permanent(
                self::PLATFORM,
                'Facebook accepted the post but returned no usable id for it. Check the Page before trying again.',
                ['endpoint' => 'POST /'.$channel->external_id.'/feed', 'outcome_unknown' => true],
            );
        }

        return new PublishResult($id, $this->permalink($id), now());
    }

    /**
     * Sends one image to the Page as a photo that is not published, with
     * its description, and returns the photo id. The file is read from its
     * stream and goes to the Graph API in the body of the request: Facebook
     * is never given an address to fetch it from.
     */
    public function uploadMedia(PostVariant $variant, MediaAttachment $attachment): string
    {
        $this->guardConfigured(PublishFailed::class);

        $channel = $this->pageFor($variant);
        $number = $this->numberOf($variant, $attachment);
        $endpoint = 'POST /'.$channel->external_id.'/photos';

        // Unpublished: the photo is in no feed and on no timeline until a
        // post names it. Facebook keeps it for about a day.
        $parameters = ['published' => 'false'];

        if (filled($attachment->alt_text)) {
            $parameters['alt_text_custom'] = (string) $attachment->alt_text;
        }

        try {
            $mimeType = $attachment->image()->mime;
            $stream = $attachment->readStream();
        } catch (MediaFileMissing) {
            throw PublishFailed::permanent(
                self::PLATFORM,
                "The file of image {$number} is missing from the storage of this instance, so it was not sent to Facebook.",
                ['endpoint' => $endpoint],
            );
        }

        try {
            $response = $this->graph->upload(
                $channel->external_id.'/photos',
                $parameters,
                (string) $channel->access_token,
                'source',
                $stream,
                'image-'.$number.'.'.(self::FILE_EXTENSIONS[$mimeType] ?? 'img'),
                $mimeType,
                PublishingConfig::mediaTimeout(),
                PublishFailed::class,
                "Facebook did not take image {$number} of this post.",
            );
        } catch (PublishFailed $refusal) {
            throw $this->withDescriptionLimit($refusal, $attachment, $number);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $id = $response['id'] ?? null;
        $id = is_int($id) ? (string) $id : $id;

        if (! is_string($id) || $id === '' || preg_match(self::NUMERIC_ID, $id) !== 1) {
            // Nothing public was made, so sending the file again is safe.
            throw PublishFailed::retryable(
                self::PLATFORM,
                "Facebook took image {$number} but gave no usable id for it.",
                context: ['endpoint' => $endpoint],
            );
        }

        return $id;
    }

    public function delete(PostVariant $variant): void
    {
        $this->guardConfigured();

        $channel = $variant->channel;

        if ($channel === null || blank($variant->external_id)) {
            throw ChannelException::permanent(self::PLATFORM, 'This post is not on Facebook, so there is nothing to delete.');
        }

        if (preg_match(self::POST_ID, (string) $variant->external_id) !== 1) {
            throw ChannelException::permanent(self::PLATFORM, 'The id stored for this post is not one Facebook gives, so it cannot be deleted from here.');
        }

        if (blank($channel->access_token)) {
            throw ChannelException::reconnectRequired(self::PLATFORM, 'The Page is not connected. Connect it again.');
        }

        $response = $this->graph->send('DELETE', (string) $variant->external_id, [], $channel->access_token, safeToRepeat: true);

        if (($response['success'] ?? false) !== true) {
            throw ChannelException::permanent(self::PLATFORM, 'Facebook did not confirm that the post was deleted.');
        }
    }

    /**
     * One call to the Page feed that names the photos uploadMedia() sent,
     * in their order. One photo goes the same way as several, so that the
     * text, the id and the permalink are those of a Page post either way.
     *
     * @param  Collection<int, MediaAttachment>  $images
     */
    private function publishWithImages(PostVariant $variant, Collection $images): PublishResult
    {
        $channel = $this->pageFor($variant);
        $endpoint = 'POST /'.$channel->external_id.'/feed';
        $capabilities = $this->capabilities();

        // What was approved: the body, and the link as its last line when
        // the images take the place of the preview.
        $parameters = ['message' => $variant->textFor($capabilities)];

        $link = $variant->linkToSend();

        if ($link !== null && ! $capabilities->linkGoesInText($link, $images->count())) {
            $parameters['link'] = $link;
        }

        foreach ($images as $index => $attachment) {
            $photoId = $attachment->external_id;

            if (! is_string($photoId) || preg_match(self::NUMERIC_ID, $photoId) !== 1) {
                // No call was made. The job sends the images again.
                throw PublishFailed::uploadsNoLongerValid(
                    self::PLATFORM,
                    'Image '.($index + 1).' of this post is not on Facebook yet. Nothing was published.',
                    ['endpoint' => $endpoint],
                );
            }

            $parameters["attached_media[{$index}]"] = (string) json_encode(['media_fbid' => $photoId]);
        }

        // As for a post without images: one call, not safe to repeat, and
        // a failure is retryable only when Facebook certainly made no post.
        try {
            $response = $this->graph->send('POST', $channel->external_id.'/feed', $parameters, $channel->access_token, PublishFailed::class, safeToRepeat: false);
        } catch (PublishFailed $refusal) {
            throw $this->blamesUploads($refusal, $images)
                ? $this->uploadsNoLongerValid($refusal)
                : $this->withCountLimit($refusal, $images->count());
        }

        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            // Not retryable: the post may be public, and a second call would duplicate it.
            throw PublishFailed::permanent(
                self::PLATFORM,
                'Facebook accepted the post but returned no id for it. Check the Page before trying again.',
                ['endpoint' => $endpoint, 'outcome_unknown' => true],
            );
        }

        return new PublishResult($id, $this->permalink($id), now());
    }

    /**
     * True when Facebook refused the post outright, with an error that can
     * be about its photos, and at least one of them was uploaded longer
     * ago than "media_upload_trusted" seconds. Meta does not document the
     * error for a photo that expired, and "about 24 hours" is not exact,
     * so the age of the uploads is what tells an expired photo from a
     * post Facebook will never take: photos sent a moment ago are not
     * blamed. After one new upload a second refusal is therefore final.
     *
     * @param  Collection<int, MediaAttachment>  $images
     */
    private function blamesUploads(PublishFailed $refusal, Collection $images): bool
    {
        if (! $this->refusedOutright($refusal, self::PHOTO_PROBLEM_CODES)) {
            return false;
        }

        $trusted = now()->subSeconds($this->positiveSetting('media_upload_trusted') ?? 0);

        return $images->contains(
            fn (MediaAttachment $attachment): bool => $attachment->uploaded_at === null || $attachment->uploaded_at->lte($trusted),
        );
    }

    private function uploadsNoLongerValid(PublishFailed $refusal): PublishFailed
    {
        return PublishFailed::uploadsNoLongerValid(
            self::PLATFORM,
            $refusal->getMessage().' The images of this post were sent to Facebook some time ago and may no longer be kept there, so they are sent again.',
            $this->contextOf($refusal),
        );
    }

    /**
     * Whether Facebook answered with an error of one of these codes that
     * is a plain "no": not a limit on requests, not a token, and not an
     * answer that leaves open what happened.
     *
     * @param  list<int>  $codes
     */
    private function refusedOutright(PublishFailed $refusal, array $codes): bool
    {
        $context = $refusal->context();

        return ! $refusal->retryable
            && ! $refusal->requiresReconnect
            && ($context['outcome_unknown'] ?? false) !== true
            && in_array($context['code'] ?? null, $codes, true);
    }

    /**
     * How many images a post may carry is a setting that Meta gives no
     * number for, so the number in use is a guess. When Facebook refuses
     * a post with several images as an invalid parameter, the refusal is
     * given back with that said, and with where the number is changed.
     * It is certain only when Facebook's own words name the images;
     * otherwise it is offered as a possible cause. The code Facebook
     * gave stays in the context, which the job writes to the log.
     */
    private function withCountLimit(PublishFailed $refusal, int $count): PublishFailed
    {
        if ($count < 2 || ! $this->refusedOutright($refusal, [self::INVALID_PARAMETER])) {
            return $refusal;
        }

        $allowed = $this->capabilities()->maxMediaItems;
        $named = str_contains($refusal->getMessage(), 'attached_media');

        return PublishFailed::permanent(
            self::PLATFORM,
            $refusal->getMessage().($named
                ? " Facebook's answer is about the images of this post, and it has {$count}."
                : " One possible cause is the number of images on this post, which is {$count}.")
                ." Meta documents no limit for it, and the {$allowed} this instance allows for Facebook are an assumption. Take images off the post, or have the limit lowered in the configuration of the instance (FACEBOOK_MAX_MEDIA_ITEMS).",
            [...$this->contextOf($refusal), 'suspected_limit' => 'max_media_items', 'limit_named_by_platform' => $named, 'images' => $count],
        );
    }

    /**
     * The same for the description of an image, whose longest length is
     * also a guess.
     */
    private function withDescriptionLimit(PublishFailed $refusal, MediaAttachment $attachment, int $number): PublishFailed
    {
        $length = mb_strlen((string) $attachment->alt_text);

        if ($length === 0 || ! $this->refusedOutright($refusal, [self::INVALID_PARAMETER])) {
            return $refusal;
        }

        $allowed = $this->capabilities()->altTextLength;
        $named = str_contains($refusal->getMessage(), 'alt_text_custom');

        return PublishFailed::permanent(
            self::PLATFORM,
            $refusal->getMessage().($named
                ? " Facebook's answer is about the description of image {$number}, which is {$length} characters long."
                : " One possible cause is the description of image {$number}, which is {$length} characters long.")
                ." Meta documents no limit for it, and the {$allowed} characters this instance allows for Facebook are an assumption. Shorten the description, or have the limit lowered in the configuration of the instance (FACEBOOK_ALT_TEXT_LENGTH).",
            [...$this->contextOf($refusal), 'suspected_limit' => 'alt_text_length', 'limit_named_by_platform' => $named, 'description_length' => $length],
        );
    }

    /**
     * What a failure carries, without the fields every failure adds itself.
     *
     * @return array<string, mixed>
     */
    private function contextOf(PublishFailed $refusal): array
    {
        return array_diff_key($refusal->context(), array_flip(['platform', 'retryable', 'retry_after_seconds', 'requires_reconnect']));
    }

    /**
     * The connected Page a variant with images goes to. Its id becomes
     * part of the address a file is sent to, so it must be a Page id and
     * nothing else.
     */
    private function pageFor(PostVariant $variant): Channel
    {
        $channel = $variant->channel;

        if ($channel === null || $channel->platform !== self::PLATFORM || preg_match(self::NUMERIC_ID, $channel->external_id) !== 1) {
            throw PublishFailed::permanent(self::PLATFORM, 'This post is not addressed to a Facebook Page.');
        }

        if (blank($channel->access_token)) {
            throw PublishFailed::reconnectRequired(self::PLATFORM, 'The Page is not connected. Connect it again.');
        }

        return $channel;
    }

    /**
     * The place of an image on its variant, from 1, as the editor shows it.
     */
    private function numberOf(PostVariant $variant, MediaAttachment $attachment): int
    {
        $index = $variant->images()->values()->search(fn (MediaAttachment $other): bool => $other->is($attachment));

        return is_int($index) ? $index + 1 : max(1, $attachment->position);
    }

    /**
     * @return list<string>
     */
    private function mediaTypes(): array
    {
        $types = $this->settings['media_types'] ?? [];

        return array_values(array_filter(
            array_map(fn (mixed $type): string => is_string($type) ? strtolower(trim($type)) : '', is_array($types) ? $types : []),
            fn (string $type): bool => $type !== '',
        ));
    }

    private function positiveSetting(string $key): ?int
    {
        $value = $this->settings[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * A Page token cannot be renewed through the API: it has no expiry and
     * is replaced only by connecting again. So this checks the token with
     * debug_token, records what Facebook reports about it, and marks the
     * channel when the token is no longer usable.
     */
    public function refreshToken(Channel $channel): Channel
    {
        // Neither is connected: a disconnected channel holds no token, and a
        // pending one is waiting for the user, not for a check.
        if ($channel->status === ChannelStatus::Disconnected || $channel->status === ChannelStatus::Pending) {
            return $channel;
        }

        if (blank($channel->access_token)) {
            return $channel->markNeedsReconnect();
        }

        $this->guardConfigured();

        $inspection = $this->inspect((string) $channel->access_token);

        // Marking a channel stops it from publishing, so it takes a definite
        // answer. One that does not say whether the token is valid is a
        // failed check, not a bad token.
        if ($inspection['valid'] === null) {
            throw ChannelException::retryable(
                self::PLATFORM,
                'Facebook answered the check of the token in a form that could not be read. The channel was left as it is.',
                context: ['endpoint' => 'GET /debug_token'],
            );
        }

        if ($inspection['valid'] === false) {
            return $channel->markNeedsReconnect();
        }

        $channel->token_expires_at = $inspection['expires_at'];
        // An answer that names no permissions proves nothing about them:
        // the ones recorded at the connect stand.
        $channel->scopes = $inspection['scopes'] ?? $channel->scopes;

        if ($channel->tokenExpired() || ($inspection['scopes'] !== null && array_diff($this->requiredScopes(), $inspection['scopes']) !== [])) {
            return $channel->markNeedsReconnect();
        }

        $channel->save();

        return $channel;
    }

    /**
     * Every Page the user granted that they can publish to, and the Pages
     * Facebook listed that cannot be connected. A Page Facebook does not
     * list at all cannot be known here.
     *
     * @return array{pages: list<array{id: string, name: string, token: string}>, skipped: list<SkippedAccount>}
     */
    private function pagesOf(#[SensitiveParameter] string $userToken): array
    {
        $pages = [];
        $skipped = [];
        $after = null;

        for ($i = 0; $i < self::MAX_ACCOUNT_PAGES; $i++) {
            $response = $this->graph->send('GET', 'me/accounts', array_filter([
                'fields' => 'id,name,tasks,access_token',
                'limit' => 100,
                'after' => $after,
            ], fn (mixed $value): bool => $value !== null), $userToken, safeToRepeat: true);

            foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $page) {
                if (! is_array($page) || ! is_scalar($page['id'] ?? null)) {
                    continue;
                }

                $id = (string) $page['id'];
                $name = is_string($page['name'] ?? null) && $page['name'] !== '' ? $page['name'] : $id;

                if (is_array($page['tasks'] ?? null) && ! in_array(self::PUBLISH_TASK, $page['tasks'], true)) {
                    $skipped[] = new SkippedAccount($id, $name, self::SKIPPED_FOR_ROLE);

                    continue;
                }

                if (! is_string($page['access_token'] ?? null) || $page['access_token'] === '') {
                    $skipped[] = new SkippedAccount($id, $name, self::SKIPPED_FOR_ACCESS);

                    continue;
                }

                $pages[] = ['id' => $id, 'name' => $name, 'token' => $page['access_token']];
            }

            // The "next" link holds the user token, so it is never followed
            // or stored. Its presence only says there is another page.
            $paging = is_array($response['paging'] ?? null) ? $response['paging'] : [];
            $cursor = is_array($paging['cursors'] ?? null) ? ($paging['cursors']['after'] ?? null) : null;

            if (! isset($paging['next']) || ! is_string($cursor) || $cursor === '') {
                break;
            }

            $after = $cursor;
        }

        return ['pages' => $pages, 'skipped' => $skipped];
    }

    /**
     * What debug_token reports about a token. An expires_at of 0 means the
     * token does not expire. `valid` is null when the answer does not say,
     * and `scopes` is null when it names none: neither is then known.
     *
     * @return array{valid: bool|null, scopes: list<string>|null, expires_at: CarbonImmutable|null}
     */
    private function inspect(#[SensitiveParameter] string $token): array
    {
        $response = $this->graph->send('GET', 'debug_token', [
            'input_token' => $token,
            'access_token' => $this->setting('app_id').'|'.$this->setting('app_secret'),
        ], safeToRepeat: true);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $expiresAt = is_numeric($data['expires_at'] ?? null) ? (int) $data['expires_at'] : 0;
        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : null;

        return [
            'valid' => is_bool($data['is_valid'] ?? null) ? $data['is_valid'] : null,
            'scopes' => $scopes === null ? null : array_values(array_filter($scopes, is_string(...))),
            'expires_at' => $expiresAt > 0 ? CarbonImmutable::createFromTimestampUTC($expiresAt) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function tokenFrom(#[SensitiveParameter] array $response): string
    {
        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw ChannelException::permanent(self::PLATFORM, 'Facebook answered the sign-in without an access token. Connect again.');
        }

        return $token;
    }

    private function permalink(string $postId): string
    {
        return rtrim($this->setting('dialog_url'), '/').'/'.$postId;
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = $this->settings['scopes'] ?? [];

        return array_values(array_filter(is_array($scopes) ? $scopes : [], fn (mixed $scope): bool => is_string($scope) && $scope !== ''));
    }

    /**
     * The permissions without which a Page cannot be connected or kept. The
     * insights permission is asked for when it is configured, but it is
     * never one of these: a Page that was not granted it publishes as
     * before and only lacks reach and clicks.
     *
     * @return list<string>
     */
    private function requiredScopes(): array
    {
        return array_values(array_diff($this->scopes(), [$this->setting('insights_scope')]));
    }

    /**
     * The names of the insights metrics to ask for, keyed by the metric
     * they fill. Empty for a channel without the insights permission.
     *
     * @return array<string, string>
     */
    private function insightMetricsFor(Channel $channel): array
    {
        if (! in_array(self::METRICS_OF_INSIGHTS[0], $this->metricsFor($channel), true)) {
            return [];
        }

        return array_filter([
            Metric::Clicks->value => $this->metricSetting('clicks'),
            Metric::Reach->value => $this->metricSetting('reach'),
        ], fn (string $name): bool => preg_match('/^[a-z0-9_]+$/', $name) === 1);
    }

    /**
     * Reactions, comments and shares from the answer for a post. Whether a
     * value is a usable number is decided by MetricReading::fromPlatform().
     *
     * Facebook leaves `shares` out for a post nobody shared. So when the
     * field was asked for and the post was read, a missing `shares` is zero;
     * a `shares` that is there without a count is not.
     *
     * @param  array<array-key, mixed>  $post
     * @return array<string, mixed>
     */
    private function engagementIn(array $post, string $fields): array
    {
        $shares = array_key_exists('shares', $post)
            ? self::dig($post, 'shares', 'count')
            : (preg_match('/(^|,)\s*shares\b/', $fields) === 1 ? 0 : null);

        return [
            Metric::Reactions->value => self::dig($post, 'reactions', 'summary', 'total_count'),
            Metric::Comments->value => self::dig($post, 'comments', 'summary', 'total_count'),
            Metric::Shares->value => $shares,
        ];
    }

    /**
     * Clicks and reach from the answer of the insights edge: a list of
     * entries with `name`, `period` and `values`, of which the first holds
     * `value`. An entry for another period than the one asked for, and a
     * name that was not asked for, are ignored. An empty list gives nothing.
     *
     * @param  array<array-key, mixed>  $insights
     * @param  array<string, string>  $metrics  metric names of Facebook keyed by Metric value
     * @return array<string, mixed>
     */
    private function insightsIn(array $insights, array $metrics): array
    {
        $period = $this->metricSetting('period');
        $numbers = [];

        foreach (is_array($insights['data'] ?? null) ? $insights['data'] : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $metric = array_search($entry['name'], $metrics, true);

            if ($metric === false || ($period !== '' && isset($entry['period']) && $entry['period'] !== $period)) {
                continue;
            }

            $numbers[$metric] = self::dig($entry, 'values', 0, 'value');
        }

        return $numbers;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function dig(array $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metricSettings(): array
    {
        return is_array($this->settings['metrics'] ?? null) ? $this->settings['metrics'] : [];
    }

    private function metricSetting(string $key): string
    {
        $value = $this->metricSettings()[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function setting(string $key): string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  class-string<ChannelException>  $failure
     */
    private function guardConfigured(string $failure = ChannelException::class): void
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            throw $failure::permanent(
                self::PLATFORM,
                'Facebook is not configured on this instance. Set '.implode(' and ', $missing).' first.',
            );
        }
    }
}
