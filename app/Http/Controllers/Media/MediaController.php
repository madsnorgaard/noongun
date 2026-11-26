<?php

namespace App\Http\Controllers\Media;

use App\Domain\Content\Actions\StoreMedia;
use App\Domain\Content\Exceptions\MediaFileMissing;
use App\Domain\Content\Exceptions\MediaRefused;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MediaPresenter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes one image at a time and serves stored images to the members of the
 * workspace they belong to.
 *
 * Images are private. Every request for one is answered here, after the
 * viewer and the workspace are checked, and to anybody else the image does
 * not exist: a visitor who is not signed in and a member of another
 * workspace both get 404, as for an id that was never given out.
 */
class MediaController extends Controller
{
    /** How long a browser may show its own copy without asking again. */
    private const CACHE_SECONDS = 86400;

    public function store(Request $request, StoreMedia $store): JsonResponse
    {
        Gate::authorize('create', Media::class);

        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $file = $request->file('file');

        // One file, sent whole. The size is checked again on the file
        // itself, and what it is, is decided from its content.
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => [(string) __('Choose one image to upload. The limit is :limit MB.', ['limit' => round(MediaConfig::maxBytes() / 1024 / 1024, 1)])],
            ]);
        }

        try {
            $media = $store->handle($user, (string) $file->getRealPath());
        } catch (MediaRefused $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return response()->json(MediaPresenter::present($media), 201);
    }

    public function show(Request $request, int $media, CurrentWorkspace $workspace): Response
    {
        return $this->serve($request, $media, $workspace, thumbnail: false);
    }

    public function thumb(Request $request, int $media, CurrentWorkspace $workspace): Response
    {
        return $this->serve($request, $media, $workspace, thumbnail: true);
    }

    private function serve(Request $request, int $id, CurrentWorkspace $workspace, bool $thumbnail): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasVerifiedEmail() && $workspace->get() !== null, 404);

        // Scoped to the current workspace: an image of another one is not found.
        $media = Media::query()->find($id);

        abort_unless($media !== null && $user->can('view', $media), 404);

        $thumbnail = $thumbnail && $media->thumb_path !== null;
        $etag = '"'.substr($media->content_hash, 0, 32).($thumbnail ? '-t' : '').'"';

        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age='.self::CACHE_SECONDS,
            // The type is the one this application gave the file when it
            // wrote it. Nothing in the response can run or be framed.
            'Content-Type' => $thumbnail ? 'image/jpeg' : $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.($thumbnail ? "thumb-{$media->id}.jpg" : $media->fileName()).'"',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if (in_array($etag, $request->getETags(), true) || in_array('*', $request->getETags(), true)) {
            return response('', 304, array_diff_key($headers, ['Content-Type' => true, 'Content-Disposition' => true]));
        }

        try {
            $stream = $media->readStream($thumbnail);
        } catch (MediaFileMissing) {
            abort(404);
        }

        if (! $thumbnail) {
            $headers['Content-Length'] = (string) $media->byte_size;
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }
}
