<?php
declare(strict_types=1);

/**
 * Minimal server-side Paystack client. The secret key never leaves the server.
 */
class PaystackClient
{
    private string $secretKey;
    private HttpClient $http;
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = trim((string) ($secretKey ?? PAYSTACK_SECRET_KEY));
        $this->http = new HttpClient(30);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    public function initialize(
        string $email,
        int $amountSubunit,
        string $currency,
        string $reference,
        string $callbackUrl,
        array $metadata = []
    ): array {
        $this->assertConfigured();

        $payload = json_encode([
            'email' => $email,
            'amount' => $amountSubunit,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->http->request('POST', $this->baseUrl . '/transaction/initialize', [
            'headers' => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            'body' => $payload,
        ]);

        $data = $this->decode($response);
        if (!($data['status'] ?? false) || empty($data['data']['authorization_url'])) {
            $message = (string) ($data['message'] ?? ($response['error'] ?: 'Paystack initialization failed.'));
            app_log('payments', 'Paystack initialize failed: ' . $message, ['reference' => $reference, 'status' => $response['status']]);
            throw new RuntimeException($message);
        }

        return [
            'authorization_url' => (string) $data['data']['authorization_url'],
            'access_code' => (string) ($data['data']['access_code'] ?? ''),
            'reference' => (string) ($data['data']['reference'] ?? $reference),
        ];
    }

    public function verify(string $reference): array
    {
        $this->assertConfigured();
        $response = $this->http->getWithRetry(
            $this->baseUrl . '/transaction/verify/' . rawurlencode($reference),
            2,
            [
                'headers' => [
                    'Authorization: Bearer ' . $this->secretKey,
                    'Accept: application/json',
                ],
            ]
        );

        $data = $this->decode($response);
        if (!($data['status'] ?? false) || !isset($data['data'])) {
            $message = (string) ($data['message'] ?? ($response['error'] ?: 'Paystack verification failed.'));
            app_log('payments', 'Paystack verify failed: ' . $message, ['reference' => $reference, 'status' => $response['status']]);
            throw new RuntimeException($message);
        }

        return $data['data'];
    }

    public static function isValidWebhookSignature(string $rawBody, string $signature, ?string $secretKey = null): bool
    {
        $secretKey = trim((string) ($secretKey ?? PAYSTACK_SECRET_KEY));
        if ($secretKey === '' || $signature === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signature);
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Paystack is not configured. Add PAYSTACK_SECRET_KEY to the environment.');
        }
    }

    private function decode(array $response): array
    {
        $decoded = json_decode($response['body'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
