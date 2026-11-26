<?php
require __DIR__.'/config.php';
if(empty($_SESSION['user_id'])){echo json_encode(['success'=>false,'user'=>null]);exit;}
// Apply the same 3-minute inactivity rule as every other endpoint.
if((time()-(int)($_SESSION['last_activity']??0)) > SESSION_TIMEOUT_SECONDS){
  log_event($pdo,(int)$_SESSION['user_id'],'Session timed out after 3 minutes of inactivity','security');
  $_SESSION=[]; session_destroy();
  echo json_encode(['success'=>false,'user'=>null,'code'=>'session_expired','message'=>'Your session expired after 3 minutes of inactivity.']);exit;
}
ensure_user_academic_fields($pdo);
$q=$pdo->prepare("SELECT id,username,full_name,email,student_id,student_level,year_level,employee_id,role,status FROM users WHERE id=?");
$q->execute([(int)$_SESSION['user_id']]); $u=$q->fetch();
if(!$u || $u['status']!=='Active'){session_unset();session_destroy();echo json_encode(['success'=>false,'user'=>null]);exit;}
$_SESSION['role']=$u['role']; $_SESSION['last_activity']=time();
echo json_encode(['success'=>true,'user'=>public_user_payload($u)+['status'=>$u['status']],'session_timeout'=>SESSION_TIMEOUT_SECONDS]);
