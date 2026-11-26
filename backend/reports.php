<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_once __DIR__.'/analytics_lib.php';
require_once __DIR__.'/report_writers.php';

// Reports: CSV, Excel (.xlsx) and PDF for Admin, MIS and Staff.
$s = require_roles(['admin','mis','staff']);
ensure_user_academic_fields($pdo);
ensure_payments_table($pdo);
ensure_payment_modules_tables($pdo);

$type = (string)($_GET['type'] ?? 'payments');
$format = strtolower((string)($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv','xlsx','pdf'], true)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Choose CSV, Excel, or PDF.']); exit; }
$f = analytics_filters($_GET);
$stamp = date('Ymd_His');
$money = fn($n) => 'PHP '.number_format((float)$n, 2);

switch ($type) {
  case 'payments':
    $rows = analytics_payment_rows($pdo, $f);
    $columns = [
      ['key'=>'payment_date','label'=>'Date','type'=>'text'],
      ['key'=>'reference_number','label'=>'Reference No.','type'=>'text'],
      ['key'=>'student_name','label'=>'Student','type'=>'text'],
      ['key'=>'student_id','label'=>'Student ID','type'=>'text'],
      ['key'=>'student_level','label'=>'Level','type'=>'text'],
      ['key'=>'year_level','label'=>'Year','type'=>'text'],
      ['key'=>'fee_name','label'=>'Fee','type'=>'text'],
      ['key'=>'period','label'=>'Period','type'=>'text'],
      ['key'=>'mode','label'=>'Mode','type'=>'text'],
      ['key'=>'amount','label'=>'Amount','type'=>'money'],
      ['key'=>'status','label'=>'Status','type'=>'text'],
      ['key'=>'cashier_name','label'=>'Cashier','type'=>'text'],
      ['key'=>'receipt_number','label'=>'Receipt No.','type'=>'text'],
    ];
    $verified = array_sum(array_map(fn($r) => in_array($r['status'], ['Verified','Paid'], true) ? (float)$r['amount'] : 0, $rows));
    $meta = ['title'=>'Payment Transaction Records', 'sheet'=>'Payments', 'filename'=>'payment_records_'.$stamp,
      'summary'=>['Total records'=>number_format(count($rows)), 'Total amount (all statuses)'=>$money(array_sum(array_column($rows, 'amount'))), 'Verified collections'=>$money($verified)]];
    break;

  case 'students':
    $rows = analytics_student_rows($pdo, $f);
    $columns = [
      ['key'=>'student_id','label'=>'Student ID','type'=>'text'],
      ['key'=>'full_name','label'=>'Student','type'=>'text'],
      ['key'=>'student_level','label'=>'Level','type'=>'text'],
      ['key'=>'year_level','label'=>'Year','type'=>'text'],
      ['key'=>'account_status','label'=>'Account','type'=>'text'],
      ['key'=>'adjusted_assessment','label'=>'Assessment','type'=>'money'],
      ['key'=>'discount','label'=>'Discount','type'=>'money'],
      ['key'=>'paid','label'=>'Verified Paid','type'=>'money'],
      ['key'=>'pending','label'=>'Pending','type'=>'money'],
      ['key'=>'outstanding','label'=>'Balance','type'=>'money'],
      ['key'=>'progress','label'=>'Paid %','type'=>'percent'],
      ['key'=>'last_payment','label'=>'Last Payment','type'=>'text'],
    ];
    $meta = ['title'=>'Student Account Balances (Data Gathering)', 'sheet'=>'Student Balances', 'filename'=>'student_balances_'.$stamp,
      'summary'=>['Students'=>number_format(count($rows)), 'College / SHS'=>count(array_filter($rows, fn($r) => $r['student_level'] !== 'SHS')).' / '.count(array_filter($rows, fn($r) => $r['student_level'] === 'SHS')),
        'Total assessment'=>$money(array_sum(array_column($rows, 'adjusted_assessment'))), 'Total verified paid'=>$money(array_sum(array_column($rows, 'paid'))), 'Total outstanding'=>$money(array_sum(array_column($rows, 'outstanding')))]];
    break;

  case 'collections':
    $rows = analytics_collection_rows($pdo, $f);
    $columns = [
      ['key'=>'d','label'=>'Date','type'=>'text'],
      ['key'=>'mode','label'=>'Mode of Payment','type'=>'text'],
      ['key'=>'tx','label'=>'Transactions','type'=>'number'],
      ['key'=>'verified_tx','label'=>'Verified','type'=>'number'],
      ['key'=>'verified_amount','label'=>'Collected','type'=>'money'],
      ['key'=>'pending_amount','label'=>'Pending','type'=>'money'],
      ['key'=>'rejected_amount','label'=>'Rejected','type'=>'money'],
    ];
    $byMode = [];
    foreach ($rows as $r) $byMode[$r['mode']] = ($byMode[$r['mode']] ?? 0) + (float)$r['verified_amount'];
    $summary = ['Total collected'=>$money(array_sum(array_column($rows, 'verified_amount'))), 'Total pending'=>$money(array_sum(array_column($rows, 'pending_amount')))];
    foreach ($byMode as $m => $amt) $summary['Collected via '.$m] = $money($amt);
    $meta = ['title'=>'Collection Summary by Mode of Payment', 'sheet'=>'Collections', 'filename'=>'collection_summary_'.$stamp, 'summary'=>$summary];
    break;

  case 'logs':
    $rows = analytics_log_rows($pdo, $f, 5000);
    $columns = [
      ['key'=>'created_at','label'=>'Date/Time','type'=>'text'],
      ['key'=>'category','label'=>'Category','type'=>'text'],
      ['key'=>'full_name','label'=>'User','type'=>'text'],
      ['key'=>'username','label'=>'Username','type'=>'text'],
      ['key'=>'role','label'=>'Role','type'=>'text'],
      ['key'=>'action','label'=>'Activity','type'=>'text'],
      ['key'=>'ip_address','label'=>'IP Address','type'=>'text'],
    ];
    $meta = ['title'=>'System Logs (Security & Activity)', 'sheet'=>'Logs', 'filename'=>'system_logs_'.$stamp,
      'summary'=>['Log entries'=>number_format(count($rows)), 'Security events'=>number_format(count(array_filter($rows, fn($r) => $r['category'] === 'security')))]];
    break;

  default:
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Unknown report type.']);
    exit;
}

$meta['filters'] = analytics_filter_summary($f);
$meta['subtitle'] = 'Prepared by: '.($s['full_name'] ?? $s['username'] ?? 'User').' ('.strtoupper((string)$s['role']).')';
log_event($pdo, (int)$s['user_id'], 'Generated '.strtoupper($format === 'xlsx' ? 'Excel' : $format).' report: '.$meta['title'].' ('.count($rows).' rows)', 'activity');

if ($format === 'csv') report_write_csv($columns, $rows, $meta);
elseif ($format === 'xlsx') report_write_xlsx($columns, $rows, $meta);
else report_write_pdf($columns, $rows, $meta);
