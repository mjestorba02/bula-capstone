<?php
declare(strict_types=1);

/**
 * Shared queries for the Dashboard (graphs + score cards), Data Gathering,
 * Records, Logs and Reports. Used by analytics_data.php and reports.php so the
 * screen and the exported files always show the same numbers.
 */

const ANALYTICS_STATUS_GROUPS = ['Verified','Pending','Rejected','Awaiting HMA'];
const ANALYTICS_MODES = ['HMA','Walk-in','Other'];

function analytics_filters(array $src): array {
  $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
  $pick = fn($v, array $allowed) => in_array((string)$v, $allowed, true) ? (string)$v : '';
  $f = [
    'date_from' => $date($src['date_from'] ?? ''),
    'date_to' => $date($src['date_to'] ?? ''),
    'level' => $pick($src['level'] ?? '', STUDENT_LEVELS),
    'year_level' => $pick($src['year_level'] ?? '', all_year_levels()),
    'mode' => $pick($src['mode'] ?? '', ANALYTICS_MODES),
    'status' => $pick($src['status'] ?? '', ANALYTICS_STATUS_GROUPS),
    'account_status' => $pick($src['account_status'] ?? '', ['Active','Inactive']),
    'balance' => $pick($src['balance'] ?? '', ['with_balance','fully_paid']),
    'category' => $pick($src['category'] ?? '', ['security','activity']),
    'search' => mb_substr(trim((string)($src['search'] ?? '')), 0, 80),
    'cashier_id' => max(0, (int)($src['cashier_id'] ?? 0)),
  ];
  if ($f['date_from'] !== '' && $f['date_to'] !== '' && $f['date_from'] > $f['date_to']) {
    [$f['date_from'], $f['date_to']] = [$f['date_to'], $f['date_from']];
  }
  return $f;
}

function analytics_status_sql(string $a = 'p'): string {
  return "CASE WHEN $a.status IN ('Verified','Paid') THEN 'Verified'
    WHEN $a.status IN ('Pending Verification','Pending Online Confirmation') THEN 'Pending'
    WHEN $a.status='Rejected' THEN 'Rejected'
    WHEN $a.status='Awaiting HelloMoney Payment' THEN 'Awaiting HMA'
    ELSE $a.status END";
}

function analytics_level_sql(string $u = 'u'): string {
  return "COALESCE(NULLIF($u.student_level,''),'College')";
}

function analytics_date_sql(string $a = 'p'): string {
  return "DATE(COALESCE($a.payment_date,$a.created_at))";
}

/** WHERE clause for payment queries (aliases: p = payments, u = student). */
function analytics_payment_where(array $f, array &$args, bool $withStatus = true): string {
  $w = ['1=1'];
  if ($f['date_from'] !== '') { $w[] = analytics_date_sql().' >= ?'; $args[] = $f['date_from']; }
  if ($f['date_to'] !== '') { $w[] = analytics_date_sql().' <= ?'; $args[] = $f['date_to']; }
  if ($f['level'] !== '') { $w[] = analytics_level_sql().' = ?'; $args[] = $f['level']; }
  if ($f['year_level'] !== '') { $w[] = 'u.year_level = ?'; $args[] = $f['year_level']; }
  if ($f['mode'] !== '') { $w[] = '('.payment_mode_sql('p').') = ?'; $args[] = $f['mode']; }
  if ($withStatus && $f['status'] !== '') { $w[] = '('.analytics_status_sql().') = ?'; $args[] = $f['status']; }
  if ($f['cashier_id'] > 0) { $w[] = 'p.cashier_id = ?'; $args[] = $f['cashier_id']; }
  if ($f['search'] !== '') {
    $w[] = '(u.full_name LIKE ? OR u.student_id LIKE ? OR u.username LIKE ? OR p.reference_number LIKE ?)';
    $like = '%'.$f['search'].'%';
    array_push($args, $like, $like, $like, $like);
  }
  return implode(' AND ', $w);
}

function analytics_payment_rows(PDO $pdo, array $f, ?int $limit = null, int $offset = 0): array {
  $args = [];
  $where = analytics_payment_where($f, $args);
  $sql = "SELECT p.id, ".analytics_date_sql()." AS payment_date, p.created_at, u.full_name AS student_name, u.student_id,
      ".analytics_level_sql()." AS student_level, COALESCE(u.year_level,'') AS year_level,
      COALESCE(f.name,'General Payment') AS fee_name, COALESCE(pi.name,'') AS period,
      (".payment_mode_sql('p').") AS mode, p.payment_method, p.payment_channel, p.amount, p.reference_number,
      p.status, (".analytics_status_sql().") AS status_group, COALESCE(c.full_name,'') AS cashier_name,
      COALESCE(p.receipt_number,'') AS receipt_number
    FROM payments p
    INNER JOIN users u ON u.id=p.user_id
    LEFT JOIN fee_items f ON f.id=p.fee_item_id
    LEFT JOIN payment_installments pi ON pi.id=p.installment_id
    LEFT JOIN users c ON c.id=p.cashier_id
    WHERE $where
    ORDER BY COALESCE(p.payment_date,p.created_at) DESC, p.id DESC";
  if ($limit !== null) $sql .= ' LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset);
  $q = $pdo->prepare($sql);
  $q->execute($args);
  return $q->fetchAll();
}

function analytics_payment_totals(PDO $pdo, array $f): array {
  $args = [];
  $where = analytics_payment_where($f, $args);
  $q = $pdo->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(p.amount),0) AS amount,
      COALESCE(SUM(CASE WHEN p.status IN ('Verified','Paid') THEN p.amount ELSE 0 END),0) AS verified_amount
    FROM payments p INNER JOIN users u ON u.id=p.user_id WHERE $where");
  $q->execute($args);
  $r = $q->fetch() ?: [];
  return ['records'=>(int)($r['records'] ?? 0), 'amount'=>round((float)($r['amount'] ?? 0), 2), 'verified_amount'=>round((float)($r['verified_amount'] ?? 0), 2)];
}

/** Month keys (YYYY-MM) shown on the monthly graph. */
function analytics_months(array $f): array {
  $end = $f['date_to'] !== '' ? new DateTimeImmutable($f['date_to']) : new DateTimeImmutable('today');
  $start = $f['date_from'] !== '' ? new DateTimeImmutable($f['date_from']) : $end->modify('-11 months');
  $start = $start->modify('first day of this month');
  $end = $end->modify('first day of this month');
  if ($start > $end) $start = $end;
  $months = [];
  for ($m = $start; $m <= $end && count($months) < 24; $m = $m->modify('+1 month')) $months[] = $m->format('Y-m');
  if (count($months) >= 24) $months = array_slice($months, -24);
  return $months;
}

function analytics_dashboard(PDO $pdo, array $f, bool $includeReceivables = true): array {
  $statusSql = analytics_status_sql();
  $modeSql = payment_mode_sql('p');
  $dateSql = analytics_date_sql();
  $args = [];
  $where = analytics_payment_where($f, $args, false);
  $base = "FROM payments p INNER JOIN users u ON u.id=p.user_id LEFT JOIN fee_items fi ON fi.id=p.fee_item_id LEFT JOIN payment_installments pi ON pi.id=p.installment_id WHERE $where";
  $run = function (string $select, string $tail = '') use ($pdo, $base, $args) {
    $q = $pdo->prepare("SELECT $select $base $tail");
    $q->execute($args);
    return $q->fetchAll();
  };
  $verified = "p.status IN ('Verified','Paid')";

  // ---- Score cards ----------------------------------------------------------
  $today = date('Y-m-d');
  $k = $run("COUNT(*) tx,
      COALESCE(SUM(CASE WHEN $verified THEN p.amount ELSE 0 END),0) collected,
      COALESCE(SUM(CASE WHEN $verified THEN 1 ELSE 0 END),0) verified_count,
      COALESCE(SUM(CASE WHEN ($statusSql)='Pending' THEN 1 ELSE 0 END),0) pending_count,
      COALESCE(SUM(CASE WHEN ($statusSql)='Pending' THEN p.amount ELSE 0 END),0) pending_amount,
      COALESCE(SUM(CASE WHEN ($statusSql)='Rejected' THEN 1 ELSE 0 END),0) rejected_count,
      COALESCE(SUM(CASE WHEN ($statusSql)='Awaiting HMA' THEN 1 ELSE 0 END),0) awaiting_count,
      COALESCE(SUM(CASE WHEN $verified AND ($modeSql)='HMA' THEN p.amount ELSE 0 END),0) hma_collected,
      COALESCE(SUM(CASE WHEN $verified AND ($modeSql)='Walk-in' THEN p.amount ELSE 0 END),0) walkin_collected,
      COALESCE(SUM(CASE WHEN $verified AND DATE(COALESCE(p.verified_at,p.payment_date,p.created_at))='$today' THEN p.amount ELSE 0 END),0) today_collected,
      COUNT(DISTINCT p.user_id) paying_students")[0] ?? [];

  $sArgs = [];
  $sWhere = ["u.role='user'"];
  if ($f['level'] !== '') { $sWhere[] = analytics_level_sql().' = ?'; $sArgs[] = $f['level']; }
  if ($f['year_level'] !== '') { $sWhere[] = 'u.year_level = ?'; $sArgs[] = $f['year_level']; }
  $sq = $pdo->prepare("SELECT u.id, u.status, ".analytics_level_sql()." AS lvl FROM users u WHERE ".implode(' AND ', $sWhere));
  $sq->execute($sArgs);
  $students = $sq->fetchAll();
  $studentCounts = ['total'=>count($students), 'active'=>0, 'College'=>0, 'SHS'=>0];
  $assessment = $paidAll = $outstanding = 0.0; $fullyPaid = 0;
  foreach ($students as $s) {
    if ($s['status'] === 'Active') $studentCounts['active']++;
    $studentCounts[$s['lvl'] === 'SHS' ? 'SHS' : 'College']++;
    if ($includeReceivables) {
      $sum = get_student_financial_summary($pdo, (int)$s['id']);
      $assessment += (float)$sum['adjusted_assessment'];
      $paidAll += (float)$sum['verified_paid'];
      $outstanding += (float)$sum['outstanding'];
      if ((float)$sum['outstanding'] <= 0) $fullyPaid++;
    }
  }
  $tx = (int)($k['tx'] ?? 0);
  $scorecards = [
    'total_collected' => round((float)($k['collected'] ?? 0), 2),
    'today_collected' => round((float)($k['today_collected'] ?? 0), 2),
    'transactions' => $tx,
    'verified_count' => (int)($k['verified_count'] ?? 0),
    'pending_count' => (int)($k['pending_count'] ?? 0),
    'pending_amount' => round((float)($k['pending_amount'] ?? 0), 2),
    'rejected_count' => (int)($k['rejected_count'] ?? 0),
    'awaiting_hma_count' => (int)($k['awaiting_count'] ?? 0),
    'hma_collected' => round((float)($k['hma_collected'] ?? 0), 2),
    'walkin_collected' => round((float)($k['walkin_collected'] ?? 0), 2),
    'paying_students' => (int)($k['paying_students'] ?? 0),
    'average_payment' => (int)($k['verified_count'] ?? 0) > 0 ? round((float)$k['collected'] / (int)$k['verified_count'], 2) : 0,
    'verification_rate' => $tx > 0 ? round(((int)$k['verified_count'] / $tx) * 100, 1) : 0,
    'students_total' => $studentCounts['total'],
    'students_active' => $studentCounts['active'],
    'students_college' => $studentCounts['College'],
    'students_shs' => $studentCounts['SHS'],
  ];
  if ($includeReceivables) {
    $scorecards += [
      'total_assessment' => round($assessment, 2),
      'total_paid_all_time' => round($paidAll, 2),
      'outstanding_receivables' => round($outstanding, 2),
      'collection_rate' => $assessment > 0 ? round(($paidAll / $assessment) * 100, 1) : 0,
      'fully_paid_students' => $fullyPaid,
    ];
  }

  // ---- Graphs ---------------------------------------------------------------
  $months = analytics_months($f);
  $monthly = ['labels'=>[], 'HMA'=>[], 'Walk-in'=>[], 'Other'=>[]];
  $mRows = $run("DATE_FORMAT($dateSql,'%Y-%m') ym, ($modeSql) mode, SUM(p.amount) amt", "AND $verified GROUP BY ym, mode");
  $mMap = [];
  foreach ($mRows as $r) $mMap[$r['ym']][$r['mode']] = (float)$r['amt'];
  foreach ($months as $ym) {
    $monthly['labels'][] = (new DateTimeImmutable($ym.'-01'))->format('M Y');
    foreach (ANALYTICS_MODES as $m) $monthly[$m][] = round($mMap[$ym][$m] ?? 0, 2);
  }

  $dEnd = $f['date_to'] !== '' ? new DateTimeImmutable($f['date_to']) : new DateTimeImmutable('today');
  $dStart = $dEnd->modify('-29 days');
  if ($f['date_from'] !== '' && new DateTimeImmutable($f['date_from']) > $dStart) $dStart = new DateTimeImmutable($f['date_from']);
  $dRows = $run("$dateSql d, COUNT(*) tx, SUM(CASE WHEN $verified THEN p.amount ELSE 0 END) amt", "AND $dateSql BETWEEN '".$dStart->format('Y-m-d')."' AND '".$dEnd->format('Y-m-d')."' GROUP BY d");
  $dMap = [];
  foreach ($dRows as $r) $dMap[$r['d']] = $r;
  $daily = ['labels'=>[], 'transactions'=>[], 'collected'=>[]];
  for ($d = $dStart; $d <= $dEnd; $d = $d->modify('+1 day')) {
    $key = $d->format('Y-m-d');
    $daily['labels'][] = $d->format('M j');
    $daily['transactions'][] = (int)($dMap[$key]['tx'] ?? 0);
    $daily['collected'][] = round((float)($dMap[$key]['amt'] ?? 0), 2);
  }

  $pairs = function (array $rows, string $labelKey, array $order = []) {
    $map = [];
    foreach ($rows as $r) $map[(string)$r[$labelKey]] = ['count'=>(int)$r['cnt'], 'amount'=>round((float)$r['amt'], 2)];
    $labels = $order ?: array_keys($map);
    foreach (array_keys($map) as $l) if (!in_array($l, $labels, true)) $labels[] = $l;
    return ['labels'=>$labels, 'count'=>array_map(fn($l) => $map[$l]['count'] ?? 0, $labels), 'amount'=>array_map(fn($l) => $map[$l]['amount'] ?? 0, $labels)];
  };
  $status = $pairs($run("($statusSql) grp, COUNT(*) cnt, SUM(p.amount) amt", "GROUP BY grp"), 'grp', ANALYTICS_STATUS_GROUPS);
  $modes = $pairs($run("($modeSql) grp, COUNT(*) cnt, SUM(CASE WHEN $verified THEN p.amount ELSE 0 END) amt", "GROUP BY grp"), 'grp', ['HMA','Walk-in']);
  $levels = $pairs($run(analytics_level_sql()." grp, COUNT(*) cnt, SUM(CASE WHEN $verified THEN p.amount ELSE 0 END) amt", "GROUP BY grp"), 'grp', STUDENT_LEVELS);
  $fees = $pairs($run("COALESCE(fi.name,'General Payment') grp, COUNT(*) cnt, SUM(p.amount) amt", "AND $verified GROUP BY grp ORDER BY amt DESC"), 'grp');
  $periods = $pairs($run("COALESCE(pi.name,'Not specified') grp, COUNT(*) cnt, SUM(p.amount) amt", "AND $verified GROUP BY grp, pi.sort_order ORDER BY pi.sort_order IS NULL, pi.sort_order"), 'grp');

  return [
    'scorecards' => $scorecards,
    'charts' => ['monthly'=>$monthly, 'daily'=>$daily, 'status'=>$status, 'modes'=>$modes, 'levels'=>$levels, 'fees'=>$fees, 'periods'=>$periods],
    'recent' => analytics_payment_rows($pdo, $f, 8),
  ];
}

/** Data Gathering: one row per student with assessment, payments and balance. */
function analytics_student_rows(PDO $pdo, array $f): array {
  $w = ["u.role='user'"]; $args = [];
  if ($f['level'] !== '') { $w[] = analytics_level_sql().' = ?'; $args[] = $f['level']; }
  if ($f['year_level'] !== '') { $w[] = 'u.year_level = ?'; $args[] = $f['year_level']; }
  if ($f['account_status'] !== '') { $w[] = 'u.status = ?'; $args[] = $f['account_status']; }
  if ($f['search'] !== '') { $w[] = '(u.full_name LIKE ? OR u.student_id LIKE ? OR u.username LIKE ? OR u.email LIKE ?)'; $like = '%'.$f['search'].'%'; array_push($args, $like, $like, $like, $like); }
  $q = $pdo->prepare("SELECT u.id, u.student_id, u.full_name, u.username, COALESCE(u.email,'') email, ".analytics_level_sql()." student_level, COALESCE(u.year_level,'') year_level, u.status,
      (SELECT MAX(COALESCE(p.payment_date,p.created_at)) FROM payments p WHERE p.user_id=u.id AND p.status IN ('Verified','Paid')) last_payment
    FROM users u WHERE ".implode(' AND ', $w)."
    ORDER BY ".analytics_level_sql().", CASE u.year_level WHEN '1st Year' THEN 1 WHEN '2nd Year' THEN 2 WHEN '3rd Year' THEN 3 WHEN '4th Year' THEN 4 WHEN 'Grade 11' THEN 5 WHEN 'Grade 12' THEN 6 ELSE 7 END, u.full_name, u.id");
  $q->execute($args);
  $rows = [];
  foreach ($q->fetchAll() as $u) {
    $s = get_student_financial_summary($pdo, (int)$u['id']);
    $outstanding = (float)$s['outstanding'];
    if ($f['balance'] === 'with_balance' && $outstanding <= 0) continue;
    if ($f['balance'] === 'fully_paid' && $outstanding > 0) continue;
    $rows[] = [
      'id'=>(int)$u['id'], 'student_id'=>$u['student_id'] ?: '', 'full_name'=>$u['full_name'], 'username'=>$u['username'], 'email'=>$u['email'],
      'student_level'=>$u['student_level'], 'year_level'=>$u['year_level'] ?: 'Not set', 'account_status'=>$u['status'],
      'assessment'=>(float)$s['total_assessment'], 'discount'=>(float)$s['discount_amount'], 'adjusted_assessment'=>(float)$s['adjusted_assessment'],
      'paid'=>(float)$s['verified_paid'], 'pending'=>(float)$s['pending_paid'], 'outstanding'=>$outstanding, 'progress'=>(float)$s['progress'],
      'last_payment'=>$u['last_payment'] ? substr((string)$u['last_payment'], 0, 10) : '',
    ];
  }
  return $rows;
}

function analytics_log_where(array $f, array &$args): string {
  $w = ['1=1'];
  if ($f['category'] !== '') { $w[] = 'a.category = ?'; $args[] = $f['category']; }
  if ($f['date_from'] !== '') { $w[] = 'DATE(a.created_at) >= ?'; $args[] = $f['date_from']; }
  if ($f['date_to'] !== '') { $w[] = 'DATE(a.created_at) <= ?'; $args[] = $f['date_to']; }
  if ($f['search'] !== '') { $w[] = '(a.action LIKE ? OR u.username LIKE ? OR u.full_name LIKE ? OR a.ip_address LIKE ?)'; $like = '%'.$f['search'].'%'; array_push($args, $like, $like, $like, $like); }
  return implode(' AND ', $w);
}

function analytics_log_rows(PDO $pdo, array $f, ?int $limit = null, int $offset = 0): array {
  ensure_audit_log_columns($pdo);
  $args = [];
  $where = analytics_log_where($f, $args);
  $sql = "SELECT a.id, a.created_at, a.category, a.action, COALESCE(a.ip_address,'') ip_address,
      COALESCE(u.username,'') username, COALESCE(u.full_name,'System / Unknown') full_name, COALESCE(u.role,'') role
    FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE $where ORDER BY a.created_at DESC, a.id DESC";
  if ($limit !== null) $sql .= ' LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset);
  $q = $pdo->prepare($sql);
  $q->execute($args);
  return $q->fetchAll();
}

function analytics_log_count(PDO $pdo, array $f): int {
  ensure_audit_log_columns($pdo);
  $args = [];
  $where = analytics_log_where($f, $args);
  $q = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE $where");
  $q->execute($args);
  return (int)$q->fetchColumn();
}

/** Collection summary (reconciliation): per day and mode of payment. */
function analytics_collection_rows(PDO $pdo, array $f): array {
  $args = [];
  $where = analytics_payment_where($f, $args, false);
  $q = $pdo->prepare("SELECT ".analytics_date_sql()." d, (".payment_mode_sql('p').") mode, COUNT(*) tx,
      SUM(CASE WHEN p.status IN ('Verified','Paid') THEN 1 ELSE 0 END) verified_tx,
      COALESCE(SUM(CASE WHEN p.status IN ('Verified','Paid') THEN p.amount ELSE 0 END),0) verified_amount,
      COALESCE(SUM(CASE WHEN (".analytics_status_sql().")='Pending' THEN p.amount ELSE 0 END),0) pending_amount,
      COALESCE(SUM(CASE WHEN p.status='Rejected' THEN p.amount ELSE 0 END),0) rejected_amount
    FROM payments p INNER JOIN users u ON u.id=p.user_id WHERE $where GROUP BY d, mode ORDER BY d DESC, mode");
  $q->execute($args);
  return $q->fetchAll();
}

function analytics_filter_summary(array $f): string {
  $parts = [];
  $parts[] = 'Date: '.(($f['date_from'] ?: 'Start').' to '.($f['date_to'] ?: 'Today'));
  if ($f['level'] !== '') $parts[] = 'Level: '.$f['level'];
  if ($f['year_level'] !== '') $parts[] = 'Year: '.$f['year_level'];
  if ($f['mode'] !== '') $parts[] = 'Mode: '.$f['mode'];
  if ($f['status'] !== '') $parts[] = 'Status: '.$f['status'];
  if ($f['account_status'] !== '') $parts[] = 'Account: '.$f['account_status'];
  if ($f['balance'] !== '') $parts[] = 'Balance: '.($f['balance'] === 'fully_paid' ? 'Fully paid' : 'With balance');
  if ($f['category'] !== '') $parts[] = 'Category: '.ucfirst($f['category']);
  if ($f['search'] !== '') $parts[] = 'Search: "'.$f['search'].'"';
  return implode(' | ', $parts);
}
