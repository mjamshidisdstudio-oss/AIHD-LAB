<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A local stand-in for a real external AI provider, so the submit -> dispatch ->
 * poll -> result loop runs end-to-end without a third party. Only mounted
 * outside production (see bootstrap/app.php). The seeded service's
 * post_url/get_url point here.
 *
 *   POST /dev/services/generate  -> accepts a job, returns an external_order_id
 *   GET  /dev/services/result    -> returns the completed results
 */
class DevServiceController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $externalOrderId = (string) Str::uuid();

        // Remember the requested output shape so the result endpoint can echo a
        // matching result per declared output.
        $outputs = $request->input('expected_outputs', [
            ['result_number' => 1, 'type' => 'text'],
        ]);
        Cache::put("dev-service:{$externalOrderId}", $outputs, now()->addHour());

        return response()->json([
            'external_order_id' => $externalOrderId,
            'status' => 'accepted',
        ]);
    }

    public function result(Request $request): JsonResponse
    {
        $externalOrderId = (string) $request->query('external_order_id');
        $outputs = Cache::get("dev-service:{$externalOrderId}");

        if ($outputs === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        $results = collect($outputs)->map(function (array $output) {
            $type = $output['type'];
            $item = [
                'result_number' => $output['result_number'],
                'type' => $type,
            ];

            if ($type === 'text') {
                $item['text'] = "Mock text result #{$output['result_number']}";
            } else {
                $item['mime'] = $type === 'video' ? 'video/mp4' : 'image/png';
                // Genuinely content-sniffable bytes, not placeholder text --
                // Phase L4's media validation checks the REAL sniffed mime,
                // not this claimed one, so it must actually match.
                $item['content_base64'] = base64_encode(
                    $type === 'video' ? $this->fakeMp4Bytes() : $this->fakePngBytes()
                );
            }

            return $item;
        })->all();

        return response()->json([
            'status' => 'completed',
            'latency_ms' => 1234,
            'results' => $results,
        ]);
    }

    /**
     * A tiny real PNG, GD-generated -- sniffs as image/png.
     */
    private function fakePngBytes(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return ob_get_clean();
    }

    /**
     * A minimal valid MP4 "ftyp" box -- not a playable video, but a genuine
     * ISO-BMFF header that sniffs as video/mp4.
     */
    private function fakeMp4Bytes(): string
    {
        return pack('N', 24).'ftyp'.'isom'.pack('N', 512).'isom'.'mp41';
    }
}
