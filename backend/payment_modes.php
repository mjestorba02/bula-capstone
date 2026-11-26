<?php
declare(strict_types=1);
require __DIR__.'/config.php';

// Modes of payment: HMA (online QR Ph) and Walk-in (cashier window).
// Everyone signed in can read them; only MIS and Admin can switch them on/off.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  require_login();
  echo json_encode(['success'=>true, 'modes'=>get_payment_modes($pdo)]);
  exit;
}

$s = require_roles(['admin','mis']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$key = (string)($in['mode'] ?? '');
if (!isset(PAYMENT_MODES[$key])) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Unknown mode of payment.']); exit; }
$enabled = !empty($in['enabled']);

$modes = get_payment_modes($pdo);
$modes[$key]['enabled'] = $enabled;
if (!array_filter($modes, fn($m) => $m['enabled'])) {
  http_response_code(422);
  echo json_encode(['success'=>false,'message'=>'At least one mode of payment must stay enabled so students can still pay.']);
  exit;
}

$q = $pdo->prepare("INSERT INTO payment_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");
$q->execute(['mode_'.$key.'_enabled', $enabled ? '1' : '0', (int)$s['user_id']]);
log_event($pdo, (int)$s['user_id'], ($enabled ? 'Enabled' : 'Disabled').' mode of payment: '.PAYMENT_MODES[$key]['label'], 'activity');
echo json_encode(['success'=>true, 'message'=>PAYMENT_MODES[$key]['label'].' is now '.($enabled ? 'enabled' : 'disabled').'.', 'modes'=>get_payment_modes($pdo)]);
