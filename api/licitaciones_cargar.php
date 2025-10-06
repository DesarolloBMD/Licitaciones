<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok?200:400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) jexit(false, ['error'=>'ID inválido']);

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $parts = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
    $parts['host'],
    $parts['port'] ?? 5432,
    ltrim($parts['path'],'/'),
    (parse_str($parts['query'] ?? '', $qs) ? ($qs['sslmode'] ?? 'require') : 'require')
  );
  $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) { jexit(false, ['error'=>'DB: '.$e->getMessage()]); }

$sql = "SELECT * FROM public.licitaciones WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id'=>$id]);
$row = $stmt->fetch();

if (!$row) jexit(false, ['error'=>'No encontrado']);
jexit(true, ['row'=>$row]);
