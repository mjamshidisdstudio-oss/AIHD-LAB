<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media validation policy (Phase L4)
    |--------------------------------------------------------------------------
    |
    | Per-media-type format allow-list and size ceiling, enforced uniformly by
    | App\Actions\Storage\StoreMedia -- the one shared write path both our own
    | input uploads and an external service's result uploads go through, so
    | there is exactly one place this policy can ever be checked or bypassed.
    |
    | mimes are matched against the file's REAL, content-sniffed mime type
    | (Symfony's fileinfo-backed UploadedFile::getMimeType()), never a
    | client-supplied Content-Type -- a file claiming to be one thing while
    | actually being another is rejected regardless of its extension or
    | declared type.
    |
    | Sizes differ by an order of magnitude between types on purpose: a flat
    | limit sized for images would either block every real video or, sized for
    | video, let a tiny image upload be absurdly large -- neither is right.
    |
    */

    'types' => [

        'image' => [
            'mimes' => array_values(array_filter(explode(',', env(
                'MEDIA_IMAGE_MIMES',
                'image/png,image/jpeg,image/webp,image/avif'
            )))),
            'max_bytes' => (int) env('MEDIA_IMAGE_MAX_BYTES', 25 * 1024 * 1024),
        ],

        'video' => [
            'mimes' => array_values(array_filter(explode(',', env(
                'MEDIA_VIDEO_MIMES',
                'video/mp4,video/webm'
            )))),
            'max_bytes' => (int) env('MEDIA_VIDEO_MAX_BYTES', 500 * 1024 * 1024),
        ],

        'text' => [
            'mimes' => array_values(array_filter(explode(',', env(
                'MEDIA_TEXT_MIMES',
                'application/json,text/plain'
            )))),
            'max_bytes' => (int) env('MEDIA_TEXT_MAX_BYTES', 1 * 1024 * 1024),
        ],

    ],

];
