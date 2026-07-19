<?php

namespace App\Actions\Storage;

use App\Enums\FileKind;
use App\Enums\MediaType;
use App\Exceptions\Storage\MediaValidationException;
use App\Models\File;
use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The single place any media -- our own input uploads, external service
 * results -- is ever written to disk. StorageController::store() (the
 * POST /api/storage endpoint an external service calls), SubmitOrder's own
 * input handling, and IngestResult's inline-bytes result delivery all call
 * this; nothing else in the request path touches a disk directly. The disk
 * itself is the "media" disk (config/filesystems.php), whose driver is
 * env('MEDIA_DRIVER') -- local for development, s3/r2 in production, a
 * one-line env change with no code here ever needing to know which.
 *
 * Every write is checked against config/media.php's per-type policy (Phase
 * L4) BEFORE anything touches disk: $expectedType is the caller's claim about
 * what this upload is supposed to be (a service_input's or service_output's
 * declared type) -- $file->getMimeType() is never trusted against that claim,
 * it is content-sniffed (Symfony's fileinfo-backed real detection, not the
 * client-supplied Content-Type) and the two are compared. A mismatch, or a
 * file over its type's size ceiling, is rejected before any bytes are written
 * or any File row is created.
 */
class StoreMedia
{
    private const DISK = 'media';

    public function handle(Order $order, UploadedFile $file, FileKind $kind, MediaType $expectedType): File
    {
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $policy = config("media.types.{$expectedType->value}");

        if (! in_array($mime, $policy['mimes'], true)) {
            throw MediaValidationException::mimeNotAllowed($expectedType, $mime);
        }

        $size = $file->getSize() ?? 0;
        if ($size > $policy['max_bytes']) {
            throw MediaValidationException::tooLarge($expectedType, $size, $policy['max_bytes']);
        }

        $prefix = match ($kind) {
            FileKind::Input => 'inputs',
            FileKind::Result => 'results',
        };

        $path = Storage::disk(self::DISK)->putFile("{$prefix}/{$order->id}", $file);

        return File::create([
            'kind' => $kind,
            'disk' => self::DISK,
            'order_id' => $order->id,
            'mime' => $mime,
            'path' => $path,
            'size' => $size,
        ]);
    }
}
