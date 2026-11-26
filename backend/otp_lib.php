<?php
declare(strict_types=1);
require_once __DIR__.'/mailer.php';

/**
 * Creates a new 6-digit OTP for the user, invalidates older unused codes,
 * emails it, and records the pending login in the session.
 * Returns the JSON payload for the browser, or throws OtpDeliveryException.
 */
final class OtpDeliveryException extends RuntimeException {}

function issue_login_otp(PDO $pdo, array $u): array {
  ensure_security_tables($pdo);
  $email = trim((string)($u['email'] ?? ''));
  $devMode = mail_dev_fallback();

  if (!$devMode) {
    if (!mail_is_configured()) throw new OtpDeliveryException('The OTP email sender is not configured. Please contact the MIS office.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      log_event($pdo, (int)$u['id'], 'OTP not sent: no valid email on file', 'security');
      throw new OtpDeliveryException('No email address is registered for this account, so the OTP cannot be sent. Please ask the MIS office or Admin to add your email.');
    }
  }

  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $now = time();
  $pdo->prepare('UPDATE login_otps SET consumed_at=? WHERE user_id=? AND consumed_at IS NULL')
      ->execute([date('Y-m-d H:i:s', $now), (int)$u['id']]);
  $ins = $pdo->prepare('INSERT INTO login_otps(user_id,code_hash,sent_to,sent_at,expires_at,ip_address) VALUES(?,?,?,?,?,?)');
  $ins->execute([(int)$u['id'], password_hash($code, PASSWORD_DEFAULT), $email ?: null, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + OTP_TTL_SECONDS), client_ip()]);
  $otpId = (int)$pdo->lastInsertId();

  if (!$devMode) {
    [$html, $text] = otp_email_content((string)$u['full_name'], $code, intdiv(OTP_TTL_SECONDS, 60));
    $err = null;
    if (!send_mail($email, 'Your Bestlink login OTP: '.$code, $html, $text, $err)) {
      $pdo->prepare('UPDATE login_otps SET consumed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $otpId]);
      log_event($pdo, (int)$u['id'], 'OTP email failed: '.mb_substr((string)$err, 0, 150), 'security');
      throw new OtpDeliveryException('The OTP email could not be sent. Please try again in a moment or contact the MIS office.');
    }
  }

  $_SESSION['otp_pending'] = ['user_id'=>(int)$u['id'], 'otp_id'=>$otpId, 'started'=>$now];
  log_event($pdo, (int)$u['id'], $devMode ? 'OTP generated (demo mode, email sender not configured)' : 'OTP sent to '.mask_email($email), 'security');

  $payload = [
    'success'=>true,
    'otp_required'=>true,
    'masked_email'=>$email !== '' ? mask_email($email) : '',
    'expires_in'=>OTP_TTL_SECONDS,
    'resend_in'=>OTP_RESEND_COOLDOWN_SECONDS,
    'message'=>$devMode
      ? 'OTP demo mode: the Gmail sender is not configured yet, so the code is shown below.'
      : 'We sent a 6-digit OTP to '.mask_email($email).'. It expires in '.intdiv(OTP_TTL_SECONDS, 60).' minutes.',
  ];
  if ($devMode) { $payload['dev_mode'] = true; $payload['dev_otp'] = $code; }
  return $payload;
}

/** Loads the user behind the pending OTP login, or null when there is none. */
function pending_otp_user(PDO $pdo): ?array {
  $p = $_SESSION['otp_pending'] ?? null;
  if (!is_array($p) || empty($p['user_id'])) return null;
  ensure_user_academic_fields($pdo);
  $q = $pdo->prepare('SELECT id,username,full_name,email,student_id,student_level,year_level,employee_id,role,status FROM users WHERE id=? LIMIT 1');
  $q->execute([(int)$p['user_id']]);
  $u = $q->fetch();
  if (!$u || $u['status'] !== 'Active') { unset($_SESSION['otp_pending']); return null; }
  return $u;
}
