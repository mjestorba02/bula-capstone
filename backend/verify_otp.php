<?php
require __DIR__.'/config.php';
require_once __DIR__.'/otp_lib.php';

// STEP 2 of 2: verify the emailed OTP (valid for 5 minutes, 5 attempts).
ensure_security_tables($pdo);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));

$u = pending_otp_user($pdo);
if (!$u) {
  http_response_code(440);
  echo json_encode(['success'=>false,'code'=>'otp_session_missing','message'=>'Your verification session has ended. Please log in again.']);
  exit;
}
if (strlen($code) !== 6) {
  http_response_code(422);
  echo json_encode(['success'=>false,'message'=>'Enter the 6-digit OTP.']);
  exit;
}

$pending = $_SESSION['otp_pending'];
$q = $pdo->prepare('SELECT * FROM login_otps WHERE id=? AND user_id=? LIMIT 1');
$q->execute([(int)$pending['otp_id'], (int)$u['id']]);
$otp = $q->fetch();

if (!$otp || $otp['consumed_at'] !== null) {
  unset($_SESSION['otp_pending']);
  http_response_code(440);
  echo json_encode(['success'=>false,'code'=>'otp_session_missing','message'=>'This OTP is no longer valid. Please log in again.']);
  exit;
}
if (strtotime((string)$otp['expires_at']) < time()) {
  $pdo->prepare('UPDATE login_otps SET consumed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$otp['id']]);
  log_event($pdo, (int)$u['id'], 'OTP expired before verification', 'security');
  http_response_code(410);
  echo json_encode(['success'=>false,'code'=>'otp_expired','message'=>'The OTP has expired. Tap "Resend OTP" to get a new code.']);
  exit;
}

if (!password_verify($code, (string)$otp['code_hash'])) {
  $attempts = (int)$otp['attempts'] + 1;
  $pdo->prepare('UPDATE login_otps SET attempts=? WHERE id=?')->execute([$attempts, (int)$otp['id']]);
  if ($attempts >= OTP_MAX_ATTEMPTS) {
    $pdo->prepare('UPDATE login_otps SET consumed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$otp['id']]);
    unset($_SESSION['otp_pending']);
    log_event($pdo, (int)$u['id'], 'OTP locked after '.OTP_MAX_ATTEMPTS.' wrong attempts', 'security');
    http_response_code(429);
    echo json_encode(['success'=>false,'code'=>'otp_locked','message'=>'Too many wrong OTP attempts. Please log in again.']);
    exit;
  }
  log_event($pdo, (int)$u['id'], 'Wrong OTP entered (attempt '.$attempts.' of '.OTP_MAX_ATTEMPTS.')', 'security');
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Incorrect OTP. '.(OTP_MAX_ATTEMPTS - $attempts).' attempt(s) left.']);
  exit;
}

$pdo->prepare('UPDATE login_otps SET consumed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), (int)$otp['id']]);
establish_user_session($u);
log_event($pdo, (int)$u['id'], 'Signed in (password + email OTP verified)', 'security');

echo json_encode(['success'=>true, 'user'=>public_user_payload($u), 'session_timeout'=>SESSION_TIMEOUT_SECONDS]);
