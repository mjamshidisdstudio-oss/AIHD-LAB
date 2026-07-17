<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin inspection of a result's file. Unlike the marketplace download
 * endpoint, this never writes an Interaction — an operator looking at a
 * result in the order log is not a customer download and must not inflate
 * download_count.
 */
class ResultDownloadController extends Controller
{
    public function show(Result $result): StreamedResponse|JsonResponse
    {
        $result->loadMissing('file');

        if ($result->file === null || ! Storage::disk($result->file->disk)->exists($result->file->path)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return Storage::disk($result->file->disk)->response(
            $result->file->path,
            null,
            ['Content-Type' => $result->file->mime],
        );
    }
}
