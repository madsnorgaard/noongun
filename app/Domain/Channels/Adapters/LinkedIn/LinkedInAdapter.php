<?php

namespace App\Domain\Channels\Adapters\LinkedIn;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\DescribesSetup;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Exceptions\ChannelException;
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
 * A member's own LinkedIn profile, through the self-serve products "Share on
 * LinkedIn" and "Sign In with LinkedIn using OpenID Connect" (ADR 0007).
 * Every endpoint used here is described in docs/platform-notes/linkedin.md,
 * with its source.
 *
 * One channel is one member. Its external id is the `sub` that LinkedIn
 * gives for the member, and posts are authored as urn:li:person:{sub}.
 *
 * LinkedIn has no idempotency key and a self-serve app cannot read a
 * member's posts back, so after a publish whose outcome is unknown nothing
 * here can find out what happened. Such a failure is never retryable, and
 * the variant keeps its mark until a person has looked at the profile.
 */
final class LinkedInAdapter implements ChannelAdapter, DescribesSetup
{
    public const PLATFORM = 'linkedin';

    /**
     * Longest post text. The Posts API documentation names no number and
     * answers FIELD_LENGTH_TOO_LONG above its limit; 3000 is the limit of
     * the LinkedIn composer. `max_body_length` in the settings replaces it.
     */
    private const MAX_BODY_LENGTH = 3000;

    /** The article schema asks for a title of "less than 400 characters". */
    private const LINK_TITLE_LENGTH = 399;

    /** And for a description of "less than 4,086 characters". */
    private const LINK_DESCRIPTION_LENGTH = 4085;

    /**
     * Share on LinkedIn allows 150 requests per member per day (UTC).
     * `rate_limit_per_day` in the settings replaces it.
     */
    private const RATE_LIMIT_CALLS = 150;

    private const RATE_LIMIT_SECONDS = 86400;

    /** What a member id and the id part of a URN may consist of. */
    private const ID = '[A-Za-z0-9_-]{1,100}';

    private readonly LinkedInClient $client;

    /**
     * ChannelManager keeps this adapter for the life of the process, so it
     * holds nothing that belongs to one request or job. The current
     * workspace is taken from the container each time it is needed.
     *
     * @param  array<string, mixed>  $settings  config('noongun.channels.linkedin') without the adapter key
     */
    public function __construct(
        private readonly array $settings,
        Factory $http,
        private readonly Repository $config,
        private readonly UrlGenerator $url,
        private readonly Container $container,
    ) {
        $timeout = $this->config->get('noongun.publishing.timeout', 30);

        $this->client = new LinkedInClient(
            $http,
            self::PLATFORM,
            rtrim($this->setting('api_url'), '/'),
            rtrim($this->setting('web_url'), '/'),
            $this->setting('api_version'),
            $this->setting('client_secret'),
            is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 30,
        );
    }

    public function platform(): string
    {
        return self::PLATFORM;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            maxBodyLength: $this->positiveSetting('max_body_length') ?? self::MAX_BODY_LENGTH,
            // Image posts wait for the media model (ADR 0003).
            mediaTypes: [],
            maxMediaItems: 0,
            // The Posts API reads nothing from a linked page: the author
            // supplies the card.
            linkPreviews: false,
            rateLimit: new RateLimit($this->positiveSetting('rate_limit_per_day') ?? self::RATE_LIMIT_CALLS, self::RATE_LIMIT_SECONDS),
            linkTitleLength: self::LINK_TITLE_LENGTH,
            linkDescriptionLength: self::LINK_DESCRIPTION_LENGTH,
        );
    }

    public function setupSteps(): array
    {
        return [
            'In the LinkedIn app, on the Products tab, add both "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect". Without the first Noon Gun may not post, and without the second it cannot tell whose profile it is.',
        ];
    }

    public function missingSettings(): array
    {
        return array_values(array_filter([
            $this->setting('client_id') === '' ? 'LINKEDIN_CLIENT_ID' : null,
            $this->setting('client_secret') === '' ? 'LINKEDIN_CLIENT_SECRET' : null,
        ]));
    }

    /**
     * Built from APP_URL, not from the current request, because LinkedIn
     * compares it with the address registered in the app, and the same
     * value must be sent again when the code is exchanged.
     */
    public function redirectUri(): string
    {
        $appUrl = $this->config->get('app.url');

        return rtrim(is_string($appUrl) ? $appUrl : '', '/')
            .$this->url->route('channels.callback', ['platform' => self::PLATFORM], false);
    }

    public function authorizationUrl(Workspace $workspace, string $state): string
    {
        $this->guardConfigured();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', $this->scopes()),
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($this->setting('web_url'), '/').'/oauth/v2/authorization?'.$query;
    }

    public function connect(Workspace $workspace, #[SensitiveParameter] string $code): Collection
    {
        $this->guardConfigured();

        $grant = $this->grantFrom($this->client->oauth('accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'redirect_uri' => $this->redirectUri(),
        ]));

        // Only the permission to post is asked of this answer, and only
        // when the answer names scopes at all: it is not known whether
        // LinkedIn lists openid and profile here, and the userinfo call
        // below proves those two by working.
        if ($this->lacksPublishScope($grant['scopes'])) {
            throw ChannelException::permanent(
                self::PLATFORM,
                'LinkedIn did not give Noon Gun permission to post. In the LinkedIn app, check that the product "Share on LinkedIn" is added, then connect again.',
                ['missing_scope' => $this->setting('publish_scope'), 'granted_scopes' => $grant['scopes']],
            );
        }

        $member = $this->memberOf($grant['access_token']);

        // Channel::recordConnection() decides the status: only a profile the
        // user already has connected is active straight away.
        return $this->container->make(CurrentWorkspace::class)->run($workspace, fn (): Collection => DB::transaction(
            fn (): Collection => new Collection([Channel::recordConnection(self::PLATFORM, $member['id'], [
                'display_name' => $member['name'],
                'access_token' => $grant['access_token'],
                'refresh_token' => $grant['refresh_token'],
                'token_expires_at' => $grant['expires_at'],
                'scopes' => $grant['scopes'] ?? [],
            ])]),
        ));
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $this->guardConfigured(PublishFailed::class);

        $channel = $variant->channel;

        if ($channel === null || $channel->platform !== self::PLATFORM) {
            throw PublishFailed::permanent(self::PLATFORM, 'This post is not addressed to a LinkedIn profile.');
        }

        if (blank($channel->access_token)) {
            throw PublishFailed::reconnectRequired(self::PLATFORM, 'The profile is not connected. Connect it again.');
        }

        if (preg_match('/^'.self::ID.'\z/', $channel->external_id) !== 1) {
            throw PublishFailed::permanent(self::PLATFORM, 'The connection to this profile is damaged. Disconnect it on the Channels page and connect it again.');
        }

        if (! mb_check_encoding($variant->body, 'UTF-8')) {
            throw PublishFailed::permanent(self::PLATFORM, 'The text holds characters that cannot be read. Return the post to draft and type the text again.');
        }

        $post = [
            'author' => 'urn:li:person:'.$channel->external_id,
            'commentary' => LittleText::escape($variant->body),
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if (filled($variant->link)) {
            $post['content'] = ['article' => $this->article($variant)];
        }

        // One call, no read-after-write. Creating a post is not safe to
        // repeat: a failure is retryable only when LinkedIn certainly made
        // no post.
        $response = $this->client->api('POST', 'rest/posts', $channel->access_token, $post, failure: PublishFailed::class, safeToRepeat: false);

        $urn = $response['id'];

        if ($urn === null || preg_match('/^urn:li:[A-Za-z]{1,40}:'.self::ID.'\z/', $urn) !== 1) {
            // Not retryable: the post may be public, and a second call would duplicate it.
            throw $this->client->outcomeUnknown(
                PublishFailed::class,
                'LinkedIn accepted the post but did not say where it is.',
                ['endpoint' => 'POST /rest/posts', 'http_status' => $response['status']],
            );
        }

        return new PublishResult($urn, $this->permalink($urn), now());
    }

    public function delete(PostVariant $variant): void
    {
        $this->guardConfigured();

        $channel = $variant->channel;

        if ($channel === null || blank($variant->external_id)) {
            throw ChannelException::permanent(self::PLATFORM, 'This post is not on LinkedIn, so there is nothing to delete.');
        }

        if (blank($channel->access_token)) {
            throw ChannelException::reconnectRequired(self::PLATFORM, 'The profile is not connected. Connect it again.');
        }

        // Documented as idempotent: deleting a post that is gone answers 204 too.
        $this->client->api(
            'DELETE',
            'rest/posts/'.rawurlencode((string) $variant->external_id),
            $channel->access_token,
            headers: ['X-RestLi-Method' => 'DELETE'],
            safeToRepeat: true,
        );
    }

    /**
     * A self-serve app gets no refresh token, so its access cannot be
     * renewed without the member: this asks LinkedIn about the token,
     * records what it reports, and marks the channel when the token is no
     * longer usable. Where LinkedIn did issue a refresh token (approved
     * partners only), that is exchanged for a new access token instead.
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

        if (filled($channel->refresh_token)) {
            return $this->renew($channel);
        }

        $report = $this->client->oauth('introspectToken', [
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'token' => (string) $channel->access_token,
        ]);

        if (($report['active'] ?? false) !== true) {
            return $channel->markNeedsReconnect();
        }

        $expiresAt = is_numeric($report['expires_at'] ?? null) ? (int) $report['expires_at'] : 0;
        // The scope is documented as optional in this answer. Without it
        // the scopes recorded at the sign-in stand.
        $scopes = $this->scopeList($report['scope'] ?? null);

        $channel->token_expires_at = $expiresAt > 0 ? CarbonImmutable::createFromTimestampUTC($expiresAt) : $channel->token_expires_at;
        $channel->scopes = $scopes ?? $channel->scopes;

        if ($channel->tokenExpired() || $this->lacksPublishScope($scopes)) {
            return $channel->markNeedsReconnect();
        }

        $channel->save();

        return $channel;
    }

    private function renew(Channel $channel): Channel
    {
        try {
            $grant = $this->grantFrom($this->client->oauth('accessToken', [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $channel->refresh_token,
                'client_id' => $this->setting('client_id'),
                'client_secret' => $this->setting('client_secret'),
            ]));
        } catch (ChannelException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            // The refresh token is expired, revoked or not valid.
            return $channel->markNeedsReconnect();
        }

        // A renewed token that may no longer post is no use: the same rule
        // as for a token that is only inspected.
        if ($this->lacksPublishScope($grant['scopes'])) {
            return $channel->markNeedsReconnect();
        }

        $channel->forceFill([
            'access_token' => $grant['access_token'],
            // LinkedIn may or may not send the refresh token again.
            'refresh_token' => $grant['refresh_token'] ?? $channel->refresh_token,
            'token_expires_at' => $grant['expires_at'],
            'scopes' => $grant['scopes'] ?? $channel->scopes,
            'status' => ChannelStatus::Active,
        ])->save();

        return $channel;
    }

    /**
     * What the token endpoint returned.
     *
     * @param  array<string, mixed>  $response
     * @return array{access_token: string, refresh_token: string|null, expires_at: CarbonImmutable|null, scopes: list<string>|null} scopes is null when the answer names none
     */
    private function grantFrom(#[SensitiveParameter] array $response): array
    {
        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw ChannelException::permanent(self::PLATFORM, 'LinkedIn answered the sign-in without giving access. Connect again from the Channels page.');
        }

        $refreshToken = $response['refresh_token'] ?? null;
        $expiresIn = is_numeric($response['expires_in'] ?? null) ? (int) $response['expires_in'] : 0;

        return [
            'access_token' => $token,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_at' => $expiresIn > 0 ? CarbonImmutable::now('UTC')->addSeconds($expiresIn) : null,
            'scopes' => $this->scopeList($response['scope'] ?? null),
        ];
    }

    /**
     * Who the token belongs to, from the OpenID Connect userinfo endpoint.
     * What comes back is shown in the UI and built into a URN, so it is
     * checked and cut to size here.
     *
     * @return array{id: string, name: string}
     */
    private function memberOf(#[SensitiveParameter] string $token): array
    {
        $profile = $this->client->api('GET', 'v2/userinfo', $token, safeToRepeat: true)['body'];

        $id = $profile['sub'] ?? null;

        if (! is_string($id) || preg_match('/^'.self::ID.'\z/', $id) !== 1) {
            throw ChannelException::permanent(self::PLATFORM, 'LinkedIn did not say who signed in. Connect again from the Channels page.');
        }

        $name = $this->text($profile['name'] ?? null)
            ?? $this->text(implode(' ', array_filter([$this->text($profile['given_name'] ?? null), $this->text($profile['family_name'] ?? null)])))
            ?? 'LinkedIn member';

        return ['id' => $id, 'name' => mb_substr($name, 0, 255)];
    }

    /**
     * The card for the variant's link. The readiness check asks for a title
     * before a post can be approved; this is the last line behind it, and
     * it fails before anything is sent.
     *
     * @return array{source: string, title: string, description?: string}
     */
    private function article(PostVariant $variant): array
    {
        $title = $this->text($variant->link_title);

        if ($title === null) {
            throw PublishFailed::permanent(
                self::PLATFORM,
                'The link has no title. LinkedIn shows a link only with the title given for it. Return the post to draft, open it and type a title under the link.',
            );
        }

        $article = [
            'source' => LinkWithUtm::apply((string) $variant->link, $variant->utm ?? []),
            'title' => $title,
        ];

        $description = $this->text($variant->link_description);

        if ($description !== null) {
            $article['description'] = $description;
        }

        return $article;
    }

    private function permalink(string $urn): string
    {
        return rtrim($this->setting('web_url'), '/').'/feed/update/'.$urn.'/';
    }

    /**
     * The scopes an answer names, or null when it names none. The token
     * endpoint documents them as separated by spaces, the introspection
     * endpoint as separated by commas.
     *
     * @return list<string>|null
     */
    private function scopeList(mixed $scope): ?array
    {
        if (! is_string($scope) || trim($scope) === '') {
            return null;
        }

        $parts = preg_split('/[\s,]+/', $scope, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? null : $parts;
    }

    /**
     * True when an answer names its scopes and the one that allows posting
     * (`publish_scope` in the settings) is not among them. An answer that
     * names no scopes proves nothing either way and is let through: a token
     * that cannot post is then found out by the first publish, which answers
     * 403 and marks the channel.
     *
     * @param  list<string>|null  $granted
     */
    private function lacksPublishScope(?array $granted): bool
    {
        $needed = $this->setting('publish_scope');

        return $granted !== null && $needed !== '' && ! in_array($needed, $granted, true);
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = $this->settings['scopes'] ?? [];

        return array_values(array_filter(is_array($scopes) ? $scopes : [], fn (mixed $scope): bool => is_string($scope) && $scope !== ''));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function setting(string $key): string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveSetting(string $key): ?int
    {
        $value = $this->settings[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Nothing is sent unless the credentials are set and both addresses are
     * HTTPS, so a token never travels in the clear because of a typing
     * mistake in the environment.
     *
     * @param  class-string<ChannelException>  $failure
     */
    private function guardConfigured(string $failure = ChannelException::class): void
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            throw $failure::permanent(
                self::PLATFORM,
                'LinkedIn is not configured on this instance. Set '.implode(' and ', $missing).' first.',
            );
        }

        foreach (['api_url' => 'LINKEDIN_API_URL', 'web_url' => 'LINKEDIN_WEB_URL'] as $key => $variable) {
            if (! str_starts_with(strtolower($this->setting($key)), 'https://')) {
                throw $failure::permanent(self::PLATFORM, "LinkedIn is not configured correctly on this instance: {$variable} must be an https:// address.");
            }
        }

        if (preg_match('/^\d{6}\z/', $this->setting('api_version')) !== 1) {
            throw $failure::permanent(self::PLATFORM, 'LinkedIn is not configured correctly on this instance: LINKEDIN_API_VERSION must be a version in the form YYYYMM.');
        }
    }
}
