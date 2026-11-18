<?php
declare(strict_types=1);

/**
 * Handles free and paid course unlocking. All monetary decisions are made from
 * server-side settings and verified Paystack data only.
 */
class UnlockService
{
    public function hasUnlock(int $userId, int $courseId): bool
    {
        $stmt = db()->prepare('SELECT id FROM course_unlocks WHERE user_id = ? AND course_id = ? LIMIT 1');
        $stmt->execute([$userId, $courseId]);
        return (bool) $stmt->fetchColumn();
    }

    public function course(int $courseId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
        $stmt->execute([$courseId]);
        $course = $stmt->fetch();
        return $course ?: null;
    }

    /**
     * @return array{status: string, unlock?: array, message?: string}
     */
    public function unlockFree(int $userId, int $courseId): array
    {
        $course = $this->course($courseId);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found.'];
        }

        $strict = StrictPriceValidator::ensureFree($course);
        if (!$strict['ok']) {
            return ['status' => 'error', 'message' => (string) $strict['message']];
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? FOR UPDATE');
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            if (!$course) {
                $pdo->rollBack();
                return ['status' => 'error', 'message' => 'Course not found.'];
            }
            if (!course_is_available($course)) {
                $pdo->rollBack();
                return ['status' => 'error', 'message' => 'This course offer is no longer available.'];
            }

            $stmt = $pdo->prepare('SELECT * FROM course_unlocks WHERE user_id = ? AND course_id = ? LIMIT 1');
            $stmt->execute([$userId, $courseId]);
            $existing = $stmt->fetch();
            if ($existing) {
                $pdo->commit();
                return ['status' => 'already_unlocked', 'unlock' => $existing];
            }

            $stmt = $pdo->prepare('SELECT id, free_unlocks_remaining FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) {
                $pdo->rollBack();
                return ['status' => 'error', 'message' => 'Your account could not be loaded.'];
            }

            // Platform-wide free mode: every available course can be unlocked
            // without spending the user's allowance and without any payment.
            if (!platform_payments_enabled()) {
                $pdo->prepare(
                    "INSERT INTO course_unlocks (user_id, course_id, unlock_type, unlocked_at) VALUES (?, ?, 'free', NOW())"
                )->execute([$userId, $courseId]);

                $pdo->commit();
                log_activity($userId, 'course.unlocked', 'Free platform unlock for course #' . $courseId . ' (' . $course['title'] . ')');
                return ['status' => 'unlocked'];
            }

            $freeEnabled = setting_bool('free_access_enabled', true);
            $remaining = (int) $user['free_unlocks_remaining'];

            if (!$freeEnabled || $remaining <= 0) {
                $pdo->commit();
                return ['status' => setting_bool('paid_access_enabled', true) ? 'requires_payment' : 'locked'];
            }

            $pdo->prepare('UPDATE users SET free_unlocks_remaining = free_unlocks_remaining - 1, updated_at = NOW() WHERE id = ? AND free_unlocks_remaining > 0')
                ->execute([$userId]);

            $pdo->prepare(
                "INSERT INTO course_unlocks (user_id, course_id, unlock_type, unlocked_at) VALUES (?, ?, 'free', NOW())"
            )->execute([$userId, $courseId]);

            $pdo->commit();
            log_activity($userId, 'course.unlocked', 'Free unlock for course #' . $courseId . ' (' . $course['title'] . ')');
            return ['status' => 'unlocked'];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                return ['status' => 'already_unlocked'];
            }
            app_log('unlocks', 'Free unlock failed: ' . $e->getMessage(), ['user' => $userId, 'course' => $courseId]);
            return ['status' => 'error', 'message' => 'We could not unlock this course. Please try again.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('unlocks', 'Free unlock failed: ' . $e->getMessage(), ['user' => $userId, 'course' => $courseId]);
            return ['status' => 'error', 'message' => 'We could not unlock this course. Please try again.'];
        }
    }

    /**
     * Creates a pending payment and initializes Paystack checkout.
     *
     * @return array{authorization_url: string, reference: string}
     */
    public function initializePayment(array $user, int $courseId): array
    {
        if (!platform_payments_enabled()) {
            throw new RuntimeException('The platform is currently free, so payments are disabled.');
        }
        if (!setting_bool('paid_access_enabled', true)) {
            throw new RuntimeException('Paid unlocking is currently disabled.');
        }

        $client = new PaystackClient();
        if (!$client->isConfigured()) {
            throw new RuntimeException('Payments are not configured yet. Please try again later.');
        }

        $course = $this->course($courseId);
        if (!$course) {
            throw new RuntimeException('Course not found.');
        }
        if (!course_is_available($course)) {
            throw new RuntimeException('This course offer is no longer available.');
        }
        $strict = StrictPriceValidator::ensureFree($course);
        if (!$strict['ok']) {
            throw new RuntimeException((string) $strict['message']);
        }
        if ($this->hasUnlock((int) $user['id'], $courseId)) {
            throw new RuntimeException('You have already unlocked this course.');
        }

        $limit = RateLimiter::check('payment-init:' . $user['id'], 10, 600);
        if (!$limit['allowed']) {
            throw new RuntimeException('Too many payment attempts. Please wait a few minutes and try again.');
        }

        $price = round((float) setting('unlock_price', 0), 2);
        $currency = strtoupper((string) setting('default_currency', 'NGN'));
        if ($price <= 0) {
            throw new RuntimeException('The course price is not configured.');
        }

        $reference = 'FCH-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = db()->prepare(
            "INSERT INTO payments (user_id, course_id, transaction_reference, amount, currency, provider, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'paystack', 'pending', NOW())"
        );
        $stmt->execute([$user['id'], $courseId, $reference, $price, $currency]);
        $paymentId = (int) db()->lastInsertId();

        try {
            $initialized = $client->initialize(
                (string) $user['email'],
                $this->toSubunit($price, $currency),
                $currency,
                $reference,
                PAYSTACK_CALLBACK_URL,
                ['course_id' => $courseId, 'user_id' => (int) $user['id'], 'payment_id' => $paymentId]
            );
        } catch (Throwable $e) {
            db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$paymentId]);
            throw new RuntimeException('We could not start the payment. ' . $e->getMessage());
        }

        return ['authorization_url' => $initialized['authorization_url'], 'reference' => $reference];
    }

    /**
     * Independently verifies a transaction with Paystack and grants access.
     *
     * @return array{ok: bool, message: string, course_id?: int, status?: string}
     */
    public function verifyAndComplete(string $reference, ?int $expectedUserId = null): array
    {
        $stmt = db()->prepare('SELECT * FROM payments WHERE transaction_reference = ? LIMIT 1');
        $stmt->execute([$reference]);
        $payment = $stmt->fetch();
        if (!$payment) {
            return ['ok' => false, 'message' => 'Unknown transaction reference.'];
        }
        if ($expectedUserId !== null && (int) $payment['user_id'] !== $expectedUserId) {
            app_log('payments', 'Reference ownership mismatch', ['reference' => $reference, 'user' => $expectedUserId]);
            return ['ok' => false, 'message' => 'This transaction does not belong to your account.'];
        }

        if (($payment['status'] ?? '') === 'success') {
            $this->grantPaidUnlock($payment);
            return ['ok' => true, 'message' => 'Payment already verified.', 'course_id' => (int) $payment['course_id'], 'status' => 'success'];
        }

        if (($payment['status'] ?? '') === 'pending' || ($payment['status'] ?? '') === 'failed') {
            try {
                $client = new PaystackClient();
                $transaction = $client->verify($reference);
            } catch (Throwable $e) {
                app_log('payments', 'Verification request failed: ' . $e->getMessage(), ['reference' => $reference]);
                return ['ok' => false, 'message' => 'We could not verify this payment right now. Please try again shortly.'];
            }

            $gatewayStatus = strtolower((string) ($transaction['status'] ?? ''));
            if ($gatewayStatus === 'failed') {
                db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ? AND status = 'pending'")->execute([$payment['id']]);
                return ['ok' => false, 'message' => 'The payment was not successful.', 'course_id' => (int) $payment['course_id'], 'status' => 'failed'];
            }
            if ($gatewayStatus === 'abandoned') {
                db()->prepare("UPDATE payments SET status = 'abandoned' WHERE id = ? AND status = 'pending'")->execute([$payment['id']]);
                return ['ok' => false, 'message' => 'This payment was not completed.', 'course_id' => (int) $payment['course_id'], 'status' => 'abandoned'];
            }
            if ($gatewayStatus === 'reversed') {
                db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ? AND status = 'pending'")->execute([$payment['id']]);
                return ['ok' => false, 'message' => 'This payment was reversed.', 'course_id' => (int) $payment['course_id'], 'status' => 'failed'];
            }
            if ($gatewayStatus !== 'success') {
                return ['ok' => false, 'message' => 'The payment is not complete yet. If you were charged, please contact support.', 'course_id' => (int) $payment['course_id'], 'status' => $gatewayStatus];
            }

            $amountPaid = (int) ($transaction['amount'] ?? 0);
            $expectedAmount = $this->toSubunit((float) $payment['amount'], (string) $payment['currency']);
            $paidCurrency = strtoupper((string) ($transaction['currency'] ?? ''));
            $gatewayReference = (string) ($transaction['reference'] ?? '');

            if ($gatewayReference !== $reference) {
                app_log('payments', 'Reference mismatch during verification', ['expected' => $reference, 'got' => $gatewayReference]);
                return ['ok' => false, 'message' => 'Payment reference verification failed.'];
            }
            if ($amountPaid !== $expectedAmount) {
                app_log('payments', 'Amount mismatch during verification', ['reference' => $reference, 'expected' => $expectedAmount, 'paid' => $amountPaid]);
                return ['ok' => false, 'message' => 'The paid amount does not match the expected amount. Access was not granted.'];
            }
            if ($paidCurrency !== strtoupper((string) $payment['currency'])) {
                app_log('payments', 'Currency mismatch during verification', ['reference' => $reference, 'expected' => $payment['currency'], 'paid' => $paidCurrency]);
                return ['ok' => false, 'message' => 'The payment currency does not match. Access was not granted.'];
            }

            $result = $this->finalize($payment, $transaction);
            if (!$result) {
                return ['ok' => false, 'message' => 'We could not finalize this payment. Please contact support.'];
            }
            return ['ok' => true, 'message' => 'Payment verified. Your course is unlocked.', 'course_id' => (int) $payment['course_id'], 'status' => 'success'];
        }

        return ['ok' => false, 'message' => 'This transaction cannot be verified.'];
    }

    private function finalize(array $payment, array $transaction, bool $fromWebhook = false): bool
    {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$payment['id']]);
            $locked = $stmt->fetch();
            if (!$locked) {
                $pdo->rollBack();
                return false;
            }

            if ($locked['status'] !== 'success') {
                $pdo->prepare(
                    "UPDATE payments SET status = 'success', verified_at = NOW() WHERE id = ?"
                )->execute([$locked['id']]);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO course_unlocks (user_id, course_id, unlock_type, payment_id, unlocked_at)
                 VALUES (?, ?, 'paid', ?, NOW())
                 ON DUPLICATE KEY UPDATE payment_id = COALESCE(course_unlocks.payment_id, VALUES(payment_id))"
            );
            $stmt->execute([$locked['user_id'], $locked['course_id'], $locked['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('payments', 'Finalize failed: ' . $e->getMessage(), ['reference' => $payment['transaction_reference']]);
            return false;
        }

        log_activity((int) $payment['user_id'], 'payment.verified', 'Payment ' . $payment['transaction_reference'] . ' verified' . ($fromWebhook ? ' via webhook' : ' via callback'));
        return true;
    }

    /**
     * Webhook entry point: validates the event and completes the payment.
     */
    public function completeFromWebhook(array $eventData): bool
    {
        $reference = trim((string) ($eventData['reference'] ?? ''));
        if ($reference === '') {
            return false;
        }
        $stmt = db()->prepare('SELECT * FROM payments WHERE transaction_reference = ? LIMIT 1');
        $stmt->execute([$reference]);
        $payment = $stmt->fetch();
        if (!$payment) {
            app_log('payments', 'Webhook reference not found', ['reference' => $reference]);
            return false;
        }
        if ($payment['status'] === 'success') {
            return true;
        }
        if (strtolower((string) ($eventData['status'] ?? '')) !== 'success') {
            return false;
        }

        $amount = (int) ($eventData['amount'] ?? 0);
        $expected = $this->toSubunit((float) $payment['amount'], (string) $payment['currency']);
        if ($amount !== $expected) {
            app_log('payments', 'Webhook amount mismatch', ['reference' => $reference, 'expected' => $expected, 'paid' => $amount]);
            return false;
        }
        if (strtoupper((string) ($eventData['currency'] ?? '')) !== strtoupper((string) $payment['currency'])) {
            app_log('payments', 'Webhook currency mismatch', ['reference' => $reference]);
            return false;
        }

        return $this->finalize($payment, $eventData, true);
    }

    private function grantPaidUnlock(array $payment): void
    {
        try {
            db()->prepare(
                "INSERT INTO course_unlocks (user_id, course_id, unlock_type, payment_id, unlocked_at)
                 VALUES (?, ?, 'paid', ?, NOW())
                 ON DUPLICATE KEY UPDATE payment_id = COALESCE(course_unlocks.payment_id, VALUES(payment_id))"
            )->execute([$payment['user_id'], $payment['course_id'], $payment['id']]);
        } catch (Throwable $e) {
            app_log('payments', 'Unable to ensure unlock: ' . $e->getMessage());
        }
    }

    public function toSubunit(float $amount, string $currency): int
    {
        return (int) round($amount * 100);
    }

    public function accessUrlForCourse(array $course): ?string
    {
        foreach ([$course['coupon_url'] ?? null, $course['udemy_url'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_safe_url($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
