<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_once __DIR__.'/analytics_lib.php';

// Dashboard (graphs + score cards), Data Gathering, Records and Logs.
//   Admin / MIS / Staff: every action, all records (Staff is read-only by design).
//   Cashier: dashboard only, limited to the payments assigned to that cashier.
$s = require_roles(['admin','mis','staff','cashier']);
$role = (string)$s['role'];
ensure_user_academic_fields($pdo);
ensure_payments_table($pdo);
ensure_payment_modules_tables($pdo);

$action = (string)($_GET['action'] ?? 'dashboard');
$f = analytics_filters($_GET);
if ($role === 'cashier') {
  if ($action !== 'dashboard') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Cashiers can only view their own dashboard.']); exit; }
  $f['cashier_id'] = (int)$s['user_id'];
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(5, (int)($_GET['per_page'] ?? 15)));
$paginate = function (int $total) use (&$page, $perPage): array {
  $pages = max(1, (int)ceil($total / $perPage));
  if ($page > $pages) $page = $pages;
  return ['page'=>$page, 'per_page'=>$perPage, 'total'=>$total, 'pages'=>$pages];
};

if ($action === 'dashboard') {
  $data = analytics_dashboard($pdo, $f, $role !== 'cashier');
  echo json_encode(['success'=>true, 'role'=>$role, 'filters'=>$f, 'generated_at'=>date('Y-m-d H:i:s'), 'payment_modes'=>get_payment_modes($pdo)] + $data);
  exit;
}

if ($action === 'records') {
  $totals = analytics_payment_totals($pdo, $f);
  $pg = $paginate($totals['records']);
  $rows = analytics_payment_rows($pdo, $f, $perPage, ($pg['page'] - 1) * $perPage);
  echo json_encode(['success'=>true, 'rows'=>$rows, 'totals'=>$totals, 'pagination'=>$pg, 'filters'=>$f]);
  exit;
}

if ($action === 'students') {
  $all = analytics_student_rows($pdo, $f);
  $totals = ['students'=>count($all), 'assessment'=>0.0, 'paid'=>0.0, 'pending'=>0.0, 'outstanding'=>0.0, 'fully_paid'=>0, 'with_balance'=>0, 'College'=>0, 'SHS'=>0];
  foreach ($all as $r) {
    $totals['assessment'] += $r['adjusted_assessment']; $totals['paid'] += $r['paid'];
    $totals['pending'] += $r['pending']; $totals['outstanding'] += $r['outstanding'];
    $r['outstanding'] > 0 ? $totals['with_balance']++ : $totals['fully_paid']++;
    $totals[$r['student_level'] === 'SHS' ? 'SHS' : 'College']++;
  }
  foreach (['assessment','paid','pending','outstanding'] as $k) $totals[$k] = round($totals[$k], 2);
  $pg = $paginate(count($all));
  echo json_encode(['success'=>true, 'rows'=>array_slice($all, ($pg['page'] - 1) * $perPage, $perPage), 'totals'=>$totals, 'pagination'=>$pg, 'filters'=>$f]);
  exit;
}

if ($action === 'logs') {
  $pg = $paginate(analytics_log_count($pdo, $f));
  $rows = analytics_log_rows($pdo, $f, $perPage, ($pg['page'] - 1) * $perPage);
  echo json_encode(['success'=>true, 'rows'=>$rows, 'pagination'=>$pg, 'filters'=>$f]);
  exit;
}

http_response_code(400);
echo json_encode(['success'=>false, 'message'=>'Unknown analytics action.']);
