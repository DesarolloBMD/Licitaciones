<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ==========================================================
   1. Validar método y parámetro
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
  exit;
}

$import_id = $_GET['id'] ?? null;
if (!$import_id) {
  echo json_encode(['ok' => false, 'error' => 'Falta el parámetro id']);
  exit;
}

/* ==========================================================
   2. Conexión a PostgreSQL
   ========================================================== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'],
    $p['port'] ?? 5432,
    ltrim($p['path'], '/')
  );
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Error conexión: ' . $e->getMessage()]);
  exit;
}

/* ==========================================================
   3. Ejecutar anulación
   ========================================================== */
try {
  // Llama la función de PostgreSQL que ya creaste
  $stmt = $pdo->prepare("SELECT public.anular_importacion(:id, :motivo)");
  $stmt->execute([
    ':id' => $import_id,
    ':motivo' => 'Anulado manualmente desde panel web'
  ]);

  echo json_encode([
    'ok' => true,
    'msg' => "✅ Importación $import_id anulada correctamente"
  ]);
} catch (Throwable $e) {
  echo json_encode([
    'ok' => false,
    'error' => 'Error al anular importación: ' . $e->getMessage()
  ]);
}
