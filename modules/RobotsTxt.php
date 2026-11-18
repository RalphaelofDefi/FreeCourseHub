<?php
declare(strict_types=1);

class RobotsTxt
{
    private HttpClient $http;
    private array $cache = [];

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient(12);
    }

    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return false;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $rules = $this->rulesFor($origin);
        if ($rules === false) {
            return false;
        }
        if ($rules === []) {
            return true;
        }
        $path = $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $allowed = true;
        foreach ($rules as [$type, $pattern]) {
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($path, $pattern)) {
                $allowed = $type === 'allow';
            }
        }
        return $allowed;
    }

    private function rulesFor(string $origin): array|false
    {
        if (isset($this->cache[$origin])) {
            return $this->cache[$origin];
        }
        $directory = storage_path('cache/robots');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $file = $directory . '/' . md5($origin) . '.txt';
        $age = is_file($file) ? time() - (int) filemtime($file) : PHP_INT_MAX;

        if (is_file($file) && $age < 86400) {
            $content = (string) file_get_contents($file);
            $rules = $this->parse($content);
            return $this->cache[$origin] = $rules;
        }

        $response = $this->http->getWithRetry($origin . '/robots.txt', 1, ['timeout' => 12]);
        if ($response['status'] === 401 || $response['status'] === 403) {
            return $this->cache[$origin] = false;
        }
        if (!$response['ok'] && $response['status'] === 0) {
            return $this->cache[$origin] = [];
        }
        if ($response['status'] === 404 || trim($response['body']) === '') {
            @file_put_contents($file, '');
            return $this->cache[$origin] = [];
        }
        @file_put_contents($file, $response['body']);
        return $this->cache[$origin] = $this->parse($response['body']);
    }

    private function parse(string $content): array
    {
        $rules = [];
        $inStar = false;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'user-agent:') === 0) {
                $agent = trim(substr($line, strlen('user-agent:')));
                $inStar = $agent === '*';
                continue;
            }
            if (!$inStar) {
                continue;
            }
            if (stripos($line, 'disallow:') === 0) {
                $pattern = trim(substr($line, strlen('disallow:')));
                if ($pattern !== '') {
                    $rules[] = ['disallow', $pattern];
                }
            } elseif (stripos($line, 'allow:') === 0) {
                $pattern = trim(substr($line, strlen('allow:')));
                if ($pattern !== '') {
                    $rules[] = ['allow', $pattern];
                }
            }
        }
        return $rules;
    }
}
