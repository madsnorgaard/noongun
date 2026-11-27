<?php

namespace App\Http\Controllers\Media;

use App\Domain\Content\Actions\StoreMedia;
use App\Domain\Content\Exceptions\MediaBusy;
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
use Illuminate\Support\Facades\Log;
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
    public function store(Request $request, StoreMedia $store): JsonResponse
    {
        Gate::authorize('create', Media::class);

        $user = $request->user();

        abort_unless($user instanceof User, 401);

        try {
            $media = $store->handle($user, $this->uploadedPath($request));
        } catch (MediaRefused $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        } catch (MediaBusy $e) {
            // Not a judgement on the file: the same request works a moment
            // later. The sentence is where a refusal has its sentence, so
            // that a page can show either the same way.
            return response()->json(
                ['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]],
                429,
                ['Retry-After' => (string) $e->retryAfterSeconds],
            );
        }

        return response()->json(MediaPresenter::present($media), 201);
    }

    /**
     * Where PHP put the one file of the request. The size is checked again
     * on the file itself, and what it is, is decided from its content.
     *
     * When PHP did not take the file, the reason decides what the person is
     * told: a file that is too large is theirs to change, a full disk is
     * not, and neither is helped by "choose an image".
     *
     * @throws MediaRefused
     */
    private function uploadedPath(Request $request): string
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw MediaRefused::nothingSent(MediaConfig::maxBytes());
        }

        if ($file->isValid()) {
            return (string) $file->getRealPath();
        }

        throw match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => MediaRefused::tooManyBytes(MediaConfig::maxBytes()),
            UPLOAD_ERR_PARTIAL => MediaRefused::interrupted(),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => $this->cannotStore($file->getError()),
            default => MediaRefused::nothingSent(MediaConfig::maxBytes()),
        };
    }

    private function cannotStore(int $error): MediaRefused
    {
        Log::error('PHP could not take an uploaded image. Check the temporary directory of the app container and the room on its disk.', [
            'upload_error' => $error,
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
        ]);

        return MediaRefused::serverCannotStore();
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
            // The browser keeps its copy and asks every time whether it may
            // still show it. The answer is a 304 without the file, and it
            // is only given after the checks above, so an image stops
            // opening the moment its viewer signs out or leaves the
            // workspace.
            'Cache-Control' => 'private, no-cache',
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
