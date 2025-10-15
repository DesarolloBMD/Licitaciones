<?php
declare(strict_types=1);
@ini_set('memory_limit','2G');
@set_time_limit(0);

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Conexión a PostgreSQL
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://usuario:clave@host:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]); exit;
}

// Autoload PhpSpreadsheet
require_once __DIR__ . '/../autoload.php';

// Acciones GET
$action = $_GET['action'] ?? '';
if ($action === 'historial') {
  try {
    $rows = $pdo->query("SELECT import_id, filename, inserted, skipped, total_rows, finished_at 
                         FROM public.procedimientos_import_log
                         ORDER BY finished_at DESC LIMIT 30")->fetchAll();
    echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
  } catch(Throwable $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
}

if ($action === 'anular') {
  $id = $_GET['id'] ?? '';
  if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID no proporcionado']); exit; }
  try {
    $pdo->prepare("SELECT public.anular_importacion(:id,'Anulado desde interfaz')")->execute([':id'=>$id]);
    echo json_encode(['ok'=>true]); exit;
  } catch(Throwable $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
}

// Procesar carga Excel
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'Método no permitido']); exit; }
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']); exit; }

$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') { echo json_encode(['ok'=>false,'error'=>'Solo se permiten archivos Excel (.xlsx)']); exit; }

try {
  $spreadsheet = IOFactory::load($tmp);
  $sheet = $spreadsheet->getActiveSheet();
  $rows = $sheet->toArray(null, true, true, true);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error al leer Excel: '.$e->getMessage()]); exit;
