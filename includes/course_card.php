<?php
$course = $course ?? null;
if (!$course) {
    return;
}
$title = course_display_title((string) ($course['title'] ?? 'Untitled course'));
$instructor = instructor_display_name($course['instructor'] ?? null);
$courseUrl = url('course.php?slug=' . urlencode((string) ($course['slug'] ?? '')));
$hasRating = isset($course['rating']) && (float) $course['rating'] > 0;
$price = $course['coupon_price'] !== null && $course['coupon_price'] !== '' ? (float) $course['coupon_price'] : null;
$original = $course['original_price'] !== null && $course['original_price'] !== '' ? (float) $course['original_price'] : null;
?>
<article class="course-card">
    <a class="course-thumb" href="<?= e($courseUrl) ?>">
        <div class="thumb-fallback"><?= e(initials($title)) ?></div>
        <?php if (!empty($course['thumbnail_url'])): ?>
            <img src="<?= e($course['thumbnail_url']) ?>" alt="<?= e($title) ?>" loading="lazy" onerror="this.remove()">
        <?php endif; ?>
        <?php if (!empty($course['level'])): ?>
            <span class="course-badge"><?= e($course['level']) ?></span>
        <?php endif; ?>
        <span class="course-free"><?= $price !== null && $price > 0 ? 'Deal' : 'Free' ?></span>
    </a>
    <div class="course-body">
        <?php if (!empty($course['category_name'])): ?>
            <span class="course-cat"><?= e($course['category_name']) ?></span>
        <?php endif; ?>
        <span class="discount-tag">[100% Off]</span>
        <h3 class="course-title"><a href="<?= e($courseUrl) ?>"><?= e($title) ?></a></h3>
        <?php if ($instructor !== null): ?>
            <div class="small-muted text-clamp-2"><?= e($instructor) ?></div>
        <?php endif; ?>
        <div class="course-meta">
            <?php if ($hasRating): ?>
                <span class="course-rating"><i data-lucide="star" style="width:14px;height:14px;fill:currentColor"></i> <?= e(number_format((float) $course['rating'], 1)) ?></span>
            <?php endif; ?>
            <?php if (!empty($course['review_count'])): ?>
                <span><i data-lucide="message-square" style="width:14px;height:14px"></i> <?= e(number_format((int) $course['review_count'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($course['language'])): ?>
                <span><?= e($course['language']) ?></span>
            <?php endif; ?>
        </div>
        <div class="course-price-row">
            <div>
                <?php if ($price !== null && $price > 0): ?>
                    <span class="course-price"><?= e(format_money($price, $course['currency'] ?? null)) ?></span>
                <?php else: ?>
                    <span class="course-price">FREE</span>
                <?php endif; ?>
                <?php if ($original !== null && $original > 0): ?>
                    <span class="course-old-price"><?= e(format_money($original, $course['currency'] ?? null)) ?></span>
                <?php endif; ?>
            </div>
            <span class="course-source"><?= e(time_ago($course['discovered_at'] ?? null)) ?></span>
        </div>
    </div>
</article>
