<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$q = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['pending', 'success', 'failed', 'cancelled', 'abandoned'], true) ? (string) $_GET['status'] : '';

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(p.transaction_reference LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare(
    'SELECT p.transaction_reference, u.full_name, u.email, c.title AS course_title,
            p.amount, p.currency, p.provider, p.status, p.verified_at, p.created_at
     FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN courses c ON c.id = p.course_id
     WHERE ' . $whereSql . '
     ORDER BY p.created_at DESC'
);
$stmt->execute($params);

log_activity((int) Auth::user()['id'], 'admin.payments_exported', 'Exported payment records');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payments-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store');

$output = fopen('php://output', 'wb');
if ($output === false) {
    exit;
}
fputcsv($output, ['Reference', 'User', 'Email', 'Course', 'Amount', 'Currency', 'Provider', 'Status', 'Verified at', 'Created at']);
while ($row = $stmt->fetch()) {
    fputcsv($output, [
        $row['transaction_reference'],
        $row['full_name'],
        $row['email'],
        $row['course_title'] ?? 'Course removed',
        $row['amount'],
        $row['currency'],
        $row['provider'],
        $row['status'],
        $row['verified_at'] ?? '',
        $row['created_at'],
    ]);
}
fclose($output);
exit;
