<?php

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Auth;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

beforeEach(function () {
    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
    $this->bytes = jpegBytes();
    $this->media = storedImage($this->workspace, $this->bytes);
});

it('serves an image to every member of its workspace', function (Role $role) {
    $response = $this->actingAs(userWithRole($this->workspace, $role))->get(route('media.show', $this->media->id));

    $response->assertOk();

    expect($response->streamedContent())->toBe($this->bytes);
})->with([Role::Owner, Role::Editor, Role::Viewer]);

it('serves it as an image and as nothing else', function () {
    $response = $this->actingAs(userWithRole($this->workspace, Role::Viewer))
        ->get(route('media.show', $this->media->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Length', (string) strlen($this->bytes))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', "inline; filename=\"image-{$this->media->id}.jpg\"")
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'")
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('ETag', '"'.substr($this->media->content_hash, 0, 32).'"');

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('public')->not->toContain('max-age');
});

describe('a copy in the browser', function () {
    it('may be kept, and is asked about every time before it is shown', function (string $route) {
        $member = userWithRole($this->workspace, Role::Viewer);
        $first = $this->actingAs($member)->get(route($route, $this->media->id))->assertOk();

        // "no-cache" is not "do not store": the browser keeps the file and
        // must have it confirmed. The confirmation is the 304 below.
        expect($first->headers->get('Cache-Control'))->toBe('no-cache, private');

        $this->withHeaders(['If-None-Match' => $first->headers->get('ETag')])
            ->get(route($route, $this->media->id))
            ->assertStatus(304)
            ->assertHeader('Cache-Control', 'no-cache, private');
    })->with(['media.show', 'media.thumb']);

    it('stops opening when its viewer signs out', function () {
        $member = userWithRole($this->workspace, Role::Viewer);
        $etag = $this->actingAs($member)->get(route('media.show', $this->media->id))->headers->get('ETag');

        $this->post(route('logout'));

        $this->withHeaders(['If-None-Match' => $etag])->get(route('media.show', $this->media->id))->assertNotFound();
    });

    it('stops opening when its viewer is taken out of the workspace', function () {
        $member = userWithRole($this->workspace, Role::Viewer);
        $etag = $this->actingAs($member)->get(route('media.show', $this->media->id))->headers->get('ETag');

        $member->memberships()->delete();

        $this->withHeaders(['If-None-Match' => $etag])->get(route('media.show', $this->media->id))->assertNotFound();
        $this->withHeaders(['If-None-Match' => $etag.'-t'])->get(route('media.thumb', $this->media->id))->assertNotFound();
    });
});

describe('an image is not a page', function () {
    it('knows who is signed in from the session', function () {
        $member = userWithRole($this->workspace, Role::Viewer);

        $this->withSession([Auth::guard('web')->getName() => $member->id])
            ->get(route('media.show', $this->media->id))
            ->assertOk();

        expect(Auth::id())->toBe($member->id);
    });

    it('is not noted as the page to go back to', function (string $route) {
        $member = userWithRole($this->workspace, Role::Editor);

        $this->actingAs($member)->get(route('queue.index'))->assertOk();

        expect(session()->previousUrl())->toBe(route('queue.index'));

        $this->get(route($route, $this->media->id))->assertOk();
        $this->get(route($route, 999999))->assertNotFound();

        expect(session()->previousUrl())->toBe(route('queue.index'));

        // A form that fails goes back to the page, not to a picture.
        $this->post(route('posts.store'), [])->assertRedirect(route('queue.index'));
    })->with(['media.show', 'media.thumb']);

    it('does not write the session and sends no cookie', function () {
        $member = userWithRole($this->workspace, Role::Editor);
        $handler = Mockery::mock(ArraySessionHandler::class, [120])->makePartial();

        session()->setHandler($handler);

        $image = $this->actingAs($member)->get(route('media.show', $this->media->id))->assertOk();
        $thumb = $this->get(route('media.thumb', $this->media->id))->assertOk();
        $unchanged = $this->withHeaders(['If-None-Match' => $image->headers->get('ETag')])->get(route('media.show', $this->media->id))->assertStatus(304);

        $handler->shouldNotHaveReceived('write');

        expect($image->headers->getCookies())->toBe([])
            ->and($thumb->headers->getCookies())->toBe([])
            ->and($unchanged->headers->getCookies())->toBe([]);

        // A page does both, so the check above can fail.
        $page = $this->get(route('queue.index'))->assertOk();

        $handler->shouldHaveReceived('write');

        expect($page->headers->getCookies())->not->toBe([]);
    });

    it('keeps what a page left in the session for the next page', function () {
        $member = userWithRole($this->workspace, Role::Editor);

        $this->actingAs($member)->withSession(['workspace_id' => $this->workspace->id]);
        session()->flash('status', 'The draft is saved.');
        session()->save();

        $this->get(route('media.thumb', $this->media->id))->assertOk();
        $this->get(route('media.thumb', $this->media->id))->assertOk();

        expect(session()->get('status'))->toBe('The draft is saved.');
    });
});

it('takes the content type from its own record, never from the file', function () {
    // Whatever ended up in the file, the response says what the record says.
    $this->disk->put($this->media->path, svgBytes());

    $this->actingAs(userWithRole($this->workspace, Role::Viewer))
        ->get(route('media.show', $this->media->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('serves the small copy for lists', function () {
    $response = $this->actingAs(userWithRole($this->workspace, Role::Viewer))
        ->get(route('media.thumb', $this->media->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', "inline; filename=\"thumb-{$this->media->id}.jpg\"")
        ->assertHeader('ETag', '"'.substr($this->media->content_hash, 0, 32).'-t"');

    expect($response->streamedContent())->toBe(storedBytes($this->media, thumbnail: true))
        ->and($response->streamedContent())->not->toBe($this->bytes);
});

it('serves the image itself where there is no small copy', function () {
    $media = storedImage($this->workspace, pngBytes(), ['thumb_path' => null]);

    $response = $this->actingAs(userWithRole($this->workspace, Role::Viewer))
        ->get(route('media.thumb', $media->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->streamedContent())->toBe(storedBytes($media));
});

it('answers a member of another workspace as if the image did not exist', function (string $route) {
    $stranger = userWithRole(Workspace::factory()->create(), Role::Owner);

    $missing = $this->actingAs($stranger)->get(route($route, 999999));
    $foreign = $this->actingAs($stranger)->get(route($route, $this->media->id));

    $missing->assertNotFound();
    $foreign->assertNotFound()->assertHeaderMissing('ETag');

    expect($foreign->getContent())->toBe($missing->getContent());
})->with(['media.show', 'media.thumb']);

it('answers a visitor who is not signed in with 404, not with the way to the sign-in page', function (string $route) {
    $this->get(route($route, $this->media->id))->assertNotFound()->assertHeaderMissing('Location');
    $this->get(route($route, 999999))->assertNotFound();
})->with(['media.show', 'media.thumb']);

it('answers a user without a workspace and a user who has not confirmed their address with 404', function () {
    $this->actingAs(User::factory()->create())->get(route('media.show', $this->media->id))->assertNotFound();

    $unverified = userWithRole($this->workspace, Role::Owner);
    $unverified->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($unverified)->get(route('media.show', $this->media->id))->assertNotFound();
});

it('shows a member with two workspaces only the images of the one they are in', function () {
    $other = Workspace::factory()->create();
    $member = userWithRole($this->workspace, Role::Owner);
    $member->memberships()->create(['workspace_id' => $other->id, 'role' => Role::Owner]);

    $this->actingAs($member)->withSession(['workspace_id' => $other->id])->get(route('media.show', $this->media->id))->assertNotFound();
    $this->actingAs($member)->withSession(['workspace_id' => $this->workspace->id])->get(route('media.show', $this->media->id))->assertOk();
});

it('answers 304 when the browser already has the image', function () {
    $member = userWithRole($this->workspace, Role::Viewer);
    $etag = $this->actingAs($member)->get(route('media.show', $this->media->id))->headers->get('ETag');

    $response = $this->withHeaders(['If-None-Match' => $etag])->get(route('media.show', $this->media->id));

    $response->assertStatus(304)->assertHeader('ETag', $etag)->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $this->withHeaders(['If-None-Match' => '"something-else"'])->get(route('media.show', $this->media->id))->assertOk();
    // The small copy has a tag of its own.
    $this->withHeaders(['If-None-Match' => $etag])->get(route('media.thumb', $this->media->id))->assertOk();
});

it('checks who asks before it says that nothing changed', function () {
    $etag = '"'.substr($this->media->content_hash, 0, 32).'"';

    $this->withHeaders(['If-None-Match' => $etag])->get(route('media.show', $this->media->id))->assertNotFound();

    $this->actingAs(userWithRole(Workspace::factory()->create(), Role::Owner))
        ->withHeaders(['If-None-Match' => $etag])
        ->get(route('media.show', $this->media->id))
        ->assertNotFound();
});

it('answers 404 when the file is gone from the disk', function () {
    $this->disk->delete($this->media->path);

    $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('media.show', $this->media->id))->assertNotFound();
});

it('has no address that reaches a file without the application', function () {
    expect(config('filesystems.disks.media.url'))->toBeNull()
        ->and(config('filesystems.disks.media.serve'))->toBeFalse()
        ->and(config('filesystems.disks.media.visibility'))->toBe('private')
        ->and(config('filesystems.links'))->not->toContain(config('filesystems.disks.media.root'));

    // The framework serves its "local" disk at /storage for signed
    // addresses. The media disk is another folder and is not served.
    expect(config('filesystems.disks.media.root'))->not->toStartWith((string) config('filesystems.disks.local.root'));

    $this->get('/media/'.$this->media->path)->assertNotFound();
    $this->get('/media/abc')->assertNotFound();
    $this->get('/media/../../.env')->assertNotFound();
});

it('reads through the disk, so object storage works the same', function () {
    remoteMediaDisk();

    $media = storedImage($this->workspace, pngBytes());
    $member = userWithRole($this->workspace, Role::Viewer);

    expect($media->disk)->toBe('objects')
        ->and($this->actingAs($member)->get(route('media.show', $media->id))->assertOk()->streamedContent())->toBe(storedBytes($media))
        ->and($this->get(route('media.thumb', $media->id))->assertOk()->streamedContent())->toBe(storedBytes($media, thumbnail: true));
});
