<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_once __DIR__.'/mailer.php';

// Security overview for MIS/Admin: OTP + session policy, OTP email sender status,
// and a "send test email" action to confirm the Gmail credentials work.
$s = require_roles(['admin','mis']);
ensure_security_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $c = mail_config();
  $since = date('Y-m-d H:i:s', time() - 86400);
  $stat = function (string $like) use ($pdo, $since) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE category='security' AND created_at>=? AND action LIKE ?");
    $q->execute([$since, $like]);
    return (int)$q->fetchColumn();
  };
  echo json_encode(['success'=>true,
    'policy'=>[
      'otp_resend_cooldown_seconds'=>OTP_RESEND_COOLDOWN_SECONDS,
      'otp_ttl_seconds'=>OTP_TTL_SECONDS,
      'otp_max_attempts'=>OTP_MAX_ATTEMPTS,
      'session_timeout_seconds'=>SESSION_TIMEOUT_SECONDS,
      'login_lockout'=>'5 failed passwords = 15-minute lock',
    ],
    'mail'=>[
      'configured'=>mail_is_configured(),
      'demo_mode'=>mail_dev_fallback(),
      'host'=>$c['host'], 'port'=>$c['port'], 'secure'=>$c['secure'],
      'from_email'=>mail_is_configured() ? (string)$c['from_email'] : '',
      'from_name'=>(string)$c['from_name'],
    ],
    'last_24h'=>[
      'sign_ins'=>$stat('Signed in%'),
      'failed_logins'=>$stat('Failed login%'),
      'wrong_otps'=>$stat('Wrong OTP%'),
      'timeouts'=>$stat('%inactivity%'),
    ],
    'accounts_without_email'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='Active' AND (email IS NULL OR email='')")->fetchColumn(),
  ]);
  exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['action'] ?? '') === 'test_email') {
  $to = strtolower(trim((string)($in['email'] ?? '')));
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Enter a valid email address for the test.']); exit; }
  if (!mail_is_configured()) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'The Gmail sender is not configured yet. Fill in backend/mail_config.php first.']); exit; }
  [$html, $text] = otp_email_content((string)($s['full_name'] ?? 'MIS'), '123456', intdiv(OTP_TTL_SECONDS, 60));
  $html = '<p style="font-family:Arial;color:#b45309"><b>TEST EMAIL</b> &mdash; this is not a real OTP.</p>'.$html;
  $err = null;
  $ok = send_mail($to, 'Test email - Bestlink Payment System OTP sender', $html, "TEST EMAIL - not a real OTP.\n\n".$text, $err);
  log_event($pdo, (int)$s['user_id'], 'OTP email sender test to '.mask_email($to).': '.($ok ? 'sent' : 'failed'), 'security');
  if (!$ok) { http_response_code(502); echo json_encode(['success'=>false,'message'=>'Test email failed: '.$err]); exit; }
  echo json_encode(['success'=>true,'message'=>'Test email sent to '.$to.'. Check the inbox (and Spam folder).']);
  exit;
}
http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Unknown action.']);
