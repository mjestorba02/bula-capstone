<?php
// Container start-up: wait for the database, create every table from database.sql,
// then run backend/setup.php (idempotent: adds missing columns, settings and the
// default accounts without touching existing records).
declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'db';
$port = (int)(getenv('DB_PORT') ?: 3306);
$db   = getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'bestlink_student_system');
$user = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : (string)(getenv('DB_PASSWORD') ?: '');

$pdo = null;
for ($i = 1; $i <= 30; $i++) {
  try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    break;
  } catch (Throwable $e) {
    fwrite(STDERR, "[bootstrap] database not ready ($i/30): {$e->getMessage()}\n");
    sleep(2);
  }
}
if (!$pdo) { fwrite(STDERR, "[bootstrap] giving up; Apache starts anyway.\n"); exit(0); }

try { $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) {}
$pdo->exec("USE `$db`");

$sql = (string)file_get_contents(__DIR__ . '/../database.sql');
// The managed database has its own generated name, so skip CREATE DATABASE / USE.
$sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql)))) as $stmt) {
  if (preg_match('/^(--[^\n]*\n\s*)*$/', $stmt . "\n")) continue;
  try { $pdo->exec($stmt); } catch (Throwable $e) { fwrite(STDERR, "[bootstrap] schema: {$e->getMessage()}\n"); }
}

ob_start();
require __DIR__ . '/../backend/setup.php';
$out = (string)ob_get_clean();
fwrite(STDERR, '[bootstrap] ' . strtok($out, "\n") . "\n");
