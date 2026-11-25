<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

itIsolatesWorkspaces(fn () => Channel::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
});

it('encrypts tokens at rest', function () {
    $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'access_token' => 'plain-access-token',
        'refresh_token' => 'plain-refresh-token',
    ]));

    $row = DB::table('channels')->where('id', $channel->id)->first();

    expect($row->access_token)->not->toContain('plain-access-token')
        ->and($row->refresh_token)->not->toContain('plain-refresh-token')
        ->and(Crypt::decryptString($row->access_token))->toBe('plain-access-token')
        ->and(Crypt::decryptString($row->refresh_token))->toBe('plain-refresh-token');

    $reloaded = inWorkspace($this->workspace, fn () => Channel::findOrFail($channel->id));

    expect($reloaded->access_token)->toBe('plain-access-token')
        ->and($reloaded->refresh_token)->toBe('plain-refresh-token');
});

it('hides tokens from arrays and JSON', function () {
    $channel = inWorkspace($this->workspace, fn () => Channel::factory()->withRefreshToken()->create([
        'access_token' => 'plain-access-token',
    ]));

    expect($channel->toArray())->not->toHaveKeys(['access_token', 'refresh_token'])
        ->and($channel->toJson())->not->toContain('plain-access-token')
        ->and($channel->toJson())->not->toContain('test-refresh-')
        ->and(json_encode(['channel' => $channel]))->not->toContain('token":');
});

it('stores scopes as a list, status as an enum and the expiry in UTC', function () {
    $expiry = $this->workspace->toUtc('2027-01-15 12:00:00');

    $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'scopes' => ['pages_show_list', 'pages_manage_posts'],
        'token_expires_at' => $expiry,
    ]));

    $channel = inWorkspace($this->workspace, fn () => Channel::findOrFail($channel->id));

    expect($channel->scopes)->toBe(['pages_show_list', 'pages_manage_posts'])
        ->and($channel->hasScope('pages_manage_posts'))->toBeTrue()
        ->and($channel->hasScope('pages_manage_engagement'))->toBeFalse()
        ->and($channel->status)->toBe(ChannelStatus::Active)
        ->and(DB::table('channels')->where('id', $channel->id)->value('token_expires_at'))->toBe('2027-01-15 11:00:00');
});

it('is active with no scopes by default', function () {
    $channel = inWorkspace($this->workspace, fn () => Channel::create([
        'platform' => 'facebook',
        'external_id' => '1',
        'display_name' => 'A Page',
        'access_token' => 'token',
    ]));

    expect($channel->status)->toBe(ChannelStatus::Active)
        ->and($channel->scopes)->toBe([])
        ->and($channel->canPublish())->toBeTrue();
});

it('can publish only when active with a token that has not expired', function () {
    inWorkspace($this->workspace, function () {
        expect(Channel::factory()->create()->canPublish())->toBeTrue()
            ->and(Channel::factory()->expiresAt(now()->addDays(30))->create()->canPublish())->toBeTrue()
            ->and(Channel::factory()->expired()->create()->canPublish())->toBeFalse()
            ->and(Channel::factory()->needsReconnect()->create()->canPublish())->toBeFalse()
            ->and(Channel::factory()->disconnected()->create()->canPublish())->toBeFalse();
    });
});

it('knows when its token is about to expire', function () {
    inWorkspace($this->workspace, function () {
        expect(Channel::factory()->expiresAt(now()->addDays(5))->create()->tokenExpiresWithin(7))->toBeTrue()
            ->and(Channel::factory()->expiresAt(now()->addDays(30))->create()->tokenExpiresWithin(7))->toBeFalse()
            ->and(Channel::factory()->create()->tokenExpiresWithin(7))->toBeFalse();
    });
});

it('can be marked as needing a reconnect', function () {
    $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create()->markNeedsReconnect());

    expect($channel->status)->toBe(ChannelStatus::NeedsReconnect)
        ->and($channel->canPublish())->toBeFalse()
        ->and($channel->access_token)->not->toBeNull();
});

it('erases its tokens when disconnected and keeps the row', function () {
    $channel = inWorkspace($this->workspace, fn () => Channel::factory()->withRefreshToken()->expiresAt(now()->addDay())->create()->disconnect());

    $row = DB::table('channels')->where('id', $channel->id)->first();

    expect($row->access_token)->toBeNull()
        ->and($row->refresh_token)->toBeNull()
        ->and($row->token_expires_at)->toBeNull()
        ->and($row->status)->toBe('disconnected');
});

it('allows one row per account in a workspace', function () {
    inWorkspace($this->workspace, function () {
        Channel::factory()->create(['external_id' => '42']);

        expect(fn () => Channel::factory()->create(['external_id' => '42']))->toThrow(QueryException::class);
    });
});

it('lets two workspaces connect the same account', function () {
    $other = Workspace::factory()->create();

    inWorkspace($this->workspace, fn () => Channel::factory()->create(['external_id' => '42']));
    $second = inWorkspace($other, fn () => Channel::factory()->create(['external_id' => '42']));

    expect($second->exists)->toBeTrue();
});

it('cannot be deleted while it has variants', function () {
    inWorkspace($this->workspace, function () {
        $variant = PostVariant::factory()->create();

        expect(fn () => $variant->channel->delete())->toThrow(QueryException::class);
    });
});
