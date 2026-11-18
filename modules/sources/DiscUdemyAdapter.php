<?php
declare(strict_types=1);

class DiscUdemyAdapter extends BaseAdapter
{
    public function __construct()
    {
        parent::__construct('DiscUdemy', 'https://www.discudemy.com/');
    }

    protected function listingUrls(int $maxPages): array
    {
        $urls = ['https://www.discudemy.com/all'];
        for ($page = 2; $page <= max(1, $maxPages); $page++) {
            $urls[] = 'https://www.discudemy.com/all?page=' . $page;
        }
        return $urls;
    }

    protected function parseListing(string $html, string $listingUrl): array
    {
        $xpath = $this->xpath($html);
        $items = [];
        $seen = [];

        $anchors = $xpath->query('//a[@href]');
        if ($anchors) {
            foreach ($anchors as $anchor) {
                $href = (string) $anchor->getAttribute('href');
                if (stripos($href, 'discudemy.com/course/') !== false) {
                    $url = $this->absoluteUrl($listingUrl, $href);
                } else {
                    $url = $this->absoluteUrl($listingUrl, $href);
                    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                    if ($host !== 'discudemy.com' && !str_ends_with($host, '.discudemy.com')) {
                        continue;
                    }
                    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                    if ($path === '' || str_contains($path, '/') || preg_match('/\.(png|jpe?g|svg|webp|ico|css|js|xml|txt)$/i', $path)) {
                        continue;
                    }
                    if (preg_match('/^(all|search|go|policies|policy|category|tag|blog|feed|giveaway|page|about|contact|privacy|terms|dmca|login|register|sitemap|wp-|language|languages|courses?|coupons?|store|deals?)$/i', $path)) {
                        continue;
                    }
                }
                if ($url === '' || !is_safe_url($url) || isset($seen[$url])) {
                    continue;
                }
                $url = preg_replace('/[?#].*$/', '', $url) ?? $url;
                $seen[$url] = true;
                $items[] = [
                    'url' => $url,
                    'title' => $this->clean($anchor->textContent ?? ''),
                ];
            }
        }

        return $items;
    }

    protected function parseDetail(string $html, string $detailUrl, string $listingUrl): array
    {
        $data = $this->extractCommonDetail($html, $detailUrl);
        $data['currency'] = 'USD';
        return $data;
    }
}
