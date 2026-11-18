<?php
declare(strict_types=1);

/**
 * Collects courses from Udemy's public free-course catalogue. Only offers whose
 * current enrolment price can be confirmed as zero are accepted.
 */
class UdemyFreeAdapter extends BaseAdapter
{
    public function __construct()
    {
        parent::__construct('Udemy Free Courses', 'https://www.udemy.com/courses/free/');
        $this->strictPrice = true;
    }

    /**
     * The public /courses/free/ page is rendered client-side, so its HTML does
     * not contain course links. Udemy's own discovery API backs that page and
     * exposes the same price-free catalogue as JSON, which is what we collect.
     */
    public function collect(int $maxPages = 3, int $maxCourses = 40, ?float $deadline = null, array $knownSourceUrls = []): array
    {
        $result = ['courses' => [], 'errors' => [], 'listings' => 0, 'detail_requests' => 0, 'skipped' => 0];
        $maxPages = max(1, $maxPages);
        $maxCourses = max(1, $maxCourses);
        $pageSize = min(50, $maxCourses);
        $timeUp = static fn (): bool => $deadline !== null && microtime(true) >= $deadline;
        $budgetReached = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            if (count($result['courses']) >= $maxCourses) {
                break;
            }
            if ($timeUp()) {
                $budgetReached = true;
                break;
            }

            $url = $this->discoveryUrl($page, $pageSize);
            if (!$this->robots->allows($url)) {
                $result['errors'][] = 'robots.txt disallows the Udemy discovery API: ' . $url;
                break;
            }

            $response = $this->http->getWithRetry($url, 2, ['timeout' => HTTP_TIMEOUT, 'headers' => ['Accept: application/json']]);
            $result['listings']++;
            $result['detail_requests']++;
            if (!$response['ok'] || (int) $response['status'] >= 400) {
                $result['errors'][] = 'Udemy discovery request failed [' . $response['status'] . '] ' . ($response['error'] ?: 'network error');
                break;
            }

            $data = json_decode((string) $response['body'], true);
            if (!is_array($data) || !isset($data['unit']['items']) || !is_array($data['unit']['items'])) {
                $result['errors'][] = 'Udemy discovery response was not valid JSON.';
                break;
            }

            $items = $data['unit']['items'];
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (count($result['courses']) >= $maxCourses) {
                    break;
                }
                if ($timeUp()) {
                    $budgetReached = true;
                    break 2;
                }
                if (!is_array($item) || ($item['is_paid'] ?? null) !== false) {
                    $result['skipped']++;
                    continue;
                }

                $udemyUrl = $this->apiItemUrl($item);
                if ($udemyUrl === '' || canonical_udemy_key($udemyUrl) === null) {
                    $result['skipped']++;
                    continue;
                }
                if (isset($knownSourceUrls[$udemyUrl])) {
                    continue;
                }

                $normalized = $this->normalizeFromApiItem($item, $udemyUrl, $url);
                if ($normalized === null) {
                    $result['errors'][] = 'Could not normalize Udemy course: ' . $udemyUrl;
                    continue;
                }
                $result['courses'][] = $normalized;
            }
        }

        if ($budgetReached) {
            $result['errors'][] = 'Time budget reached; the remaining offers will be collected on the next run.';
        }
        $result['errors'] = array_slice($result['errors'], 0, 60);
        return $result;
    }

    protected function listingUrls(int $maxPages): array
    {
        $urls = ['https://www.udemy.com/courses/free/'];
        for ($page = 2; $page <= max(1, $maxPages); $page++) {
            $urls[] = 'https://www.udemy.com/courses/free/?page=' . $page;
        }
        return $urls;
    }

    protected function parseListing(string $html, string $listingUrl): array
    {
        $xpath = $this->xpath($html);
        $items = [];
        $seen = [];

        $anchors = $xpath->query('//a[@href]');
        if (!$anchors) {
            return $items;
        }

        foreach ($anchors as $anchor) {
            $url = $this->absoluteUrl($listingUrl, (string) $anchor->getAttribute('href'));
            if ($url === '' || !is_safe_url($url)) {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== 'udemy.com' && !str_ends_with($host, '.udemy.com')) {
                continue;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (!preg_match('#^/course/[a-z0-9\-_]+/?$#i', $path)) {
                continue;
            }
            $canonical = canonical_udemy_key($url);
            if ($canonical === null) {
                continue;
            }

            $cleanUrl = $this->stripQuery($url);
            if (isset($seen[$cleanUrl])) {
                continue;
            }

            $title = $this->clean($anchor->textContent ?? '');
            if ($title === '' || mb_strlen($title) < 5) {
                $title = trim((string) $anchor->getAttribute('aria-label'));
            }
            if ($title === '') {
                $images = $xpath->query('.//img[@alt]', $anchor);
                if ($images && $images->length > 0) {
                    $title = $this->clean($images->item(0)->getAttribute('alt'));
                }
            }
            if ($title === '' || mb_strlen($title) < 5) {
                $headings = $xpath->query('ancestor::*[self::div or self::article or self::li][1]//h2 | ancestor::*[self::div or self::article or self::li][1]//h3', $anchor);
                if ($headings && $headings->length > 0) {
                    $title = $this->clean($headings->item(0)->textContent ?? '');
                }
            }

            $seen[$cleanUrl] = true;
            $items[] = ['url' => $cleanUrl, 'title' => $title];
        }

        return $items;
    }

    protected function parseDetail(string $html, string $detailUrl, string $listingUrl): array
    {
        $data = $this->extractCommonDetail($html, $detailUrl);
        $data['currency'] = 'USD';

        $price = $this->extractCoursePrice($html);
        $data['coupon_price'] = $price;
        $data['price_status'] = $price === null ? 'unknown' : ($price <= 0 ? 'free' : 'paid');

        if (canonical_udemy_key($detailUrl) !== null) {
            $data['udemy_url'] = $this->stripQuery($detailUrl);
            $data['coupon_url'] = $detailUrl;
        }

        return $data;
    }

    public function recheckPrice(array $course, array $source): array
    {
        $url = trim((string) ($course['coupon_url'] ?? ''));
        if ($url === '' || canonical_udemy_key($url) === null) {
            $url = trim((string) ($course['udemy_url'] ?? ''));
        }
        if ($url === '' || canonical_udemy_key($url) === null) {
            return ['state' => 'unknown', 'message' => 'No valid Udemy course URL is stored for this offer.'];
        }

        $identifier = $this->courseIdentifier($url);
        if ($identifier === '') {
            return ['state' => 'unknown', 'message' => 'No valid Udemy course identifier is stored for this offer.'];
        }

        $apiUrl = 'https://www.udemy.com/api-2.0/courses/' . rawurlencode($identifier)
            . '/?fields%5Bcourse%5D=' . rawurlencode('id,title,price,is_paid,price_detail');
        if (!$this->robots->allows($apiUrl)) {
            return ['state' => 'unknown', 'message' => 'Udemy robots.txt does not permit automated access to the price API.'];
        }

        $response = $this->http->getWithRetry($apiUrl, 2, ['timeout' => HTTP_TIMEOUT, 'headers' => ['Accept: application/json']]);
        $status = (int) $response['status'];
        if ($status === 404 || $status === 410) {
            return ['state' => 'unavailable', 'message' => 'The Udemy course price API returned HTTP ' . $status . '.'];
        }
        if (!$response['ok'] || $status === 0) {
            return ['state' => 'unknown', 'message' => 'The Udemy course price API could not be reached (' . ($response['error'] ?: 'network error') . ').'];
        }
        if (in_array($status, [401, 403, 429], true)) {
            return ['state' => 'unknown', 'message' => 'The Udemy course price API refused automated verification (HTTP ' . $status . '). Not bypassed.'];
        }
        if ($status >= 500) {
            return ['state' => 'unknown', 'message' => 'The Udemy course price API returned a server error (HTTP ' . $status . ').'];
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data)) {
            return ['state' => 'unknown', 'message' => 'The Udemy course price API returned an invalid response.'];
        }

        $isPaid = $data['is_paid'] ?? null;
        if ($isPaid === false) {
            return [
                'state' => 'free',
                'price' => 0.0,
                'message' => 'Udemy reports the current enrolment price as free.',
            ];
        }
        if ($isPaid === true) {
            $amount = null;
            $display = '';
            $detail = $data['price_detail'] ?? null;
            if (is_array($detail)) {
                if (isset($detail['amount']) && is_numeric($detail['amount'])) {
                    $amount = (float) $detail['amount'];
                }
                $display = $this->clean((string) ($detail['price_string'] ?? ''));
            }
            if ($display === '') {
                $display = $this->clean((string) ($data['price'] ?? ''));
            }
            return [
                'state' => 'paid',
                'price' => $amount,
                'message' => 'Udemy reports the current enrolment price as ' . ($display !== '' ? $display : 'above zero') . '.',
            ];
        }

        $price = $data['price'] ?? null;
        if (is_string($price) && preg_match('/\bfree\b/i', $price)) {
            return ['state' => 'free', 'price' => 0.0, 'message' => 'Udemy reports the current enrolment price as free.'];
        }
        if (is_numeric($price)) {
            $amount = (float) $price;
            return [
                'state' => $amount <= 0 ? 'free' : 'paid',
                'price' => $amount,
                'message' => $amount <= 0
                    ? 'Udemy reports the current enrolment price as free.'
                    : 'Udemy reports the current enrolment price as ' . number_format($amount, 2) . '.',
            ];
        }

        return ['state' => 'unknown', 'message' => 'The current enrolment price could not be determined from the Udemy price API.'];
    }

    private function discoveryUrl(int $page, int $pageSize): string
    {
        return 'https://www.udemy.com/api-2.0/discovery-units/all_courses/?' . http_build_query([
            'context' => 'category',
            'category_id' => 283,
            'source_page' => 'category_page',
            'fl' => 'cat',
            'sos' => 'pc',
            'skip_price' => 'true',
            'price' => 'price-free',
            'p' => $page,
            'page_size' => $pageSize,
            'locale' => 'en_US',
            'navigation_locale' => 'en_US',
            'currency' => 'usd',
        ]);
    }

    private function apiItemUrl(array $item): string
    {
        $path = trim((string) ($item['url'] ?? ''));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            $url = $path;
        } else {
            $url = 'https://www.udemy.com/' . ltrim($path, '/');
        }
        return $this->stripQuery($url);
    }

    private function normalizeFromApiItem(array $item, string $udemyUrl, string $listingUrl): ?array
    {
        $title = $this->clean((string) ($item['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $description = $this->clean((string) ($item['headline'] ?? ''));
        $objectives = $item['objectives_summary'] ?? [];
        if (is_array($objectives) && $objectives !== []) {
            $points = [];
            foreach ($objectives as $objective) {
                $objective = $this->clean((string) $objective);
                if ($objective !== '') {
                    $points[] = '• ' . $objective;
                }
            }
            if ($points !== []) {
                $description = trim($description . "\n\n" . implode("\n", $points));
            }
        }

        $instructor = '';
        $instructors = $item['visible_instructors'] ?? [];
        if (is_array($instructors)) {
            foreach ($instructors as $person) {
                if (!is_array($person)) {
                    continue;
                }
                $name = $this->clean((string) ($person['display_name'] ?? $person['title'] ?? ''));
                if ($name !== '') {
                    $instructor = $name;
                    break;
                }
            }
        }

        $category = '';
        $context = $item['context_info'] ?? [];
        if (is_array($context)) {
            $categoryNode = $context['category'] ?? null;
            if (is_array($categoryNode)) {
                $category = $this->clean((string) ($categoryNode['title'] ?? ''));
            }
            if ($category === '') {
                $label = $context['label'] ?? null;
                if (is_array($label)) {
                    $category = $this->clean((string) ($label['display_name'] ?? $label['title'] ?? ''));
                }
            }
        }

        $language = '';
        $locale = $item['locale'] ?? null;
        if (is_array($locale)) {
            $language = $this->clean((string) ($locale['english_title'] ?? $locale['title'] ?? ''));
        }

        $rating = $item['rating'] ?? $item['avg_rating_recent'] ?? $item['avg_rating'] ?? null;

        $data = [
            'title' => $title,
            'description' => $description,
            'thumbnail_url' => (string) ($item['image_480x270'] ?? $item['image_750x422'] ?? ''),
            'instructor' => $instructor,
            'category' => $category,
            'rating' => is_numeric($rating) ? (float) $rating : null,
            'review_count' => isset($item['num_reviews']) ? (int) $item['num_reviews'] : null,
            'student_count' => isset($item['num_subscribers']) ? (int) $item['num_subscribers'] : null,
            'language' => $language,
            'level' => $this->clean((string) ($item['instructional_level'] ?? '')),
            'duration' => $this->clean((string) ($item['content_info_short'] ?? $item['content_info'] ?? '')),
            'original_price' => null,
            'coupon_price' => 0.0,
            'price_status' => 'free',
            'strict_price' => true,
            'currency' => 'USD',
            'udemy_url' => $udemyUrl,
            'coupon_url' => $udemyUrl,
            'expiry_date' => null,
        ];

        return $this->normalize($data, $udemyUrl, $listingUrl);
    }

    private function courseIdentifier(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/course/([a-z0-9\-_]+)#i', $path, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function extractCoursePrice(string $html): ?float
    {
        $xpath = $this->xpath($html);

        $priceQueries = [
            '//*[@data-purpose="course-price-text"]',
            '//*[contains(@class,"price-text--price-part")]',
            '//*[contains(@class,"ud-price-text")]',
            '//*[contains(@class,"clp-lead__price")]',
            '//*[contains(@class,"price--price-text")]',
            '//*[contains(@class,"price-text")]',
        ];
        $prices = [];
        foreach ($priceQueries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                $price = $this->parseStrictPriceText($this->clean($node->textContent ?? ''));
                if ($price !== null) {
                    $prices[] = $price;
                }
            }
        }
        if ($prices !== []) {
            return min($prices);
        }

        foreach (['product:price:amount', 'product:sale_price:amount', 'og:price:amount'] as $property) {
            $price = $this->normalizePriceValue($this->meta($xpath, $property));
            if ($price !== null) {
                return $price;
            }
        }

        return $this->extractJsonLdPrice($xpath);
    }

    private function extractJsonLdPrice(DOMXPath $xpath): ?float
    {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if (!$scripts) {
            return null;
        }
        foreach ($scripts as $script) {
            $raw = trim((string) $script->textContent);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $price = $this->findOfferPrice($decoded);
            if ($price !== null) {
                return $price;
            }
        }
        return null;
    }

    private function findOfferPrice(array $node): ?float
    {
        $type = $node['@type'] ?? '';
        if (is_array($type)) {
            $type = implode(' ', $type);
        }
        $isOffer = stripos((string) $type, 'Offer') !== false
            || stripos((string) $type, 'Product') !== false
            || stripos((string) $type, 'Course') !== false;

        if ($isOffer) {
            foreach (['price', 'lowPrice', 'salePrice'] as $key) {
                if (array_key_exists($key, $node)) {
                    $price = $this->normalizePriceValue($node[$key]);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }
            if (isset($node['offers'])) {
                $offers = is_array($node['offers']) ? $node['offers'] : [$node['offers']];
                foreach ($offers as $offer) {
                    if (!is_array($offer)) {
                        continue;
                    }
                    foreach (['price', 'lowPrice', 'salePrice'] as $key) {
                        if (array_key_exists($key, $offer)) {
                            $price = $this->normalizePriceValue($offer[$key]);
                            if ($price !== null) {
                                return $price;
                            }
                        }
                    }
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $price = $this->findOfferPrice($value);
                if ($price !== null) {
                    return $price;
                }
            }
        }
        return null;
    }

    private function normalizePriceValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\bfree\b/i', $value)) {
            return 0.0;
        }
        if (preg_match('/^([0-9][0-9,]*(?:\.[0-9]{1,2})?)$/', $value, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }
        return null;
    }

    private function parseStrictPriceText(string $text): ?float
    {
        if ($text === '') {
            return null;
        }

        $amounts = [];
        if (preg_match_all('/(?:US\$|\$|USD|€|£|₦|NGN)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $matches)) {
            foreach ($matches[1] as $amount) {
                $amounts[] = (float) str_replace(',', '', $amount);
            }
        }
        if (preg_match_all('/([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:USD|US\$)/i', $text, $matches)) {
            foreach ($matches[1] as $amount) {
                $amounts[] = (float) str_replace(',', '', $amount);
            }
        }

        if ($amounts !== []) {
            return min($amounts);
        }
        if (preg_match('/\bfree\b/i', $text)) {
            return 0.0;
        }
        if (preg_match('/^([0-9][0-9,]*(?:\.[0-9]{1,2})?)$/', trim($text), $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }
        return null;
    }
}
