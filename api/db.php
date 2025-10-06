<?php
// db.php
declare(strict_types=1);

// 1) Toma DATABASE_URL del entorno (Render > Environment).
// 2) Si no está definida, usa tu INTERNAL DB URL como fallback.
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a/licitaciones_bmd';
}

try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'], $p['user'], $p['pass'], $p['path'])) {
    throw new RuntimeException('DATABASE_URL inválida');
  }

  // Lee sslmode=? si viene en la URL; si no, decide:
  // - Host interno (no tiene "."): usa 'prefer'
  // - Host externo (tiene "."):  usa 'require'
  parse_str($p['query'] ?? '', $q);
  $isInternalHost = (strpos($p['host'], '.') === false);
  $sslmode = $q['sslmode'] ?? ($isInternalHost ? 'prefer' : 'require');

  $dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
    $p['host'],
    $p['port'] ?? 5432,
    ltrim($p['path'], '/'),
    $sslmode
  );

  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'DB: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
