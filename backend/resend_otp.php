<?php
require __DIR__.'/config.php';
require_once __DIR__.'/otp_lib.php';

// Re-send the OTP email. Allowed once every 2 minutes.
ensure_security_tables($pdo);
$u = pending_otp_user($pdo);
if (!$u) {
  http_response_code(440);
  echo json_encode(['success'=>false,'code'=>'otp_session_missing','message'=>'Your verification session has ended. Please log in again.']);
  exit;
}

$q = $pdo->prepare('SELECT sent_at FROM login_otps WHERE user_id=? ORDER BY id DESC LIMIT 1');
$q->execute([(int)$u['id']]);
$lastSent = $q->fetchColumn();
$wait = $lastSent ? (strtotime((string)$lastSent) + OTP_RESEND_COOLDOWN_SECONDS) - time() : 0;
if ($wait > 0) {
  http_response_code(429);
  echo json_encode(['success'=>false,'resend_in'=>$wait,'message'=>'Please wait '.$wait.' second(s) before requesting a new OTP.']);
  exit;
}

try {
  $payload = issue_login_otp($pdo, $u);
  $payload['message'] = !empty($payload['dev_mode']) ? $payload['message'] : 'A new OTP was sent to '.$payload['masked_email'].'.';
  echo json_encode($payload);
} catch (OtpDeliveryException $e) {
  http_response_code(503);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
