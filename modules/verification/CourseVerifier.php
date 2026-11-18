<?php
declare(strict_types=1);

/**
 * Verifies collected offers against their Udemy and coupon destinations and
 * decides whether they may be published.
 */
class CourseVerifier
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    public function verifyCourse(int $courseId): array
    {
        $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
        $stmt->execute([$courseId]);
        $course = $stmt->fetch();
        if (!$course) {
            return ['status' => 'failed', 'details' => 'Course not found.'];
        }

        $details = [];
        $status = 'verified';
        $strictSources = StrictPriceValidator::sourcesForCourse($courseId);
        $strict = $strictSources !== [];
        $strictPrice = null;

        if (!empty($course['expiry_date']) && strtotime((string) $course['expiry_date']) < time()) {
            $status = 'expired';
            $details[] = 'Coupon expiry date has passed.';
        }

        foreach (['title' => 'Course title', 'udemy_url' => 'Udemy URL', 'coupon_url' => 'Coupon URL'] as $field => $label) {
            if (trim((string) ($course[$field] ?? '')) === '') {
                $status = 'questionable';
                $details[] = $label . ' is missing.';
            }
        }

        if (!empty($course['udemy_url']) && canonical_udemy_key((string) $course['udemy_url']) === null) {
            $status = 'questionable';
            $details[] = 'Udemy URL is not a recognisable course URL.';
        }

        $couponPrice = $course['coupon_price'];
        if ($strict) {
            $strictResult = StrictPriceValidator::recheck($course, $strictSources);
            $strictState = (string) ($strictResult['state'] ?? 'unknown');
            $strictPrice = isset($strictResult['price']) && is_numeric($strictResult['price']) ? (float) $strictResult['price'] : null;
            $details[] = 'Strict price: ' . (string) ($strictResult['message'] ?? 'No details.');

            if ($strictState === 'paid') {
                $status = 'failed';
                $couponPrice = $strictPrice;
                $details[] = 'Rejected: the current enrolment price is above $0.00.';
            } elseif ($strictState === 'unavailable' && $status === 'verified') {
                $status = 'unavailable';
            } elseif ($strictState === 'unknown' && $status === 'verified') {
                $status = 'questionable';
            } elseif ($strictState === 'free') {
                $couponPrice = 0.0;
                $course['coupon_price'] = 0.0;
            }
        } elseif ($status === 'verified' && ($couponPrice === null || $couponPrice === '')) {
            $status = 'questionable';
            $details[] = 'Zero-cost enrollment could not be established from the source.';
        } elseif ($status === 'verified' && (float) $couponPrice > 0) {
            $status = 'questionable';
            $details[] = 'The offer price is above zero; manual review required.';
        }

        $udemyResult = $this->checkDestination((string) $course['udemy_url'], true);
        $details[] = 'Udemy: ' . $udemyResult['message'];
        if ($status === 'verified' && $udemyResult['state'] !== 'ok') {
            if ($udemyResult['state'] === 'gone') {
                $status = 'unavailable';
            } elseif (!$strict) {
                $status = 'questionable';
            }
        }

        $couponResult = $this->checkDestination((string) $course['coupon_url'], false);
        $details[] = 'Coupon: ' . $couponResult['message'];
        if ($status === 'verified' && $couponResult['state'] === 'gone') {
            $status = 'expired';
        } elseif ($status === 'verified' && $couponResult['state'] === 'blocked' && !$strict) {
            $status = 'questionable';
        }

        if (!empty($course['expiry_date']) && $status === 'verified' && strtotime((string) $course['expiry_date']) < time() + 3600) {
            $details[] = 'Coupon expires within the hour.';
        }

        if (!$strict && $status === 'verified' && $couponPrice === null) {
            $status = 'questionable';
        }

        $notes = implode(' ', array_unique($details));
        $this->applyResult($course, $status, $notes, $strict ? $strictPrice : null);
        return ['status' => $status, 'details' => $notes];
    }

    /**
     * @return array{state: string, message: string}
     */
    private function checkDestination(string $url, bool $isUdemy): array
    {
        if ($url === '' || !is_safe_url($url)) {
            return ['state' => 'blocked', 'message' => 'No valid URL to check.'];
        }

        $response = $this->http->getWithRetry($url, 2, ['timeout' => HTTP_TIMEOUT]);
        $statusCode = (int) $response['status'];
        $finalUrl = (string) ($response['url'] ?? $url);

        if ($statusCode === 0) {
            return ['state' => 'blocked', 'message' => 'Destination could not be reached (' . ($response['error'] ?: 'network error') . ').'];
        }
        if ($statusCode === 404 || $statusCode === 410) {
            return ['state' => 'gone', 'message' => 'Destination returned HTTP ' . $statusCode . '.'];
        }
        if (in_array($statusCode, [401, 403, 429], true)) {
            return ['state' => 'blocked', 'message' => 'Destination refused automated verification (HTTP ' . $statusCode . '). Not bypassed.'];
        }
        if ($statusCode >= 500) {
            return ['state' => 'blocked', 'message' => 'Destination returned a server error (HTTP ' . $statusCode . ').'];
        }
        if ($statusCode >= 400) {
            return ['state' => 'blocked', 'message' => 'Destination returned HTTP ' . $statusCode . '.'];
        }

        if ($isUdemy) {
            $key = canonical_udemy_key($finalUrl);
            if ($key === null && stripos($finalUrl, 'udemy.com') === false) {
                return ['state' => 'gone', 'message' => 'Udemy URL redirected away from Udemy.'];
            }
            return ['state' => 'ok', 'message' => 'Udemy course page reachable (HTTP ' . $statusCode . ').'];
        }

        if (stripos($finalUrl, 'udemy.com/course') !== false) {
            return ['state' => 'ok', 'message' => 'Coupon resolves to the Udemy course page (HTTP ' . $statusCode . ').'];
        }

        return ['state' => 'ok', 'message' => 'Coupon destination reachable (HTTP ' . $statusCode . ').'];
    }

    private function applyResult(array $course, string $status, string $notes, ?float $couponPrice = null): void
    {
        $publicationStatus = (string) $course['publication_status'];
        if ($status === 'verified') {
            if (in_array($publicationStatus, ['draft', 'published'], true)) {
                $publicationStatus = 'published';
            }
        } elseif (in_array($status, ['questionable', 'expired', 'unavailable', 'failed'], true)) {
            // Anything that is no longer reliably free comes off public listings
            // and waits for an administrator where applicable.
            if ($publicationStatus === 'published') {
                $publicationStatus = 'unpublished';
            }
        }

        $stmt = db()->prepare(
            'UPDATE courses
             SET verification_status = ?, publication_status = ?, verification_notes = ?,
                 coupon_price = COALESCE(?, coupon_price), last_verified_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $publicationStatus, $notes !== '' ? mb_substr($notes, 0, 5000) : null, $couponPrice, $course['id']]);

        db()->prepare(
            'INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at)
             VALUES (?, ?, ?, NOW())'
        )->execute([$course['id'], $status, mb_substr($notes, 0, 5000)]);
    }

    /**
     * Re-checks courses that are due. Returns counters for job reporting.
     */
    public function runDue(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $intervalHours = max(1, (int) setting('verification_interval_hours', 12));

        $stmt = db()->prepare(
            "SELECT id FROM courses
             WHERE (last_verified_at IS NULL OR last_verified_at < DATE_SUB(NOW(), INTERVAL ? HOUR))
               AND verification_status IN ('pending', 'questionable', 'failed', 'verified')
             ORDER BY COALESCE(last_verified_at, '1970-01-01') ASC, id ASC
             LIMIT " . $limit
        );
        $stmt->execute([$intervalHours]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $summary = ['checked' => 0, 'verified' => 0, 'questionable' => 0, 'expired' => 0, 'unavailable' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                $result = $this->verifyCourse((int) $id);
            } catch (Throwable $e) {
                app_log('verification', 'Verification error for course #' . $id . ': ' . $e->getMessage());
                $result = ['status' => 'failed', 'details' => 'Verification error.'];
                $this->applyResult(['id' => (int) $id, 'publication_status' => 'draft', 'verification_status' => 'failed'], 'failed', $e->getMessage());
            }
            $summary['checked']++;
            $key = (string) $result['status'];
            if (isset($summary[$key])) {
                $summary[$key]++;
            } else {
                $summary['failed']++;
            }
            usleep(200000);
        }

        save_setting('verification_last_run', date('Y-m-d H:i:s'));
        return $summary;
    }
}
