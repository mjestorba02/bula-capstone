<?php
require __DIR__.'/config.php';
if(!empty($_SESSION['user_id'])){
  $reason = (($_GET['reason'] ?? '') === 'idle') ? 'Signed out automatically after 3 minutes of inactivity' : 'Signed out';
  log_event($pdo, (int)$_SESSION['user_id'], $reason, 'security');
}
$_SESSION=[];
if(ini_get('session.use_cookies')){
  $p=session_get_cookie_params();
  setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
}
session_destroy();
echo json_encode(['success'=>true]);
