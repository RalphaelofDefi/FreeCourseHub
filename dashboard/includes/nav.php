<?php
$activeTab = $activeTab ?? 'overview';
$tabs = [
    'overview' => ['index.php', 'layout-dashboard', 'Overview'],
    'courses' => ['courses.php', 'bookmark', 'My Courses'],
    'payments' => ['payments.php', 'receipt', 'Payment History'],
    'settings' => ['settings.php', 'settings', 'Account Settings'],
];
?>
<div class="panel mb-4">
    <div class="panel-body py-2">
        <ul class="nav nav-pills flex-wrap gap-1 mb-0">
            <?php foreach ($tabs as $key => [$file, $icon, $label]): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" href="<?= e(url('dashboard/' . $file)) ?>">
                        <i data-lucide="<?= e($icon) ?>" style="width:16px;height:16px"></i> <?= e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
