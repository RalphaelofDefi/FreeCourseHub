<?php
declare(strict_types=1);

class HttpClient
{
    private int $timeout;
    private string $userAgent;

    public function __construct(?int $timeout = null, ?string $userAgent = null)
    {
        $this->timeout = $timeout ?? HTTP_TIMEOUT;
        $this->userAgent = $userAgent ?? ('FreeCourseHubBot/1.0 (+' . APP_URL . ')');
    }

    public function request(string $method, string $url, array $options = []): array
    {
        if (!is_safe_url($url)) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Invalid URL', 'url' => $url, 'headers' => []];
        }

        $method = strtoupper($method);
        $headers = array_merge([
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.8',
        ], $options['headers'] ?? []);

        $handle = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? $this->timeout),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            if (isset($options['body'])) {
                $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
            }
        } elseif ($method === 'HEAD') {
            $curlOptions[CURLOPT_NOBODY] = true;
        } elseif ($method !== 'GET') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($handle, $curlOptions);
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        return [
            'ok' => $errno === 0 && $status > 0,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
            'url' => $finalUrl !== '' ? $finalUrl : $url,
            'headers' => [],
        ];
    }

    public function get(string $url, array $options = []): array
    {
        return $this->request('GET', $url, $options);
    }

    public function getWithRetry(string $url, int $attempts = 2, array $options = []): array
    {
        $attempts = max(1, $attempts);
        $response = $this->request('GET', $url, $options);
        for ($i = 1; $i < $attempts; $i++) {
            if ($response['ok'] && $response['status'] < 500) {
                break;
            }
            usleep(400000);
            $response = $this->request('GET', $url, $options);
        }
        return $response;
    }
}
