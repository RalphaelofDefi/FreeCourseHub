<?php
declare(strict_types=1);

/**
 * Collects free Udemy offers from the public Real.discount course feed.
 * Real.discount exposes its catalogue through a JSON endpoint used by the
 * public site, so no HTML scraping or access restrictions are bypassed.
 */
class RealDiscountAdapter extends BaseAdapter
{
    private const API_BASE = 'https://cdn.real.discount/api/courses';
    private const OFFER_BASE = 'https://www.real.discount/offer/';

    public function __construct()
    {
        parent::__construct('Real.discount', 'https://www.real.discount/courses');
        $this->strictPrice = true;
    }

    protected function listingUrls(int $maxPages): array
    {
        $urls = [];
        for ($page = 1; $page <= max(1, $maxPages); $page++) {
            $urls[] = self::API_BASE . '?' . http_build_query([
                'limit' => 40,
                'page' => $page,
                'sortBy' => 'sale_start',
                'sortOrder' => 'desc',
            ]);
        }
        return $urls;
    }

    protected function parseListing(string $json, string $listingUrl): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $items = [];
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = (string) ($item['url'] ?? '');
            if (!is_safe_url($url) || canonical_udemy_key($url) === null) {
                continue;
            }
            $items[] = ['url' => $url, 'title' => (string) ($item['name'] ?? '')];
        }
        return $items;
    }

    protected function parseDetail(string $html, string $detailUrl, string $listingUrl): array
    {
        return [];
    }

    public function collect(int $maxPages = 3, int $maxCourses = 40, ?float $deadline = null, array $knownSourceUrls = []): array
    {
        $result = ['courses' => [], 'errors' => [], 'listings' => 0, 'detail_requests' => 0, 'skipped' => 0];
        $listingPages = array_slice($this->listingUrls($maxPages), 0, max(1, $maxPages));
        $timeUp = static fn (): bool => $deadline !== null && microtime(true) >= $deadline;
        $budgetReached = false;

        foreach ($listingPages as $listingUrl) {
            if (count($result['courses']) >= $maxCourses) {
                break;
            }
            if ($timeUp()) {
                $budgetReached = true;
                break;
            }
            if (!$this->robots->allows($listingUrl)) {
                $result['errors'][] = 'robots.txt disallows the Real.discount feed: ' . $listingUrl;
                continue;
            }

            $response = $this->http->getWithRetry($listingUrl, 2, [
                'headers' => ['Accept: application/json'],
                'timeout' => HTTP_TIMEOUT,
            ]);
            if (!$response['ok'] || (int) $response['status'] >= 400) {
                $result['errors'][] = 'Feed request failed [' . (int) $response['status'] . '] ' . $listingUrl . ($response['error'] !== '' ? ' — ' . $response['error'] : '');
                continue;
            }

            $payload = json_decode((string) $response['body'], true);
            if (!is_array($payload) || !is_array($payload['items'] ?? null)) {
                $result['errors'][] = 'The Real.discount feed returned an unexpected payload.';
                continue;
            }
            $result['listings']++;

            foreach ($payload['items'] as $item) {
                if (count($result['courses']) >= $maxCourses) {
                    break;
                }
                if ($timeUp()) {
                    $budgetReached = true;
                    break 2;
                }
                if (!is_array($item)) {
                    continue;
                }

                $id = trim((string) ($item['id'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));
                if ($id === '' || str_starts_with($id, 'ad-') || !is_safe_url($url) || canonical_udemy_key($url) === null) {
                    continue;
                }
                if (strcasecmp((string) ($item['store'] ?? ''), 'Udemy') !== 0) {
                    continue;
                }

                $salePrice = $item['sale_price'] ?? null;
                if (is_numeric($salePrice) && (float) $salePrice > 0) {
                    $result['skipped']++;
                    continue;
                }
                $couponPrice = is_numeric($salePrice) ? (float) $salePrice : null;
                $priceStatus = $couponPrice === null ? 'unknown' : ($couponPrice <= 0 ? 'free' : 'paid');

                $sourceUrl = self::OFFER_BASE . rawurlencode($id);
                if (isset($knownSourceUrls[$sourceUrl])) {
                    continue;
                }

                $content = [];
                if (!empty($item['content']) && is_string($item['content'])) {
                    $decoded = json_decode($item['content'], true);
                    if (is_array($decoded)) {
                        $content = $decoded;
                    }
                }

                $instructor = null;
                if (isset($content['instructor']['name'])) {
                    $instructor = (string) $content['instructor']['name'];
                }

                $students = null;
                if (isset($content['students']) && is_numeric($content['students'])) {
                    $students = (int) $content['students'];
                }

                $duration = null;
                if (isset($item['lectures']) && is_numeric($item['lectures']) && (float) $item['lectures'] > 0) {
                    $duration = rtrim(rtrim(number_format((float) $item['lectures'], 2, '.', ''), '0'), '.') . ' hours';
                }

                $rating = null;
                if (isset($item['rating']) && is_numeric($item['rating']) && (float) $item['rating'] > 0) {
                    $rating = (float) $item['rating'];
                }

                $originalPrice = null;
                if (isset($item['price']) && is_numeric($item['price']) && (float) $item['price'] > 0) {
                    $originalPrice = (float) $item['price'];
                }

                $expiry = null;
                foreach (['sale_end', 'end_date', 'expiry'] as $expiryKey) {
                    if (!empty($item[$expiryKey])) {
                        $timestamp = strtotime((string) $item[$expiryKey]);
                        if ($timestamp !== false) {
                            $expiry = date('Y-m-d H:i:s', $timestamp);
                            break;
                        }
                    }
                }

                $data = [
                    'title' => (string) ($item['name'] ?? ''),
                    'description' => $this->clean((string) ($item['description'] ?? '')),
                    'thumbnail_url' => (string) ($item['image'] ?? ''),
                    'instructor' => $instructor,
                    'category' => (string) ($item['category'] ?? $item['subcategory'] ?? ''),
                    'rating' => $rating,
                    'review_count' => null,
                    'student_count' => $students,
                    'language' => (string) ($item['language'] ?? ''),
                    'level' => null,
                    'duration' => $duration,
                    'original_price' => $originalPrice,
                    'coupon_price' => $couponPrice,
                    'price_status' => $priceStatus,
                    'currency' => 'USD',
                    'udemy_url' => $this->stripQuery($url),
                    'coupon_url' => $url,
                    'expiry_date' => $expiry,
                    'strict_price' => true,
                ];

                $normalized = $this->normalize($data, $sourceUrl, $listingUrl);
                if ($normalized === null) {
                    $result['errors'][] = 'Could not extract a valid Udemy course from Real.discount offer ' . $id . '.';
                    continue;
                }
                $result['courses'][] = $normalized;
            }
        }

        if ($budgetReached) {
            $result['errors'][] = 'Time budget reached; the remaining Real.discount offers will be collected on the next run.';
        }
        $result['errors'] = array_slice($result['errors'], 0, 60);
        return $result;
    }

    public function recheckPrice(array $course, array $source): array
    {
        $id = $this->extractOfferId((string) ($source['source_course_url'] ?? $course['source_course_url'] ?? ''));
        $canonical = (string) ($course['canonical_key'] ?? '');
        $title = trim((string) ($course['title'] ?? ''));

        $queries = [];
        if ($title !== '') {
            $words = preg_split('/\s+/', $title) ?: [];
            $queries[] = implode(' ', array_slice($words, 0, 6));
            $queries[] = $title;
        }
        if ($canonical !== '' && str_starts_with($canonical, 'udemy:')) {
            $queries[] = substr($canonical, 6);
        }

        foreach (array_unique(array_filter($queries)) as $query) {
            foreach ($this->search($query) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = (string) ($item['id'] ?? '');
                $itemUrl = (string) ($item['url'] ?? '');
                $matches = ($id !== '' && $itemId === $id)
                    || ($canonical !== '' && canonical_udemy_key($itemUrl) === $canonical);
                if ($matches) {
                    return $this->priceStateFromItem($item);
                }
            }
        }

        return ['state' => 'unknown', 'message' => 'The offer was not found in the current Real.discount feed.'];
    }

    private function search(string $query): array
    {
        $url = self::API_BASE . '?' . http_build_query([
            'search' => $query,
            'limit' => 20,
            'sortBy' => 'sale_start',
            'sortOrder' => 'desc',
        ]);
        if (!$this->robots->allows($url)) {
            return [];
        }
        $response = $this->http->getWithRetry($url, 2, [
            'headers' => ['Accept: application/json'],
            'timeout' => HTTP_TIMEOUT,
        ]);
        if (!$response['ok'] || (int) $response['status'] >= 400) {
            return [];
        }
        $payload = json_decode((string) $response['body'], true);
        return is_array($payload) && is_array($payload['items'] ?? null) ? $payload['items'] : [];
    }

    private function priceStateFromItem(array $item): array
    {
        $salePrice = $item['sale_price'] ?? null;
        if (!is_numeric($salePrice)) {
            return ['state' => 'unknown', 'message' => 'Real.discount did not expose a current sale price for this offer.'];
        }
        $price = (float) $salePrice;
        return [
            'state' => $price <= 0 ? 'free' : 'paid',
            'price' => $price,
            'message' => $price <= 0
                ? 'Real.discount reports the current price as free.'
                : 'Real.discount reports the current price as ' . number_format($price, 2) . ' USD.',
        ];
    }

    private function extractOfferId(string $url): string
    {
        if (preg_match('#/offer/([A-Za-z0-9\-_]+)#', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
