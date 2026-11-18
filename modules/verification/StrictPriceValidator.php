<?php
declare(strict_types=1);

/**
 * Enforces the zero-price rule for courses discovered through strict sources.
 * It is used by the verifier and by the unlock/access flow so a course cannot
 * be published or unlocked after its price stops being free.
 */
class StrictPriceValidator
{
    private static array $cache = [];

    public static function sourcesForCourse(int $courseId): array
    {
        return course_strict_sources($courseId);
    }

    public static function isStrictCourse(int $courseId): bool
    {
        return self::sourcesForCourse($courseId) !== [];
    }

    /**
     * @return array{state: string, message: string, price?: float}
     */
    public static function recheck(array $course, array $sources = []): array
    {
        $courseId = (int) ($course['id'] ?? 0);
        if ($sources === []) {
            $sources = $courseId > 0 ? self::sourcesForCourse($courseId) : [];
        }
        if ($sources === []) {
            return ['state' => 'not_strict', 'message' => 'No strict price source is linked to this course.'];
        }

        $cacheKey = $courseId . ':' . md5(json_encode([
            $course['coupon_url'] ?? null,
            $course['udemy_url'] ?? null,
            $course['canonical_key'] ?? null,
            array_map(static fn (array $source): string => (string) ($source['id'] ?? '') . ':' . (string) ($source['source_course_url'] ?? ''), $sources),
        ]));
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $states = [];
        $messages = [];
        $detectedPrice = null;

        foreach ($sources as $source) {
            $adapter = self::adapterForSource($source);
            if ($adapter === null) {
                $states[] = 'unknown';
                $messages[] = ($source['name'] ?? 'Unknown source') . ': adapter unavailable.';
                continue;
            }
            try {
                $result = $adapter->recheckPrice($course, $source);
            } catch (Throwable $e) {
                app_log('verification', 'Strict price recheck failed: ' . $e->getMessage(), [
                    'course' => $courseId,
                    'source' => $source['name'] ?? null,
                ]);
                $result = ['state' => 'unknown', 'message' => 'Price recheck failed: ' . $e->getMessage()];
            }

            $state = (string) ($result['state'] ?? 'unknown');
            $states[] = $state;
            $messages[] = ($source['name'] ?? 'Unknown source') . ': ' . (string) ($result['message'] ?? 'No details.');
            if ($state === 'paid') {
                $detectedPrice = isset($result['price']) ? (float) $result['price'] : null;
                $message = implode(' ', array_unique($messages));
                return self::$cache[$cacheKey] = [
                    'state' => 'paid',
                    'price' => $detectedPrice,
                    'message' => $message,
                ];
            }
            if ($state === 'free') {
                $detectedPrice = 0.0;
            }
        }

        if (in_array('free', $states, true)) {
            $state = 'free';
        } elseif (in_array('unavailable', $states, true)) {
            $state = 'unavailable';
        } else {
            $state = 'unknown';
        }

        return self::$cache[$cacheKey] = [
            'state' => $state,
            'price' => $detectedPrice,
            'message' => implode(' ', array_unique($messages)),
        ];
    }

    /**
     * Confirms that a strict course is still free before it is unlocked or its
     * enrolment link is shared. Recently verified courses skip the network call.
     *
     * @return array{ok: bool, state: string, message: string, price?: float}
     */
    public static function ensureFree(array $course, int $maxAgeSeconds = 900): array
    {
        $courseId = (int) ($course['id'] ?? 0);
        $sources = self::sourcesForCourse($courseId);
        if ($sources === []) {
            return ['ok' => true, 'state' => 'not_strict', 'message' => 'No strict price source is linked to this course.'];
        }

        if (self::isFreshFree($course, $maxAgeSeconds)) {
            return ['ok' => true, 'state' => 'free', 'message' => 'The stored verification confirms the offer is free.'];
        }

        $result = self::recheck($course, $sources);
        $state = (string) ($result['state'] ?? 'unknown');

        if ($state === 'free') {
            self::applyResult($courseId, 'verified', (string) ($result['message'] ?? 'Strict price recheck confirmed the offer is free.'), 0.0);
            return ['ok' => true, 'state' => 'free', 'message' => (string) ($result['message'] ?? 'The offer is free.')];
        }

        if ($state === 'paid') {
            $price = $result['price'] ?? null;
            self::applyResult($courseId, 'failed', (string) ($result['message'] ?? 'The current price is above $0.00.'), is_numeric($price) ? (float) $price : null);
            return ['ok' => false, 'state' => 'paid', 'price' => is_numeric($price) ? (float) $price : null, 'message' => 'This offer is no longer free and cannot be unlocked.'];
        }

        if ($state === 'unavailable') {
            self::applyResult($courseId, 'unavailable', (string) ($result['message'] ?? 'The offer is no longer available.'), null);
            return ['ok' => false, 'state' => 'unavailable', 'message' => 'This offer is no longer available.'];
        }

        return ['ok' => false, 'state' => 'unknown', 'message' => 'The current price of this offer could not be confirmed. Please try again later.'];
    }

    public static function canManuallyPublish(array $course): array
    {
        $courseId = (int) ($course['id'] ?? 0);
        $sources = self::sourcesForCourse($courseId);
        if ($sources === []) {
            return ['ok' => true, 'message' => 'No strict price source is linked to this course.'];
        }

        $result = self::recheck($course, $sources);
        $state = (string) ($result['state'] ?? 'unknown');
        if ($state === 'free') {
            return ['ok' => true, 'message' => (string) ($result['message'] ?? 'The offer is free.')];
        }
        return [
            'ok' => false,
            'message' => $state === 'paid'
                ? 'This offer is no longer free. It cannot be approved or published.'
                : 'The current price could not be confirmed. This offer cannot be approved or published yet.',
        ];
    }

    public static function storedPriceAllowsPublication(array $course): bool
    {
        $courseId = (int) ($course['id'] ?? 0);
        if (!self::isStrictCourse($courseId)) {
            return true;
        }
        return strict_price_is_free($course['coupon_price'] ?? null);
    }

    private static function isFreshFree(array $course, int $maxAgeSeconds): bool
    {
        if (($course['verification_status'] ?? '') !== 'verified' || !strict_price_is_free($course['coupon_price'] ?? null)) {
            return false;
        }
        $lastVerified = $course['last_verified_at'] ?? null;
        if (empty($lastVerified)) {
            return false;
        }
        $timestamp = strtotime((string) $lastVerified);
        return $timestamp !== false && $timestamp >= time() - max(0, $maxAgeSeconds);
    }

    private static function adapterForSource(array $source): ?BaseAdapter
    {
        $class = (string) ($source['adapter'] ?? '');
        if ($class === '' || !class_exists($class)) {
            return null;
        }
        $adapter = new $class();
        return $adapter instanceof BaseAdapter ? $adapter : null;
    }

    private static function applyResult(int $courseId, string $status, string $notes, ?float $couponPrice): void
    {
        if ($courseId <= 0) {
            return;
        }
        try {
            $stmt = db()->prepare('SELECT publication_status FROM courses WHERE id = ? LIMIT 1');
            $stmt->execute([$courseId]);
            $currentPublication = (string) $stmt->fetchColumn();

            $publication = $currentPublication;
            if ($status === 'verified' && in_array($currentPublication, ['draft', 'published'], true)) {
                $publication = 'published';
            } elseif (in_array($status, ['failed', 'unavailable'], true) && $currentPublication === 'published') {
                $publication = 'unpublished';
            }

            $stmt = db()->prepare(
                'UPDATE courses
                 SET verification_status = ?, publication_status = ?, verification_notes = ?,
                     coupon_price = COALESCE(?, coupon_price), last_verified_at = NOW(), updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$status, $publication, mb_substr($notes, 0, 5000), $couponPrice, $courseId]);
        } catch (Throwable $e) {
            app_log('verification', 'Unable to store strict price result: ' . $e->getMessage(), ['course' => $courseId]);
        }
    }
}
