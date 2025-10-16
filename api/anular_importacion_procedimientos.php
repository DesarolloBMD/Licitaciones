<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ==========================================================
   Conexión a PostgreSQL
   ========================================================== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], $p['port'] ?? 5432, ltrim($p['path'],'/')
  );
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()]);
  exit;
}

/* ==========================================================
   Validación de parámetros
   ========================================================== */
$import_id = $_GET['id'] ?? $_POST['id'] ?? null;

if (!$import_id) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Falta el parámetro id']);
  exit;
}

/* ==========================================================
   Ejecución de la función anular_importacion()
   ========================================================== */
try {
  $stmt = $pdo->prepare("SELECT anular_importacion(:id, 'Anulación manual desde interfaz')");
  $stmt->execute([':id' => $import_id]);
  echo json_encode(['ok'=>true,'mensaje'=>'Importación anulada correctamente']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error al anular: '.$e->getMessage()]);
}
