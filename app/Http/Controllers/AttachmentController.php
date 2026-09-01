<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves one attachment, fetching it from the provider on first request and
 * caching the bytes on disk after that.
 *
 * Security posture: the stored MIME type came from the sender, so it is never
 * trusted for anything but a small image safelist. Images from that list render
 * inline (they are what cid: bodies embed); everything else downloads as an
 * opaque file, with nosniff so the browser cannot decide otherwise.
 */
class AttachmentController
{
    private const INLINE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function show(Message $message, Attachment $attachment): StreamedResponse
    {
        // Scoped route binding already guarantees the attachment belongs to the
        // message; a missing remote id means the provider never gave us a handle.
        if (! $this->cached($attachment)) {
            abort_if($attachment->remote_id === null, 404);

            $stream = $message->mailAccount->driver()->downloadAttachment(
                $message->mailAccount,
                $message->provider_message_id,
                $attachment->remote_id,
            );

            $path = 'attachments/'.$message->id.'/'.$attachment->id;
            Storage::disk('local')->put($path, $stream->getContents());
            $attachment->update(['disk_path' => $path, 'downloaded_at' => now()]);
        }

        $inline = in_array(strtolower((string) $attachment->mime_type), self::INLINE_IMAGE_MIMES, true);

        return Storage::disk('local')->response(
            $attachment->disk_path,
            $attachment->filename,
            [
                'Content-Type' => $inline ? $attachment->mime_type : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }

    /** A disk_path whose file has since vanished (volume loss) is not cached. */
    private function cached(Attachment $attachment): bool
    {
        return $attachment->disk_path !== null && Storage::disk('local')->exists($attachment->disk_path);
    }
}
