<?php

namespace App\Domain\Channels\Adapters\Facebook;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\ConnectedChannels;
use App\Domain\Channels\Contracts\DescribesSetup;
use App\Domain\Channels\Contracts\ExplainsMissingAccounts;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Contracts\ReadsMetrics;
use App\Domain\Channels\Contracts\SkippedAccount;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\NothingToConnect;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Support\LinkWithUtm;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Facebook Pages through the Graph API. Every endpoint used here is described
 * in docs/platform-notes/meta.md, with its source.
 *
 * Only Page access tokens are stored. The user tokens met during connect()
 * are used for that one request and then dropped.
 */
final class FacebookAdapter implements ChannelAdapter, DescribesSetup, ExplainsMissingAccounts, ReadsMetrics
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

    /** Most result pages read from /me/accounts (100 Pages each). */
    private const MAX_ACCOUNT_PAGES = 10;

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

        return new ChannelCapabilities(
            maxBodyLength: is_numeric($maxBodyLength) && (int) $maxBodyLength > 0 ? (int) $maxBodyLength : self::MAX_BODY_LENGTH,
            // Photo and media posts are not built yet (M3 is text and link).
            mediaTypes: [],
            maxMediaItems: 0,
            linkPreviews: true,
            rateLimit: new RateLimit(self::RATE_LIMIT_CALLS, self::RATE_LIMIT_SECONDS),
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
     * Facebook counts every post id in a request as one call, also when ids
     * are combined or batched. One read of the post and one of its insights.
     */
    public function metricCallsPerVariant(): int
    {
        return 2;
    }

    /**
     * NOT IMPLEMENTED YET (M6, issue #6): no call is made and no variant
     * gets a reading, so FetchMetricsJob stores nothing for Facebook and
     * nothing fails. The calls to make are described under "Reading the
     * numbers of a post" in docs/platform-notes/meta.md.
     */
    public function fetchMetrics(Channel $channel, array $variants): array
    {
        return [];
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
            $inspection = $this->inspect($page['token']);
            $missing = array_values(array_diff($this->scopes(), $inspection['scopes']));

            if (! $inspection['valid'] || $missing !== []) {
                throw ChannelException::permanent(
                    self::PLATFORM,
                    ! $inspection['valid']
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

        $parameters = ['message' => $variant->body];

        if (filled($variant->link)) {
            $parameters['link'] = LinkWithUtm::apply((string) $variant->link, $variant->utm ?? []);
        }

        // One call, no read-after-write. Creating a post is not safe to repeat:
        // a failure is retryable only when Facebook certainly made no post.
        $response = $this->graph->send('POST', $channel->external_id.'/feed', $parameters, $channel->access_token, PublishFailed::class, safeToRepeat: false);

        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            // Not retryable: the post may be public, and a second call would duplicate it.
            throw PublishFailed::permanent(
                self::PLATFORM,
                'Facebook accepted the post but returned no id for it. Check the Page before trying again.',
                ['endpoint' => 'POST /'.$channel->external_id.'/feed', 'outcome_unknown' => true],
            );
        }

        return new PublishResult($id, $this->permalink($id), now());
    }

    public function delete(PostVariant $variant): void
    {
        $this->guardConfigured();

        $channel = $variant->channel;

        if ($channel === null || blank($variant->external_id)) {
            throw ChannelException::permanent(self::PLATFORM, 'This post is not on Facebook, so there is nothing to delete.');
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

        if (! $inspection['valid']) {
            return $channel->markNeedsReconnect();
        }

        $channel->token_expires_at = $inspection['expires_at'];
        $channel->scopes = $inspection['scopes'];

        if ($channel->tokenExpired() || array_diff($this->scopes(), $inspection['scopes']) !== []) {
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
     * token does not expire.
     *
     * @return array{valid: bool, scopes: list<string>, expires_at: CarbonImmutable|null}
     */
    private function inspect(#[SensitiveParameter] string $token): array
    {
        $response = $this->graph->send('GET', 'debug_token', [
            'input_token' => $token,
            'access_token' => $this->setting('app_id').'|'.$this->setting('app_secret'),
        ], safeToRepeat: true);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $expiresAt = is_numeric($data['expires_at'] ?? null) ? (int) $data['expires_at'] : 0;
        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        return [
            'valid' => ($data['is_valid'] ?? false) === true,
            'scopes' => array_values(array_filter($scopes, is_string(...))),
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
