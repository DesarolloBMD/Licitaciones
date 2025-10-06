<?php
// db.php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  parse_str($p['query'] ?? '', $q);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
    $p['host'], $p['port'] ?? 5432, ltrim($p['path'],'/'), $q['sslmode'] ?? 'require'
  );
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'DB: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
