<?php
declare(strict_types=1);

/**
 * Minimal SMTP mailer (STARTTLS / SSL + AUTH LOGIN) for Gmail.
 * No Composer packages are required, so it works on a plain XAMPP install.
 */

function mail_config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $cfg = require __DIR__.'/mail_config.php';
    $cfg['password'] = str_replace(' ', '', (string)($cfg['password'] ?? ''));
    if (trim((string)($cfg['from_email'] ?? '')) === '') $cfg['from_email'] = (string)($cfg['username'] ?? '');
  }
  return $cfg;
}

function mail_is_configured(): bool {
  $c = mail_config();
  return trim((string)$c['username']) !== '' && trim((string)$c['password']) !== '';
}

function mail_dev_fallback(): bool {
  return !mail_is_configured() && !empty(mail_config()['dev_fallback']);
}

/** Sends an email. Returns true on success; on failure $error explains why. */
function send_mail(string $to, string $subject, string $html, string $text, ?string &$error = null): bool {
  $error = null;
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid recipient email address.'; return false; }
  if (!mail_is_configured()) { $error = 'The OTP email sender (Gmail SMTP) is not configured yet.'; return false; }
  $c = mail_config();
  $secure = strtolower((string)$c['secure']);
  $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://').$c['host'].':'.(int)$c['port'];

  $sslOptions = ['verify_peer'=>true, 'verify_peer_name'=>true, 'peer_name'=>$c['host']];
  foreach ([ini_get('openssl.cafile'), ini_get('curl.cainfo'), 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt'] as $ca) {
    if ($ca && is_file($ca)) { $sslOptions['cafile'] = $ca; break; }
  }
  $ctx = stream_context_create(['ssl'=>$sslOptions]);
  $fp = @stream_socket_client($remote, $errno, $errstr, (int)$c['timeout'], STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) { $error = 'Cannot connect to the mail server ('.$errstr.').'; return false; }
  stream_set_timeout($fp, (int)$c['timeout']);

  try {
    smtp_expect($fp, [220]);
    $ehloHost = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
    smtp_cmd($fp, 'EHLO '.$ehloHost, [250]);
    if ($secure !== 'ssl') {
      smtp_cmd($fp, 'STARTTLS', [220]);
      $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
      if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
      if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
      if (!@stream_socket_enable_crypto($fp, true, $method)) throw new RuntimeException('TLS negotiation with the mail server failed.');
      smtp_cmd($fp, 'EHLO '.$ehloHost, [250]);
    }
    smtp_cmd($fp, 'AUTH LOGIN', [334]);
    smtp_cmd($fp, base64_encode((string)$c['username']), [334]);
    smtp_cmd($fp, base64_encode((string)$c['password']), [235], 'Gmail rejected the login. Check the Gmail address and App Password in backend/mail_config.php.');
    smtp_cmd($fp, 'MAIL FROM:<'.$c['from_email'].'>', [250]);
    smtp_cmd($fp, 'RCPT TO:<'.$to.'>', [250, 251]);
    smtp_cmd($fp, 'DATA', [354]);

    $boundary = 'b_'.bin2hex(random_bytes(12));
    $fromName = '=?UTF-8?B?'.base64_encode((string)$c['from_name']).'?=';
    $headers = [
      'Date: '.date('r'),
      'From: '.$fromName.' <'.$c['from_email'].'>',
      'To: <'.$to.'>',
      'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
      'Message-ID: <'.bin2hex(random_bytes(16)).'@'.substr(strrchr((string)$c['from_email'], '@') ?: '@localhost', 1).'>',
      'MIME-Version: 1.0',
      'Content-Type: multipart/alternative; boundary="'.$boundary.'"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html))
          . "--$boundary--\r\n";
    $message = implode("\r\n", $headers)."\r\n\r\n".$body;
    $message = preg_replace('/^\./m', '..', $message); // dot-stuffing
    fwrite($fp, $message."\r\n.\r\n");
    smtp_expect($fp, [250]);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
  } catch (Throwable $e) {
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    $error = $e->getMessage();
    error_log('SMTP error: '.$error);
    return false;
  }
}

function smtp_cmd($fp, string $cmd, array $okCodes, string $failMessage = ''): string {
  fwrite($fp, $cmd."\r\n");
  return smtp_expect($fp, $okCodes, $failMessage);
}

function smtp_expect($fp, array $okCodes, string $failMessage = ''): string {
  $response = '';
  while (($line = fgets($fp, 1024)) !== false) {
    $response .= $line;
    if (strlen($line) < 4 || $line[3] === ' ') break; // last line of a (multi-line) reply
  }
  $code = (int)substr($response, 0, 3);
  if (!in_array($code, $okCodes, true)) {
    throw new RuntimeException($failMessage !== '' ? $failMessage : 'Mail server error: '.trim($response ?: 'no response'));
  }
  return $response;
}

/** Branded OTP email body. */
function otp_email_content(string $name, string $code, int $ttlMinutes): array {
  $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
  $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;border:1px solid #e2e8f0;border-radius:14px;padding:26px;color:#172033">'
    .'<h2 style="margin:0 0 4px;color:#173b82">Bestlink College of the Philippines</h2>'
    .'<p style="margin:0 0 18px;color:#64748b;font-size:13px">Payment Management System &bull; Login Verification</p>'
    .'<p>Hello '.$safeName.',</p><p>Use this one-time password (OTP) to finish signing in:</p>'
    .'<p style="font-size:32px;font-weight:800;letter-spacing:8px;color:#173b82;background:#eff6ff;border-radius:10px;padding:14px;text-align:center">'.$code.'</p>'
    .'<p style="font-size:13px;color:#475569">This code expires in <b>'.$ttlMinutes.' minutes</b>. Never share it with anyone, including school staff.</p>'
    .'<p style="font-size:12px;color:#94a3b8">If you did not try to sign in, change your password and contact the MIS office.</p></div>';
  $text = "Bestlink College Payment System\n\nHello $name,\n\nYour login OTP is: $code\nIt expires in $ttlMinutes minutes. Never share this code.\n\nIf you did not try to sign in, contact the MIS office.";
  return [$html, $text];
}
