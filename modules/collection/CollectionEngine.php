<?php
declare(strict_types=1);

/**
 * Collects course offers from every enabled source, normalizes them through the
 * source adapters, deduplicates and stores new or improved records.
 */
class CollectionEngine
{
    private const CATEGORY_KEYWORDS = [
        'development' => ['development', 'programming', 'web development', 'software', 'coding', 'mobile app', 'game development', 'python', 'javascript', 'java', 'php'],
        'business' => ['business', 'entrepreneurship', 'management', 'strategy', 'startup', 'leadership'],
        'design' => ['design', 'graphic', 'ux', 'ui', 'creative', 'illustration', 'figma'],
        'marketing' => ['marketing', 'seo', 'social media', 'branding', 'digital marketing', 'advertising'],
        'it-software' => ['it & software', 'it and software', 'networking', 'cloud', 'security', 'cyber', 'devops', 'database', 'hardware'],
        'personal-development' => ['personal development', 'productivity', 'habits', 'communication', 'career', 'self'],
        'photography' => ['photography', 'photo', 'video production', 'videography', 'filmmaking'],
        'music' => ['music', 'audio', 'instrument', 'guitar', 'piano', 'sound'],
        'health-fitness' => ['health', 'fitness', 'wellness', 'nutrition', 'yoga', 'workout'],
        'finance-accounting' => ['finance', 'accounting', 'investing', 'financial', 'bookkeeping', 'trading'],
        'office-productivity' => ['office productivity', 'excel', 'spreadsheet', 'microsoft office', 'word', 'powerpoint'],
        'teaching-academics' => ['teaching', 'academics', 'language learning', 'education', 'exam'],
    ];

    public function run(?int $maxPages = null, ?int $maxCourses = null, ?int $sourceId = null, ?int $timeBudgetSeconds = null): array
    {
        $maxPages = max(1, $maxPages ?? COLLECTION_MAX_PAGES);
        $maxCourses = max(1, $maxCourses ?? 40);

        $lock = new JobLock('collection');
        if (!$lock->acquire()) {
            return ['ok' => false, 'error' => 'A collection job is already running.', 'sources' => []];
        }

        $deadline = $timeBudgetSeconds !== null ? microtime(true) + max(10, $timeBudgetSeconds) : null;

        $summary = [
            'ok' => true,
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => null,
            'sources' => [],
            'new_courses' => 0,
            'duplicates' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        try {
            $sql = "SELECT * FROM course_sources WHERE status = 'enabled'";
            $params = [];
            if ($sourceId !== null) {
                $sql .= ' AND id = ?';
                $params[] = $sourceId;
            }
            $sql .= ' ORDER BY id ASC';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $sources = $stmt->fetchAll();

            $this->closeInterruptedLogs();

            $remainingSources = max(1, count($sources));
            foreach ($sources as $source) {
                $sourceDeadline = null;
                if ($deadline !== null) {
                    $remainingTime = max(0.0, $deadline - microtime(true));
                    $sourceDeadline = microtime(true) + max(5.0, $remainingTime / $remainingSources);
                }
                $summary['sources'][] = $this->collectSource($source, $maxPages, $maxCourses, $summary, $sourceDeadline);
                $remainingSources--;
            }
        } catch (Throwable $e) {
            app_log('collection', 'Collection engine failed: ' . $e->getMessage());
            $summary['ok'] = false;
            $summary['error'] = $e->getMessage();
        } finally {
            $lock->release();
        }

        $summary['completed_at'] = date('Y-m-d H:i:s');
        save_setting('collection_last_run', $summary['completed_at']);
        return $summary;
    }

    private function collectSource(array $source, int $maxPages, int $maxCourses, array &$summary, ?float $deadline = null): array
    {
        $adapterClass = (string) $source['adapter'];
        $result = [
            'source' => $source['name'],
            'source_id' => (int) $source['id'],
            'status' => 'failed',
            'discovered' => 0,
            'new' => 0,
            'duplicates' => 0,
            'failed' => 0,
            'skipped' => 0,
            'error' => null,
        ];

        $logId = $this->startLog((int) $source['id']);
        $errorMessages = [];

        try {
            if (!class_exists($adapterClass)) {
                throw new RuntimeException('Adapter class not found: ' . $adapterClass);
            }
            /** @var BaseAdapter $adapter */
            $adapter = new $adapterClass();
            if ($adapter->name !== $source['name'] || rtrim($adapter->baseUrl, '/') !== rtrim((string) $source['base_url'], '/')) {
                $adapter = $this->adapterForSource($source);
            }

            // Web-triggered runs are time-budgeted. In that mode skip detail
            // pages that are already stored so the run reaches deeper listings
            // instead of spending its budget re-fetching duplicates.
            $knownUrls = $deadline !== null ? $this->knownSourceUrls((int) $source['id']) : [];
            $collected = $adapter->collect($maxPages, $maxCourses, $deadline, $knownUrls);
            $result['discovered'] = count($collected['courses']);
            $result['skipped'] = (int) ($collected['skipped'] ?? 0);
            $errorMessages = array_merge($errorMessages, $collected['errors']);
            if ($result['skipped'] > 0) {
                $errorMessages[] = 'Skipped ' . $result['skipped'] . ' paid deal(s) with a price above zero.';
            }

            foreach ($collected['courses'] as $course) {
                try {
                    $outcome = $this->storeCourse($course, $source);
                    if ($outcome === 'skipped') {
                        $result['skipped']++;
                    } else {
                        $result[$outcome === 'duplicate' ? 'duplicates' : 'new']++;
                    }
                } catch (Throwable $e) {
                    $result['failed']++;
                    $errorMessages[] = 'Store failed: ' . $e->getMessage();
                    app_log('collection', 'Unable to store course: ' . $e->getMessage(), ['source' => $source['name']]);
                }
            }

            $result['status'] = $result['failed'] > 0 && $result['new'] === 0 ? 'partial' : 'completed';
            db()->prepare('UPDATE course_sources SET last_fetched_at = NOW() WHERE id = ?')->execute([$source['id']]);
        } catch (Throwable $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            $errorMessages[] = $e->getMessage();
            app_log('collection', 'Source collection failed: ' . $e->getMessage(), ['source' => $source['name']]);
        }

        $summary['new_courses'] += $result['new'];
        $summary['duplicates'] += $result['duplicates'];
        $summary['failed'] += $result['failed'];
        $summary['skipped'] += $result['skipped'];

        $this->finishLog($logId, $result, $errorMessages);
        return $result;
    }

    private function adapterForSource(array $source): BaseAdapter
    {
        return match ($source['adapter']) {
            'DiscUdemyAdapter' => new DiscUdemyAdapter(),
            'CouponScorpionAdapter' => new CouponScorpionAdapter(),
            'UdemyFreeAdapter' => new UdemyFreeAdapter(),
            'RealDiscountAdapter' => new RealDiscountAdapter(),
            default => throw new RuntimeException('No adapter available for ' . $source['name']),
        };
    }

    /**
     * A previous web-triggered run can be killed by PHP's execution limit. The
     * file lock guarantees no collection is active when this runs, so any log
     * still marked running is stale.
     */
    private function knownSourceUrls(int $sourceId): array
    {
        try {
            $stmt = db()->prepare('SELECT source_course_url FROM course_source_links WHERE source_id = ?');
            $stmt->execute([$sourceId]);
            return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            app_log('collection', 'Unable to load known source URLs: ' . $e->getMessage());
            return [];
        }
    }

    private function closeInterruptedLogs(): void
    {
        try {
            db()->exec(
                "UPDATE collection_logs
                 SET status = 'failed',
                     error_message = CONCAT(COALESCE(error_message, ''), IF(COALESCE(error_message, '') = '', '', ' '), 'The job was interrupted before completion.'),
                     completed_at = NOW()
                 WHERE status = 'running'"
            );
        } catch (Throwable $e) {
            app_log('collection', 'Unable to close interrupted logs: ' . $e->getMessage());
        }
    }

    private function startLog(int $sourceId): int
    {
        $stmt = db()->prepare(
            "INSERT INTO collection_logs (source_id, status, started_at) VALUES (?, 'running', NOW())"
        );
        $stmt->execute([$sourceId]);
        return (int) db()->lastInsertId();
    }

    private function finishLog(int $logId, array $result, array $errors): void
    {
        $message = implode(' | ', array_slice(array_unique($errors), 0, 12));
        if (mb_strlen($message) > 4000) {
            $message = mb_substr($message, 0, 4000);
        }
        $stmt = db()->prepare(
            'UPDATE collection_logs
             SET status = ?, discovered_count = ?, new_courses_count = ?, duplicate_count = ?,
                 failed_count = ?, error_message = ?, completed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $result['status'],
            $result['discovered'],
            $result['new'],
            $result['duplicates'],
            $result['failed'],
            $message !== '' ? $message : null,
            $logId,
        ]);
    }

    /**
     * @return string new|duplicate|updated|skipped
     */
    private function storeCourse(array $course, array $source): string
    {
        $couponPrice = $course['coupon_price'] ?? null;
        if ($couponPrice !== null && $couponPrice !== '' && (float) $couponPrice > 0) {
            return 'skipped';
        }

        $title = trim((string) ($course['title'] ?? ''));
        $canonical = trim((string) ($course['canonical_key'] ?? ''));
        $udemyUrl = trim((string) ($course['udemy_url'] ?? ''));
        if ($title === '' || $udemyUrl === '' || $canonical === '' || !is_safe_url($udemyUrl)) {
            throw new RuntimeException('Course is missing required title or Udemy URL.');
        }

        $categoryId = $this->resolveCategoryId((string) ($course['category'] ?? ''));

        $existing = $this->findExisting($canonical, $title);

        if ($existing !== null) {
            $this->recordSourceLink((int) $existing['id'], (int) $source['id'], (string) ($course['source_course_url'] ?? $udemyUrl));
            $this->fillMissingMetadata($existing, $course);
            return 'duplicate';
        }

        $slug = $this->uniqueSlug($title);
        // When an administrator turns approvals off, new offers publish
        // immediately. The verifier still checks them on its next run and
        // unpublishes anything that no longer looks free or reachable.
        $autoApprove = !setting_bool('approval_required', true);
        $verificationStatus = $autoApprove ? 'verified' : 'pending';
        $publicationStatus = $autoApprove ? 'published' : 'draft';
        $verificationNotes = null;

        $strictPrice = !empty($course['strict_price']);
        $priceStatus = (string) ($course['price_status'] ?? '');
        if ($strictPrice) {
            if ($priceStatus === 'unknown' || $couponPrice === null || $couponPrice === '') {
                $verificationStatus = 'questionable';
                $publicationStatus = 'draft';
                $verificationNotes = 'Strict $0.00 source: the current enrolment price could not be confirmed. Awaiting manual review.';
            } elseif ((float) $couponPrice > 0) {
                return 'skipped';
            }
        }

        $stmt = db()->prepare(
            'INSERT INTO courses
                (title, slug, canonical_key, description, thumbnail_url, instructor, category_id, rating, review_count,
                 student_count, language, level, duration, original_price, coupon_price, currency, udemy_url, coupon_url,
                 expiry_date, verification_status, publication_status, verification_notes, discovered_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            mb_substr($title, 0, 255),
            $slug,
            $canonical,
            $course['description'] ?? null,
            $course['thumbnail_url'] ?? null,
            $course['instructor'] ?? null,
            $categoryId,
            $course['rating'] ?? null,
            $course['review_count'] ?? null,
            $course['student_count'] ?? null,
            $course['language'] ?? null,
            $course['level'] ?? null,
            $course['duration'] ?? null,
            $course['original_price'] ?? null,
            $course['coupon_price'] ?? null,
            $course['currency'] ?? 'USD',
            mb_substr($udemyUrl, 0, 600),
            isset($course['coupon_url']) ? mb_substr((string) $course['coupon_url'], 0, 600) : null,
            $course['expiry_date'] ?? null,
            $verificationStatus,
            $publicationStatus,
            $verificationNotes,
            $course['discovered_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $courseId = (int) db()->lastInsertId();
        $this->recordSourceLink($courseId, (int) $source['id'], (string) ($course['source_course_url'] ?? $udemyUrl));
        log_activity(null, 'course.collected', 'Collected "' . $title . '" from ' . $source['name']);
        return 'new';
    }

    private function findExisting(string $canonical, string $title): ?array
    {
        $stmt = db()->prepare('SELECT * FROM courses WHERE canonical_key = ? LIMIT 1');
        $stmt->execute([$canonical]);
        $course = $stmt->fetch();
        if ($course) {
            return $course;
        }

        // Secondary duplicate detection: normalized title. The normaliser ignores
        // punctuation, casing and source suffixes, so compare candidates in PHP
        // instead of relying on the raw stored title.
        $normalized = $this->normalizeTitle($title);
        if ($normalized === '') {
            return null;
        }
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $needle = $tokens[0] ?? $normalized;
        if (mb_strlen($needle) < 4) {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM courses WHERE title LIKE ? LIMIT 200');
        $stmt->execute(['%' . $needle . '%']);
        foreach ($stmt->fetchAll() as $candidate) {
            if ($this->normalizeTitle((string) $candidate['title']) === $normalized) {
                return $candidate;
            }
        }
        return null;
    }

    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $title = preg_replace('/\s*[\|\-–]\s*(free\s+)?udemy.*$/iu', '', $title) ?? $title;
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    private function fillMissingMetadata(array $existing, array $course): void
    {
        $columns = [
            'description', 'thumbnail_url', 'instructor', 'rating', 'review_count', 'student_count',
            'language', 'level', 'duration', 'original_price', 'coupon_price', 'currency',
            'coupon_url', 'expiry_date',
        ];
        $updates = [];
        $params = [];
        foreach ($columns as $column) {
            $current = $existing[$column] ?? null;
            $incoming = $course[$column] ?? null;
            if (($current === null || $current === '' || (float) $current === 0.0) && $incoming !== null && $incoming !== '') {
                $updates[] = $column . ' = ?';
                $params[] = $incoming;
            }
        }
        if ($existing['category_id'] === null && !empty($course['category'])) {
            $categoryId = $this->resolveCategoryId((string) $course['category']);
            if ($categoryId !== null) {
                $updates[] = 'category_id = ?';
                $params[] = $categoryId;
            }
        }
        if ($updates === []) {
            return;
        }
        $updates[] = 'updated_at = NOW()';
        $params[] = $existing['id'];
        db()->prepare('UPDATE courses SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
    }

    private function recordSourceLink(int $courseId, int $sourceId, string $url): void
    {
        $stmt = db()->prepare(
            'INSERT INTO course_source_links (course_id, source_id, source_course_url, discovered_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE source_course_url = VALUES(source_course_url)'
        );
        $stmt->execute([$courseId, $sourceId, mb_substr($url, 0, 600)]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = mb_substr(slugify($title), 0, 200);
        $slug = $base;
        $attempt = 1;
        $stmt = db()->prepare('SELECT id FROM courses WHERE slug = ? LIMIT 1');
        while (true) {
            $stmt->execute([$slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $attempt++;
            $slug = $base . '-' . $attempt;
            if ($attempt > 50) {
                return $base . '-' . bin2hex(random_bytes(4));
            }
        }
    }

    private function resolveCategoryId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $slug = slugify($name);

        $stmt = db()->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $stmt = db()->prepare('SELECT id FROM categories WHERE LOWER(name) = ? LIMIT 1');
        $stmt->execute([mb_strtolower($name)]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $haystack = ' ' . slugify($name) . ' ';
        foreach (self::CATEGORY_KEYWORDS as $categorySlug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, ' ' . slugify($keyword) . ' ')) {
                    $stmt = db()->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
                    $stmt->execute([$categorySlug]);
                    $id = $stmt->fetchColumn();
                    if ($id) {
                        return (int) $id;
                    }
                }
            }
        }

        return null;
    }
}
