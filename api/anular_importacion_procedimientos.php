<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ============================================================
   1. Conexión a la base de datos
   ============================================================ */
$DATABASE_URL = getenv('DATABASE_URL') ?: 
  'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;sslmode=require", $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Error conexión: ' . $e->getMessage()]);
  exit;
}

/* ============================================================
   2. Validar solicitud
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
  exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['import_id'])) {
  echo json_encode(['ok' => false, 'error' => 'Falta parámetro import_id']);
  exit;
}

$import_id = trim($data['import_id']);
$motivo = trim($data['motivo'] ?? 'Anulación manual');

/* ============================================================
   3. Verificar existencia antes de anular
   ============================================================ */
try {
  $check = $pdo->prepare("SELECT import_id, anulado_at FROM public.procedimientos_import_log WHERE import_id = :id");
  $check->execute([':id' => $import_id]);
  $row = $check->fetch();

  if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'No existe el ID de importación especificado.']);
    exit;
  }
  if (!empty($row['anulado_at'])) {
    echo json_encode(['ok' => false, 'error' => 'Esta importación ya fue anulada anteriormente.']);
    exit;
  }

  /* ============================================================
     4. Ejecutar función de anulación
     ============================================================ */
  $stmt = $pdo->prepare("SELECT public.anular_importacion(:id, :motivo)");
  $stmt->execute([':id' => $import_id, ':motivo' => $motivo]);

  echo json_encode([
    'ok' => true,
    'mensaje' => "✅ Importación $import_id anulada correctamente.",
    'motivo' => $motivo
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
