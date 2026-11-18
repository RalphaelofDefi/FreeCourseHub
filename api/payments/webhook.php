<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$rawBody = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');

if (!PaystackClient::isValidWebhookSignature($rawBody, $signature)) {
    app_log('payments', 'Rejected webhook with invalid signature', ['ip' => client_ip()]);
    json_response(['ok' => false, 'error' => 'Invalid signature.'], 401);
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Invalid payload.'], 400);
}

$eventName = (string) ($event['event'] ?? '');
$data = is_array($event['data'] ?? null) ? $event['data'] : [];

if ($eventName !== 'charge.success') {
    json_response(['ok' => true, 'ignored' => true]);
}

try {
    $service = new UnlockService();
    $processed = $service->completeFromWebhook($data);
    json_response(['ok' => $processed]);
} catch (Throwable $e) {
    app_log('payments', 'Webhook processing error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Processing error.'], 500);
}
