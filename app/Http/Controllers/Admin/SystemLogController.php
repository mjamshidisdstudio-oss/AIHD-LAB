<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SystemLogController extends Controller
{
    /**
     * Return tail of storage/logs/laravel.log (sanitized).
     *
     * This endpoint is intentionally conservative: it clamps `limit`, reads a
     * bounded tail (no full file dumps), and redacts common credential
     * patterns before returning lines to the admin UI.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 200);
        $limit = max(10, min($limit, 500));

        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : '';

        $path = storage_path('logs/laravel.log');
        if (!is_file($path)) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'file' => 'laravel.log',
                    'limit' => $limit,
                    'returned' => 0,
                ],
            ]);
        }

        // If we filter by `q`, we fetch a slightly larger tail first so the
        // final `limit` isn't frequently under-filled.
        $rawLines = $this->tailLines($path, $q !== '' ? $limit * 3 : $limit);
        if ($q !== '') {
            $rawLines = array_values(array_filter($rawLines, fn (string $line) => stripos($line, $q) !== false));
        }

        $rawLines = array_slice($rawLines, -$limit);

        $lines = array_map(fn (string $line) => $this->sanitizeLine($line), $rawLines);

        return response()->json([
            'data' => $lines,
            'meta' => [
                'file' => 'laravel.log',
                'limit' => $limit,
                'returned' => count($lines),
                'query' => $q !== '' ? $q : null,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function tailLines(string $path, int $maxLines): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $buffer = '';
        $lines = [];
        $chunkSize = 4096;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        while ($pos > 0 && count($lines) < $maxLines) {
            $readSize = (int) min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer = $chunk . $buffer;

            // Split with Windows/unix line endings.
            $parts = preg_split("/\r\n|\n|\r/", $buffer);
            $lines = is_array($parts) ? $parts : [$buffer];
        }

        fclose($handle);

        return array_slice($lines, -$maxLines);
    }

    private function sanitizeLine(string $line): string
    {
        // Redact bearer tokens in Authorization headers.
        $line = preg_replace(
            '/(Authorization:\s*Bearer\s+)[^\s,"]+/i',
            '$1[REDACTED]',
            $line,
        ) ?? $line;

        // Redact any Bearer <token> that appears in logs.
        $line = preg_replace(
            '/\bBearer\s+[A-Za-z0-9\-_\.]{10,}\b/i',
            'Bearer [REDACTED]',
            $line,
        ) ?? $line;

        // Redact common key/value secrets.
        $line = preg_replace(
            '/\b(api[_-]?key|secret|token|password|service_secret|webhook_signing_key)\b\s*[:=]\s*[^\s,"]+/i',
            '$1=[REDACTED]',
            $line,
        ) ?? $line;

        return $line;
    }
}

