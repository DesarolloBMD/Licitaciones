<?php
// db.php
declare(strict_types=1);

// Usa la var de entorno si existe; si no, usa tu EXTERNAL URL (Render Postgres) con sslmode=require
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'], $p['user'], $p['pass'], $p['path'])) {
    throw new RuntimeException('DATABASE_URL inválida');
  }
  parse_str($p['query'] ?? '', $q);
  $sslmode = $q['sslmode'] ?? 'require';

  $dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
    $p['host'],
    $p['port'] ?? 5432,
    ltrim($p['path'], '/'),
    $sslmode
  );

  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'DB: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
