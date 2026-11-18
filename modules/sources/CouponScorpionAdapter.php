<?php
declare(strict_types=1);

class CouponScorpionAdapter extends BaseAdapter
{
    public function __construct()
    {
        parent::__construct('CouponsCorpion', 'https://couponscorpion.com/');
    }

    protected function listingUrls(int $maxPages): array
    {
        $urls = [
            'https://couponscorpion.com/',
            'https://couponscorpion.com/category/free100-discount/',
            'https://couponscorpion.com/category/100-off-coupons/',
        ];
        for ($page = 2; $page <= max(1, $maxPages); $page++) {
            $urls[] = 'https://couponscorpion.com/page/' . $page . '/';
        }
        return array_slice($urls, 0, max(1, $maxPages));
    }

    protected function parseListing(string $html, string $listingUrl): array
    {
        $xpath = $this->xpath($html);
        $items = [];
        $seen = [];

        $anchors = $xpath->query('//article//a[@href]');
        if (!$anchors || $anchors->length === 0) {
            $anchors = $xpath->query('//h2//a[@href] | //h3//a[@href]');
        }
        if (!$anchors || $anchors->length === 0) {
            $anchors = $xpath->query('//a[@href]');
        }

        if ($anchors) {
            foreach ($anchors as $anchor) {
                $href = (string) $anchor->getAttribute('href');
                $url = $this->absoluteUrl($listingUrl, $href);
                if ($url === '' || !is_safe_url($url)) {
                    continue;
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if ($host !== 'couponscorpion.com' && !str_ends_with($host, '.couponscorpion.com')) {
                    continue;
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path === '' || $path === '/' || preg_match('#^/(category|tag|author|page|wp-|feed|blog|contact|privacy|terms|about|dmca|sitemap)#i', $path)) {
                    continue;
                }
                if (!preg_match('#^/[^/]+/[^/]+/?$#', $path)) {
                    continue;
                }
                if (isset($seen[$url])) {
                    continue;
                }
                $title = $this->clean($anchor->textContent ?? '');
                if (mb_strlen($title) < 10 || preg_match('/^(read more|continue reading|home|next|previous)$/i', $title)) {
                    continue;
                }
                $seen[$url] = true;
                $items[] = ['url' => $url, 'title' => $title];
            }
        }

        return $items;
    }

    protected function parseDetail(string $html, string $detailUrl, string $listingUrl): array
    {
        $data = $this->extractCommonDetail($html, $detailUrl);
        $data['currency'] = 'USD';

        $category = trim((string) ($data['category'] ?? ''));
        if ($category === '' || strcasecmp($category, 'Home') === 0) {
            $segment = strtolower((string) strtok(trim((string) parse_url($detailUrl, PHP_URL_PATH), '/'), '/'));
            $map = [
                'development' => 'Development',
                'business' => 'Business',
                'design' => 'Design',
                'marketing' => 'Marketing',
                'it-software' => 'IT & Software',
                'personal-development' => 'Personal Development',
                'photography-video' => 'Photography',
                'music' => 'Music',
                'health-fitness' => 'Health & Fitness',
                'finance-accounting' => 'Finance & Accounting',
                'office-productivity' => 'Office Productivity',
                'teaching-academics' => 'Teaching & Academics',
            ];
            if (isset($map[$segment])) {
                $data['category'] = $map[$segment];
            }
        }

        return $data;
    }
}
