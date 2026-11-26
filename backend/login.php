<?php
require __DIR__.'/config.php';
require_once __DIR__.'/otp_lib.php';

// STEP 1 of 2: username + password. On success an OTP is emailed and the
// browser continues with verify_otp.php. No session is created until the OTP
// is verified.
$in=json_decode(file_get_contents('php://input'),true) ?: [];
$username=trim((string)($in['username']??''));
$password=(string)($in['password']??'');
if($username===''||$password===''){
  http_response_code(422);
  echo json_encode(['success'=>false,'message'=>'Username and password are required.']);
  exit;
}

// Keep login compatible with existing XAMPP databases (adds role/email/level columns).
ensure_user_academic_fields($pdo);
ensure_security_tables($pdo);

// Login throttling: five failed attempts for the same username/IP are temporarily blocked.
$ip = client_ip();
$pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(190) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_failed_at DATETIME NULL,
  UNIQUE KEY uq_login_attempt (username, ip_address),
  INDEX idx_login_lock (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$attempt=$pdo->prepare('SELECT failed_count,locked_until FROM login_attempts WHERE username=? AND ip_address=? LIMIT 1');
$attempt->execute([$username,$ip]);
$lock=$attempt->fetch();
if($lock && !empty($lock['locked_until']) && strtotime($lock['locked_until']) > time()){
  http_response_code(429);
  echo json_encode(['success'=>false,'message'=>'Too many failed login attempts. Please try again later.']);
  exit;
}

$q=$pdo->prepare("SELECT id,username,password_hash,full_name,email,student_id,student_level,year_level,employee_id,role,status FROM users WHERE username=? LIMIT 1");
$q->execute([$username]);
$u=$q->fetch();

if(!$u || $u['status']!=='Active' || !password_verify($password,$u['password_hash'])){
  $newCount=(int)($lock['failed_count'] ?? 0)+1;
  $lockedUntil=$newCount>=5 ? date('Y-m-d H:i:s',time()+900) : null;
  $up=$pdo->prepare("INSERT INTO login_attempts(username,ip_address,failed_count,locked_until,last_failed_at) VALUES(?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE failed_count=VALUES(failed_count), locked_until=VALUES(locked_until), last_failed_at=NOW()");
  $up->execute([$username,$ip,$newCount,$lockedUntil]);
  log_event($pdo, $u ? (int)$u['id'] : null, 'Failed login for username "'.mb_substr($username,0,60).'"'.($lockedUntil?' (locked for 15 minutes)':''), 'security');
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Invalid username or password.']);
  exit;
}

// Upgrade older hashes transparently after a successful password check.
if(password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)){
  $rh=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
  $rh->execute([password_hash($password,PASSWORD_DEFAULT),(int)$u['id']]);
}

$clear=$pdo->prepare('DELETE FROM login_attempts WHERE username=? AND ip_address=?');
$clear->execute([$username,$ip]);

// Any previous signed-in session in this browser ends here.
unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['last_activity']);
session_regenerate_id(true);

try {
  echo json_encode(issue_login_otp($pdo, $u));
} catch (OtpDeliveryException $e) {
  unset($_SESSION['otp_pending']);
  http_response_code(503);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
