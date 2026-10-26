<?php
// Download a simple reservation report. Every cell is escaped against CSV formula injection.

declare(strict_types=1);

use App\Helpers\Response;
use App\Repositories\ReservationRepository;
require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['admin']);
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reservibe-reservations-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fputcsv($out, ['Reference', 'Guest', 'Email', 'Phone', 'Package', 'Check-in', 'Check-out', 'Status', 'Guest status', 'Total', 'Paid', 'Balance', 'Created']);
$safe = static function ($value): string {
    $text = (string) $value;
    return preg_match('/^[=+@\-\t\r]/', $text) ? "'" . $text : $text;
};
foreach ((new ReservationRepository())->all() as $r) {
    fputcsv($out, array_map($safe, [
        $r['reference'] ?? '', $r['guest_name'] ?? '', $r['guest_email'] ?? '', $r['guest_phone'] ?? '',
        $r['accommodation_name'] ?? '', $r['check_in'] ?? '', $r['check_out'] ?? '', $r['status'] ?? '',
        $r['guest_status'] ?? '', $r['total'] ?? 0, $r['amount_paid'] ?? 0, $r['balance_due'] ?? 0, $r['created_at'] ?? '',
    ]));
}
fclose($out);
exit;
