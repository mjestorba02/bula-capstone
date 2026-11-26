<?php
declare(strict_types=1);
require __DIR__.'/config.php';

// User accounts for every user type: Admin, Staff, MIS, Cashier, Student (College/SHS).
//   Admin: manages all user types.
//   MIS:   manages Staff, MIS, Cashier and Student accounts (not Admin accounts).
$s = require_roles(['admin','mis']);
ensure_user_academic_fields($pdo);
$actorRole = (string)$s['role'];
$manageable = $actorRole === 'admin' ? APP_ROLES : ['staff','mis','cashier','user'];
$roleLabels = ['admin'=>'Admin','staff'=>'Staff','mis'=>'MIS','cashier'=>'Cashier','user'=>'Student'];

function um_fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $role = (string)($_GET['role'] ?? '');
  $status = (string)($_GET['status'] ?? '');
  $search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 80);
  $w = ['1=1']; $args = [];
  if (in_array($role, APP_ROLES, true)) { $w[] = 'role=?'; $args[] = $role; }
  if (in_array($status, ['Active','Inactive'], true)) { $w[] = 'status=?'; $args[] = $status; }
  if ($search !== '') { $w[] = '(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR student_id LIKE ? OR employee_id LIKE ?)'; $like = '%'.$search.'%'; array_push($args, $like, $like, $like, $like, $like); }
  $q = $pdo->prepare("SELECT id,username,full_name,COALESCE(email,'') email,role,status,COALESCE(employee_id,'') employee_id,COALESCE(student_id,'') student_id,COALESCE(student_level,'') student_level,COALESCE(year_level,'') year_level,created_at
    FROM users WHERE ".implode(' AND ', $w)." ORDER BY FIELD(role,'admin','mis','staff','cashier','user'), full_name, id LIMIT 1000");
  $q->execute($args);
  $users = $q->fetchAll();
  foreach ($users as &$u) $u['can_manage'] = in_array($u['role'], $manageable, true);
  unset($u);
  $counts = [];
  foreach ($pdo->query("SELECT role, COUNT(*) c, SUM(status='Active') a, SUM(email IS NULL OR email='') no_email FROM users GROUP BY role")->fetchAll() as $r) {
    $counts[$r['role']] = ['total'=>(int)$r['c'], 'active'=>(int)$r['a'], 'no_email'=>(int)$r['no_email']];
  }
  echo json_encode(['success'=>true, 'users'=>$users, 'counts'=>$counts, 'manageable_roles'=>$manageable, 'role_labels'=>$roleLabels, 'year_levels'=>YEAR_LEVELS_BY_STUDENT_LEVEL]);
  exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($in['action'] ?? '');

try {
  if ($action === 'create' || $action === 'update') {
    $id = (int)($in['id'] ?? 0);
    $username = trim((string)($in['username'] ?? ''));
    $full = trim((string)($in['full_name'] ?? ''));
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $role = (string)($in['role'] ?? '');
    $status = in_array(($in['status'] ?? 'Active'), ['Active','Inactive'], true) ? (string)($in['status'] ?? 'Active') : 'Active';
    $password = (string)($in['password'] ?? '');
    $employeeId = trim((string)($in['employee_id'] ?? ''));
    $studentId = trim((string)($in['student_id'] ?? ''));
    $level = normalize_student_level((string)($in['student_level'] ?? 'College'));
    $year = valid_year_level($level, trim((string)($in['year_level'] ?? '')));

    if (!in_array($role, $manageable, true)) um_fail(403, 'You are not allowed to manage '.($roleLabels[$role] ?? 'this').' accounts.');
    if ($username === '' || $full === '') um_fail(422, 'Username and full name are required.');
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) um_fail(422, 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore).');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) um_fail(422, 'Please enter a valid email address.');
    if ($email === '') um_fail(422, 'An email address is required because every login is confirmed with an email OTP.');
    if ($action === 'create' && strlen($password) < 8) um_fail(422, 'Password must be at least 8 characters.');
    if ($action === 'update' && $password !== '' && strlen($password) < 8) um_fail(422, 'Password must be at least 8 characters.');
    if (in_array($role, ['staff','mis','cashier','admin'], true) && $employeeId === '') um_fail(422, 'Employee ID is required for '.$roleLabels[$role].' accounts.');

    if ($action === 'update') {
      $q = $pdo->prepare('SELECT id,role,status FROM users WHERE id=? LIMIT 1'); $q->execute([$id]); $existing = $q->fetch();
      if (!$existing) um_fail(404, 'Account not found.');
      if (!in_array($existing['role'], $manageable, true)) um_fail(403, 'You are not allowed to edit this account.');
      if ($id === (int)$s['user_id'] && ($status !== 'Active' || $role !== $existing['role'])) um_fail(422, 'You cannot deactivate your own account or change your own user type.');
    }
    $dup = $pdo->prepare("SELECT id FROM users WHERE (username=? OR (?<>'' AND email=?) OR (?<>'' AND employee_id=?)) AND id<>? LIMIT 1");
    $dup->execute([$username, $email, $email, $employeeId, $employeeId, $id]);
    if ($dup->fetch()) um_fail(409, 'Username, email, or employee ID is already used by another account.');

    $isStudent = $role === 'user';
    $values = [$username, $full, $email ?: null, $role, $status, $isStudent ? null : $employeeId, $isStudent ? $studentId : null, $isStudent ? $level : null, $isStudent ? $year : null];
    if ($action === 'create') {
      $q = $pdo->prepare('INSERT INTO users(username,full_name,email,role,status,employee_id,student_id,student_level,year_level,password_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
      $q->execute(array_merge($values, [password_hash($password, PASSWORD_DEFAULT)]));
      $newId = (int)$pdo->lastInsertId();
      log_event($pdo, (int)$s['user_id'], 'Created '.$roleLabels[$role].' account #'.$newId.' ('.$username.')', 'activity');
      echo json_encode(['success'=>true, 'message'=>$roleLabels[$role].' account created.', 'id'=>$newId]);
    } else {
      $q = $pdo->prepare('UPDATE users SET username=?,full_name=?,email=?,role=?,status=?,employee_id=?,student_id=?,student_level=?,year_level=? WHERE id=?');
      $q->execute(array_merge($values, [$id]));
      if ($password !== '') $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
      log_event($pdo, (int)$s['user_id'], 'Updated '.$roleLabels[$role].' account #'.$id.' ('.$username.')'.($password !== '' ? ' and reset password' : ''), 'activity');
      echo json_encode(['success'=>true, 'message'=>'Account updated.']);
    }
    exit;
  }

  if ($action === 'activate' || $action === 'deactivate') {
    $id = (int)($in['id'] ?? 0);
    $q = $pdo->prepare('SELECT id,role,username FROM users WHERE id=? LIMIT 1'); $q->execute([$id]); $u = $q->fetch();
    if (!$u) um_fail(404, 'Account not found.');
    if (!in_array($u['role'], $manageable, true)) um_fail(403, 'You are not allowed to change this account.');
    if ($id === (int)$s['user_id']) um_fail(422, 'You cannot change the status of your own account.');
    $st = $action === 'activate' ? 'Active' : 'Inactive';
    $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$st, $id]);
    log_event($pdo, (int)$s['user_id'], ($st === 'Active' ? 'Activated' : 'Deactivated').' account #'.$id.' ('.$u['username'].')', 'activity');
    echo json_encode(['success'=>true, 'message'=>'Account '.($st === 'Active' ? 'activated' : 'deactivated').'.']);
    exit;
  }

  um_fail(400, 'Unknown action.');
} catch (PDOException $e) {
  error_log('users_manage.php: '.$e->getMessage());
  um_fail(500, 'Database error while saving the account.');
}
