<?php
$flashTypes = ['success' => 'alert-success', 'error' => 'alert-danger', 'info' => 'alert-info', 'warning' => 'alert-warning'];
$flashMessages = [];
foreach (array_keys($flashTypes) as $type) {
    $message = flash($type);
    if ($message !== null) {
        $flashMessages[$type] = $message;
    }
}
?>
<?php if ($flashMessages): ?>
    <div class="container mt-3">
        <?php foreach ($flashMessages as $type => $message): ?>
            <div class="alert <?= e($flashTypes[$type]) ?> d-flex align-items-start gap-2" data-auto-dismiss role="alert">
                <i data-lucide="<?= $type === 'success' ? 'check-circle-2' : ($type === 'error' ? 'alert-circle' : 'info') ?>" style="width:18px;height:18px;margin-top:2px"></i>
                <div><?= e($message) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
