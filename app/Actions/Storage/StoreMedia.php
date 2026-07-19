<?php

namespace App\Actions\Storage;

use App\Enums\FileKind;
use App\Models\File;
use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The single place any media -- our own input uploads, external service
 * results -- is ever written to disk. StorageController::store() (the
 * POST /api/storage endpoint an external service calls) and SubmitOrder's
 * own input handling both call this; nothing else in the request path
 * touches a disk directly. The disk itself is the "media" disk
 * (config/filesystems.php), whose driver is env('MEDIA_DRIVER') --
 * local for development, s3/r2 in production, a one-line env change with
 * no code here ever needing to know which.
 */
class StoreMedia
{
    private const DISK = 'media';

    public function handle(Order $order, UploadedFile $file, FileKind $kind): File
    {
        $prefix = match ($kind) {
            FileKind::Input => 'inputs',
            FileKind::Result => 'results',
        };

        $path = Storage::disk(self::DISK)->putFile("{$prefix}/{$order->id}", $file);

        return File::create([
            'kind' => $kind,
            'disk' => self::DISK,
            'order_id' => $order->id,
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'path' => $path,
            'size' => $file->getSize() ?? 0,
        ]);
    }
}
