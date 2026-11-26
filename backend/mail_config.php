<?php
/**
 * OTP EMAIL SENDER (Gmail SMTP)
 * -----------------------------------------------------------------------------
 * Fill in the Gmail account that will send the login OTP codes:
 *
 *   1. Use (or create) a Gmail account for the system, e.g. bcp.payments@gmail.com
 *   2. Turn on 2-Step Verification for that Google account.
 *   3. Create an App Password: Google Account > Security > 2-Step Verification
 *      > App passwords. Copy the 16-character password (spaces don't matter).
 *   4. Put the Gmail address in 'username' and 'from_email', and the App Password
 *      in 'password' below (or set the SMTP_* environment variables instead).
 *
 * Do NOT use the normal Gmail password — Google rejects it for SMTP.
 *
 * While 'username'/'password' are empty, the system runs in OTP DEMO MODE:
 * the login still requires an OTP, but the code is shown on the login screen
 * instead of being emailed. Set 'dev_fallback' => false for production.
 */
// Local-only overrides (gitignored): backend/mail_config.local.php returns an array.
$local = is_file(__DIR__.'/mail_config.local.php') ? (array)(require __DIR__.'/mail_config.local.php') : [];

return $local + [
  'host'         => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
  'port'         => (int)(getenv('SMTP_PORT') ?: 587),
  'secure'       => getenv('SMTP_SECURE') ?: 'tls',     // 'tls' (STARTTLS, port 587) or 'ssl' (port 465)
  'username'     => getenv('SMTP_USERNAME') ?: '',       // Gmail address
  'password'     => getenv('SMTP_PASSWORD') ?: '',       // 16-character Google App Password
  'from_email'   => getenv('SMTP_FROM_EMAIL') ?: '',     // usually the same Gmail address
  'from_name'    => getenv('SMTP_FROM_NAME') ?: 'Payment System',
  'timeout'      => 20,
  'dev_fallback' => false,
];
