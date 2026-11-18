<?php
declare(strict_types=1);

abstract class BaseAdapter
{
    protected HttpClient $http;
    protected RobotsTxt $robots;
    public string $name;
    public string $baseUrl;
    protected bool $strictPrice = false;

    public function __construct(string $name, string $baseUrl)
    {
        $this->name = $name;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->http = new HttpClient(HTTP_TIMEOUT);
        $this->robots = new RobotsTxt(new HttpClient(12));
    }

    public function requiresExactFreePrice(): bool
    {
        return $this->strictPrice;
    }

    /**
     * Re-checks the current enrollment price for a stored course. Adapters that
     * opt into strict price validation override this method.
     *
     * @return array{state: string, message: string, price?: float}
     */
    public function recheckPrice(array $course, array $source): array
    {
        return ['state' => 'unknown', 'message' => 'This source does not support automated price rechecks.'];
    }

    abstract protected function listingUrls(int $maxPages): array;

    abstract protected function parseListing(string $html, string $listingUrl): array;

    abstract protected function parseDetail(string $html, string $detailUrl, string $listingUrl): array;

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
                $result['errors'][] = 'robots.txt disallows listing page: ' . $listingUrl;
                continue;
            }
            $listing = $this->http->getWithRetry($listingUrl, 2);
            if (!$listing['ok'] || $listing['status'] >= 400) {
                $result['errors'][] = 'Listing request failed [' . $listing['status'] . '] ' . $listingUrl . ($listing['error'] !== '' ? ' — ' . $listing['error'] : '');
                continue;
            }
            $result['listings']++;
            $items = $this->parseListing($listing['body'], $listingUrl);
            $seen = [];

            foreach ($items as $item) {
                if (count($result['courses']) >= $maxCourses) {
                    break;
                }
                if ($timeUp()) {
                    $budgetReached = true;
                    break 2;
                }
                $detailUrl = (string) ($item['url'] ?? '');
                if ($detailUrl === '' || isset($seen[$detailUrl])) {
                    continue;
                }
                $seen[$detailUrl] = true;
                if (isset($knownSourceUrls[$detailUrl])) {
                    continue;
                }
                if (!$this->robots->allows($detailUrl)) {
                    $result['errors'][] = 'robots.txt disallows detail page: ' . $detailUrl;
                    continue;
                }
                usleep(250000);
                $detail = $this->http->getWithRetry($detailUrl, 2);
                $result['detail_requests']++;
                if (!$detail['ok'] || $detail['status'] >= 400) {
                    $result['errors'][] = 'Detail request failed [' . $detail['status'] . '] ' . $detailUrl . ($detail['error'] !== '' ? ' — ' . $detail['error'] : '');
                    continue;
                }
                $data = $this->parseDetail($detail['body'], $detailUrl, $listingUrl);
                $data = $this->resolveEnrollment($data, $detail['body'], $detailUrl);

                // Only free offers are collected. A "Deal" with a price above
                // zero is not a 100%-off course and must not enter the catalog.
                $couponPrice = $data['coupon_price'] ?? null;
                if ($couponPrice !== null && $couponPrice !== '' && (float) $couponPrice > 0) {
                    $result['skipped']++;
                    continue;
                }

                $normalized = $this->normalize($data, $detailUrl, $listingUrl);
                if ($normalized === null) {
                    $result['errors'][] = 'Could not extract a valid Udemy course from: ' . $detailUrl;
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

    protected function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($dom);
    }

    protected function text(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        if (!$nodes || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        return $this->clean($node->textContent ?? '');
    }

    protected function attribute(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        if (!$nodes || $nodes->length === 0) {
            return '';
        }
        return trim((string) $nodes->item(0)->nodeValue);
    }

    protected function meta(DOMXPath $xpath, string $property): string
    {
        $value = $this->attribute($xpath, '//meta[@property="' . $property . '"]/@content');
        if ($value === '') {
            $value = $this->attribute($xpath, '//meta[@name="' . $property . '"]/@content');
        }
        return $value;
    }

    protected function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    protected function absoluteUrl(string $base, string $link): string
    {
        $link = trim(html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($link === '' || str_starts_with($link, 'data:') || str_starts_with($link, 'javascript:')) {
            return '';
        }
        if (str_starts_with($link, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $link;
        }
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        $parts = parse_url($base);
        if (!$parts || empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($link, '/')) {
            return $origin . $link;
        }
        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        return $origin . $directory . $link;
    }

    protected function findUdemyUrl(string $html, DOMXPath $xpath): string
    {
        $anchors = $xpath->query('//a[@href]');
        if ($anchors) {
            foreach ($anchors as $anchor) {
                $href = (string) $anchor->getAttribute('href');
                if (stripos($href, 'udemy.com/course') !== false) {
                    $absolute = $this->absoluteUrl($this->baseUrl, $href);
                    if (canonical_udemy_key($absolute) !== null) {
                        return $absolute;
                    }
                }
            }
        }
        if (preg_match('#https?://[^\s"\'<>]*udemy\.com/course/[^\s"\'<>]+#i', $html, $matches)) {
            $url = html_entity_decode($matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (is_safe_url($url)) {
                return $url;
            }
        }
        return '';
    }

    /**
     * Resolves the source's own enrollment redirect to the real Udemy course
     * URL. The redirect is followed normally; restrictions are respected.
     */
    protected function resolveEnrollment(array $data, string $html, string $detailUrl): array
    {
        $udemyUrl = trim((string) ($data['udemy_url'] ?? ''));
        $couponUrl = trim((string) ($data['coupon_url'] ?? ''));

        if ($udemyUrl === '' && $couponUrl !== '' && canonical_udemy_key($couponUrl) !== null) {
            $udemyUrl = $couponUrl;
        }

        if ($udemyUrl !== '') {
            $data['udemy_url'] = $this->stripQuery($udemyUrl);
            if ($couponUrl === '' || canonical_udemy_key($couponUrl) === null) {
                $data['coupon_url'] = $udemyUrl;
            }
            return $data;
        }

        $outbound = $this->findEnrollmentLink($html, $detailUrl);
        if ($outbound === '' || !$this->robots->allows($outbound)) {
            return $data;
        }

        usleep(250000);
        $response = $this->http->getWithRetry($outbound, 2, ['timeout' => HTTP_TIMEOUT]);
        if (!$response['ok'] || $response['status'] >= 400) {
            return $data;
        }

        $resolved = $this->extractFirstUdemyUrl((string) $response['url'] . "\n" . $response['body']);
        if ($resolved === '') {
            return $data;
        }

        $data['udemy_url'] = $this->stripQuery($resolved);
        $data['coupon_url'] = $resolved;
        return $data;
    }

    protected function findEnrollmentLink(string $html, string $detailUrl): string
    {
        $xpath = $this->xpath($html);
        $anchors = $xpath->query('//a[@href]');
        if (!$anchors) {
            return '';
        }

        $best = '';
        $bestScore = 0;
        $detailHost = strtolower((string) parse_url($detailUrl, PHP_URL_HOST));

        foreach ($anchors as $anchor) {
            $href = $this->absoluteUrl($detailUrl, (string) $anchor->getAttribute('href'));
            if ($href === '' || !is_safe_url($href) || $href === $detailUrl) {
                continue;
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            $path = (string) parse_url($href, PHP_URL_PATH);
            $text = $this->clean($anchor->textContent ?? '');
            $class = strtolower((string) $anchor->getAttribute('class'));

            $score = 0;
            if (preg_match('/\b(enroll|enrol|go to course|get course|claim|download|grab|get this course)\b/i', $text)) {
                $score += 5;
            }
            if (str_contains($class, 'discbtn') || str_contains($class, 're_track_btn') || str_contains($class, 'btn_offer')) {
                $score += 4;
            }
            if (preg_match('#/(go|out|enroll)/#i', $path) || str_contains($path, 'out.php')) {
                $score += 4;
            }
            if ($host !== $detailHost && (str_contains($host, 'coupon') || str_contains($host, 'udemy'))) {
                $score += 2;
            }
            if ($score <= $bestScore) {
                continue;
            }
            $bestScore = $score;
            $best = $href;
        }

        return $bestScore >= 4 ? $best : '';
    }

    protected function extractFirstUdemyUrl(string $haystack): string
    {
        if (!preg_match('#https?://[^\s"\'<>]*udemy\.com/course/[^\s"\'<>]*#i', $haystack, $matches)) {
            return '';
        }
        $url = html_entity_decode(trim($matches[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = rtrim(trim($url), ".,;)]}'\"");
        return is_safe_url($url) && canonical_udemy_key($url) !== null ? $url : '';
    }

    protected function stripQuery(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        return $scheme . '://' . $parts['host'] . ($parts['path'] ?? '/');
    }

    protected function findCouponUrl(string $html, DOMXPath $xpath, string $detailUrl, string $udemyUrl): string
    {
        $best = '';
        $bestScore = 0;
        $anchors = $xpath->query('//a[@href]');
        if ($anchors) {
            foreach ($anchors as $anchor) {
                $href = $this->absoluteUrl($detailUrl, (string) $anchor->getAttribute('href'));
                if ($href === '' || !is_safe_url($href) || $href === $detailUrl) {
                    continue;
                }
                $text = $this->clean($anchor->textContent ?? '');
                $score = 0;
                if (preg_match('/enroll|go to course|get course|claim|download|grab/i', $text)) {
                    $score += 4;
                }
                if (stripos($href, '/go/') !== false) {
                    $score += 4;
                }
                if (stripos($href, 'coupon') !== false) {
                    $score += 2;
                }
                if (stripos($href, 'udemy.com/course') !== false) {
                    $score += 3;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $href;
                }
            }
        }
        if ($best === '') {
            $best = $udemyUrl;
        }
        return $best;
    }

    protected function extractDescription(DOMXPath $xpath): string
    {
        $description = $this->meta($xpath, 'og:description');
        if ($description === '') {
            $description = $this->meta($xpath, 'description');
        }
        if ($description === '') {
            $paragraphs = $xpath->query('//p');
            if ($paragraphs) {
                foreach ($paragraphs as $paragraph) {
                    $text = $this->clean($paragraph->textContent ?? '');
                    if (mb_strlen($text) > 60) {
                        return $text;
                    }
                }
            }
        }
        return $description;
    }

    protected function extractRating(string $text): ?float
    {
        if (preg_match('/([0-5](?:\.\d)?)\s*(?:out of\s*5|stars?|rating)/i', $text, $m)) {
            return min(5.0, (float) $m[1]);
        }
        if (preg_match('/\brating[:\s]+([0-5](?:\.\d)?)/i', $text, $m)) {
            return min(5.0, (float) $m[1]);
        }
        return null;
    }

    protected function extractReviewCount(string $text): ?int
    {
        if (preg_match('/([\d,]+)\s*(?:reviews?|ratings?)/i', $text, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }
        return null;
    }

    protected function extractStudentCount(string $text): ?int
    {
        if (preg_match('/([\d,.]+)\s*(?:students?|learners?)/i', $text, $m)) {
            return (int) str_replace([',', '.'], '', $m[1]);
        }
        return null;
    }

    protected function extractLevel(string $text): ?string
    {
        if (preg_match('/\b(all levels|beginner|intermediate|expert|advanced)\b/i', $text, $m)) {
            return ucfirst(strtolower($m[1]));
        }
        return null;
    }

    protected function extractLanguage(string $text): ?string
    {
        if (preg_match('/language[:\s]+([A-Z][a-z]+(?:\s[A-Z][a-z]+)?)/i', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(English|Spanish|French|German|Portuguese|Italian|Hindi|Arabic|Chinese|Japanese|Russian|Turkish|Dutch|Polish)\b/', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function extractDuration(string $text): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(hours?|hrs?|minutes?|mins?)/i', $text, $m)) {
            return $m[1] . ' ' . strtolower($m[2]);
        }
        return null;
    }

    protected function extractInstructor(DOMXPath $xpath, string $text): ?string
    {
        $candidate = $this->text($xpath, '//*[contains(@class,"instructor")]//a');
        if ($candidate === '') {
            $candidate = $this->text($xpath, '//*[contains(@class,"author")]//a');
        }
        if ($candidate !== '' && mb_strlen($candidate) <= 120) {
            return $candidate;
        }
        if (preg_match('/instructor[:\s]+([A-Z][\w.\-]+(?:\s+[A-Z][\w.\-]+){0,3})/i', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function extractCategory(DOMXPath $xpath): ?string
    {
        $section = $this->meta($xpath, 'article:section');
        if ($section !== '') {
            return $section;
        }
        $candidate = $this->text($xpath, '//*[contains(@class,"breadcrumb")]//a[last()]');
        if ($candidate !== '' && mb_strlen($candidate) <= 120) {
            return $candidate;
        }
        return null;
    }

    protected function parsePriceText(string $text): ?float
    {
        $text = mb_strtolower($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/(100\s*%\s*off|free|₦\s*0(?:\.00)?|\$\s*0(?:\.00)?|ngn\s*0(?:\.00)?)/iu', $text)) {
            return 0.0;
        }
        if (preg_match('/(?:₦|ngn|usd|\$|€|£)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        return null;
    }

    protected function extractExpiry(string $text): ?string
    {
        $patterns = [
            '/(?:expires?|valid until|ends?)(?:\s*(?:on|at))?[:\s]+([A-Z][a-z]+ \d{1,2},? \d{4})/i',
            '/(?:expires?|valid until|ends?)(?:\s*(?:on|at))?[:\s]+(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i',
            '/(?:expires?|valid until|ends?)(?:\s*(?:on|at))?[:\s]+(\d{4}-\d{2}-\d{2})/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $timestamp = strtotime($m[1]);
                if ($timestamp !== false) {
                    return date('Y-m-d H:i:s', $timestamp);
                }
            }
        }
        return null;
    }

    protected function normalize(array $data, string $detailUrl, string $listingUrl): ?array
    {
        $title = $this->clean($data['title'] ?? '');
        if ($title === '') {
            return null;
        }
        $udemyUrl = (string) ($data['udemy_url'] ?? '');
        $canonical = canonical_udemy_key($udemyUrl);
        if ($canonical === null) {
            return null;
        }
        $couponUrl = (string) ($data['coupon_url'] ?? '');
        if ($couponUrl === '' || !is_safe_url($couponUrl)) {
            $couponUrl = $udemyUrl;
        }
        $description = $this->clean($data['description'] ?? '');
        if (mb_strlen($description) > 5000) {
            $description = mb_substr($description, 0, 5000);
        }
        $thumbnail = (string) ($data['thumbnail_url'] ?? '');
        if (!is_safe_url($thumbnail)) {
            $thumbnail = '';
        }
        $rating = $data['rating'] ?? null;
        if ($rating !== null) {
            $rating = max(0.0, min(5.0, (float) $rating));
        }

        $couponPrice = array_key_exists('coupon_price', $data) ? $data['coupon_price'] : null;
        $priceStatus = (string) ($data['price_status'] ?? '');
        if (!in_array($priceStatus, ['free', 'paid', 'unknown'], true)) {
            $priceStatus = $couponPrice === null || $couponPrice === '' ? 'unknown' : ((float) $couponPrice <= 0 ? 'free' : 'paid');
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'description' => $description !== '' ? $description : null,
            'thumbnail_url' => $thumbnail !== '' ? mb_substr($thumbnail, 0, 600) : null,
            'instructor' => ($instructor = $this->clean($data['instructor'] ?? '')) !== '' ? mb_substr($instructor, 0, 190) : null,
            'category' => ($category = $this->clean($data['category'] ?? '')) !== '' ? mb_substr($category, 0, 120) : null,
            'rating' => $rating,
            'review_count' => isset($data['review_count']) ? max(0, (int) $data['review_count']) : null,
            'student_count' => isset($data['student_count']) ? max(0, (int) $data['student_count']) : null,
            'language' => ($language = $this->clean($data['language'] ?? '')) !== '' ? mb_substr($language, 0, 80) : null,
            'level' => ($level = $this->clean($data['level'] ?? '')) !== '' ? mb_substr($level, 0, 80) : null,
            'duration' => ($duration = $this->clean($data['duration'] ?? '')) !== '' ? mb_substr($duration, 0, 80) : null,
            'original_price' => isset($data['original_price']) ? (float) $data['original_price'] : null,
            'coupon_price' => $couponPrice,
            'price_status' => $priceStatus,
            'strict_price' => (bool) ($data['strict_price'] ?? $this->requiresExactFreePrice()),
            'currency' => ($currency = strtoupper($this->clean($data['currency'] ?? ''))) !== '' ? mb_substr($currency, 0, 8) : 'USD',
            'udemy_url' => mb_substr($udemyUrl, 0, 600),
            'coupon_url' => mb_substr($couponUrl, 0, 600),
            'expiry_date' => $data['expiry_date'] ?? null,
            'canonical_key' => $canonical,
            'source_course_url' => mb_substr($detailUrl, 0, 600),
            'source_listing_url' => mb_substr($listingUrl, 0, 600),
            'source_name' => $this->name,
            'discovered_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function extractCommonDetail(string $html, string $detailUrl): array
    {
        $xpath = $this->xpath($html);
        $bodyText = $this->text($xpath, '//body');
        $title = $this->meta($xpath, 'og:title');
        if ($title === '') {
            $title = $this->text($xpath, '//h1');
        }
        if ($title === '') {
            $title = $this->text($xpath, '//title');
        }
        $title = preg_replace('/\s*[\-|–]\s*(DiscUdemy|CouponsCorpion|Udemy).*$/i', '', $title) ?? $title;
        $thumbnail = $this->meta($xpath, 'og:image');
        if ($thumbnail === '') {
            $thumbnail = $this->meta($xpath, 'twitter:image');
        }
        if ($thumbnail === '') {
            $images = $xpath->query('//img[@src]');
            if ($images) {
                foreach ($images as $image) {
                    $src = (string) $image->getAttribute('src');
                    if (stripos($src, 'udemycdn') !== false || stripos($src, 'udemy.com') !== false) {
                        $thumbnail = $this->absoluteUrl($detailUrl, $src);
                        break;
                    }
                }
            }
        }
        $udemyUrl = $this->findUdemyUrl($html, $xpath);
        $couponUrl = $this->findCouponUrl($html, $xpath, $detailUrl, $udemyUrl);
        $priceText = $this->text($xpath, '//*[contains(@class,"price")]');
        if ($priceText === '') {
            $priceText = $bodyText;
        }
        $couponPrice = $this->parsePriceText($this->clean($priceText));
        if ($couponPrice === null && preg_match('/100\s*%\s*off|free\s+course|enroll\s+for\s+free|free\s+enrollment/i', $bodyText)) {
            $couponPrice = 0.0;
        }
        $originalPrice = null;
        if (preg_match('/(?:original price|was|regular price)[:\s]+(?:₦|ngn|usd|\$|€|£)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $bodyText, $m)) {
            $originalPrice = (float) str_replace(',', '', $m[1]);
        }

        return [
            'title' => $title,
            'description' => $this->extractDescription($xpath),
            'thumbnail_url' => $thumbnail,
            'instructor' => $this->extractInstructor($xpath, $bodyText),
            'category' => $this->extractCategory($xpath),
            'rating' => $this->extractRating($bodyText),
            'review_count' => $this->extractReviewCount($bodyText),
            'student_count' => $this->extractStudentCount($bodyText),
            'language' => $this->extractLanguage($bodyText),
            'level' => $this->extractLevel($bodyText),
            'duration' => $this->extractDuration($bodyText),
            'original_price' => $originalPrice,
            'coupon_price' => $couponPrice,
            'currency' => 'USD',
            'udemy_url' => $udemyUrl,
            'coupon_url' => $couponUrl,
            'expiry_date' => $this->extractExpiry($bodyText),
        ];
    }
}
