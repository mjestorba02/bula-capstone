<?php
declare(strict_types=1);
/**
 * Database connection settings from the environment.
 * Priority: DATABASE_URL (HostForge managed DB) > DB_USERNAME/DB_PASSWORD > DB_USER/DB_PASS
 * (Docker Compose) > XAMPP defaults (root, no password, localhost).
 */
function db_env(): array {
  $docker = (bool)getenv('DOCKER_ENV');
  $cfg = [
    'host' => getenv('DB_HOST') ?: ($docker ? 'db' : 'localhost'),
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'bestlink_student_system'),
    'user' => getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: 'root'),
    'pass' => getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD')
            : (getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : ($docker ? 'root' : '')),
  ];
  $url = (string)(getenv('DATABASE_URL') ?: '');
  if ($url !== '' && preg_match('#^(mysql|mariadb)://#i', $url) && ($u = parse_url($url))) {
    if (!empty($u['host'])) $cfg['host'] = $u['host'];
    if (!empty($u['port'])) $cfg['port'] = (int)$u['port'];
    if (isset($u['user'])) $cfg['user'] = rawurldecode($u['user']);
    if (isset($u['pass'])) $cfg['pass'] = rawurldecode($u['pass']);
    if (!empty($u['path']) && trim($u['path'], '/') !== '') $cfg['name'] = rawurldecode(trim($u['path'], '/'));
  }
  return $cfg;
}
