<?php
declare(strict_types=1);

class RateLimiter
{
    public static function check(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $directory = storage_path('cache');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $file = $directory . '/rate_limits.json';
        $bucketKey = hash('sha256', $key);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0];
        }

        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle) ?: '';
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        $bucket = array_values(array_filter((array) ($data[$bucketKey] ?? []), static fn ($timestamp) => (int) $timestamp > $now - $windowSeconds));
        $allowed = count($bucket) < $maxAttempts;
        if ($allowed) {
            $bucket[] = $now;
        }
        $data[$bucketKey] = $bucket;

        foreach ($data as $keyName => $timestamps) {
            $filtered = array_values(array_filter((array) $timestamps, static fn ($timestamp) => (int) $timestamp > $now - 86400));
            if ($filtered === []) {
                unset($data[$keyName]);
            } else {
                $data[$keyName] = $filtered;
            }
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $retryAfter = $allowed ? 0 : max(1, ((int) ($bucket[0] ?? $now)) + $windowSeconds - $now);
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $maxAttempts - count($bucket)),
            'retry_after' => $retryAfter,
        ];
    }

    public static function clear(string $key): void
    {
        $file = storage_path('cache/rate_limits.json');
        if (!is_file($file)) {
            return;
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $data = json_decode(stream_get_contents($handle) ?: '', true);
        if (is_array($data)) {
            unset($data[hash('sha256', $key)]);
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data ?: new stdClass()));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
