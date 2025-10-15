<?php
// ================================================
// subir_procedimientos.php
// Procesa archivos Excel (.xlsx) y registra importaciones
// ================================================

declare(strict_types=1);
@ini_set('memory_limit','2G');
@set_time_limit(0);

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---------------------------------------------
// 1️⃣ Conexión a PostgreSQL
// ---------------------------------------------
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], $p['port']??5432, ltrim($p['path'],'/')
  );
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]);
  exit;
}

// ---------------------------------------------
// 2️⃣ Dependencia PhpSpreadsheet
// ---------------------------------------------
require_once __DIR__ . '/../../vendor/autoload.php';

// ---------------------------------------------
// 3️⃣ Acciones GET (historial / anular)
// ---------------------------------------------
$action = $_GET['action'] ?? '';

if ($action === 'historial') {
  try {
    $stmt = $pdo->query("SELECT import_id, filename, inserted, skipped, total_rows, started_at, finished_at 
                         FROM public.procedimientos_import_log
                         ORDER BY started_at DESC LIMIT 30");
    echo json_encode(['ok'=>true,'rows'=>$stmt->fetchAll()]);
  } catch(Throwable $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

if ($action === 'anular') {
  $id = $_GET['id'] ?? '';
  if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID no proporcionado']); exit; }
  try {
    $pdo->prepare("SELECT public.anular_importacion(:id,'Anulado desde interfaz')")->execute([':id'=>$id]);
    echo json_encode(['ok'=>true]);
  } catch(Throwable $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

// ---------------------------------------------
// 4️⃣ Validar carga Excel
// ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'Método no permitido']); exit;
}
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']); exit;
}

$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
  echo json_encode(['ok'=>false,'error'=>'Solo se permiten archivos Excel (.xlsx)']); exit;
}

// ---------------------------------------------
// 5️⃣ Leer archivo Excel con PhpSpreadsheet
// ---------------------------------------------
try {
  $spreadsheet = IOFactory::load($tmp);
  $sheet = $spreadsheet->getActiveSheet();
  $rows = $sheet->toArray(null, true, true, true);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error al leer el archivo Excel: '.$e->getMessage()]);
  exit;
}

if (count($rows) < 2) {
  echo json_encode(['ok'=>false,'error'=>'El archivo no contiene datos']); exit;
}

// ---------------------------------------------
// 6️⃣ Preparar importación
// ---------------------------------------------
$import_id = uniqid('imp_');
$insertados = 0;
$saltados = 0;
$total = count($rows) - 1;
$inicio = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

// Generar huella del archivo
$fingerprint = md5_file($tmp);

// Registrar importación en log
try {
  $pdo->prepare("INSERT INTO public.procedimientos_import_log 
    (import_id, filename, total_rows, inserted, skipped, started_at, source_ip, fingerprint)
    VALUES (:id,:f,:t,0,0,:start,:ip,:fp)")
  ->execute([
    ':id'=>$import_id, ':f'=>$name, ':t'=>$total, ':start'=>$inicio,
    ':ip'=>$ip, ':fp'=>$fingerprint
  ]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error al registrar en log: '.$e->getMessage()]);
  exit;
}

// ---------------------------------------------
// 7️⃣ Insertar filas
// ---------------------------------------------
try {
  $pdo->beginTransaction();

  $cols = [
    'mes_descarga','anio_descarga','mes_adjudicacion','anio_adjudicacion','cedula',
    'institucion','anio','numero_procedimiento','descripcion_procedimiento','linea',
    'numero_sicop','tipo_procedimiento','modalidad_procedimiento','fecha_rev',
    'cedula_proveedor','nombre_proveedor','perfil_proveedor','cedula_representante',
    'representante','objeto_gasto','moneda_adjudicada','monto_adjudicado_linea',
    'monto_adjudicado_linea_crc','monto_adjudicado_linea_usd','fecha_adjudicacion_firma',
    'fecha_solicitud_contrato','producto_id','descripcion_bien_servicio','cantidad',
    'unidad_medida','monto_unitario','moneda_precio','fecha_solicitud_contrato_cl',
    'producto_id_cl','import_id'
  ];

  $placeholders = implode(',', array_map(fn($c)=>':'.$c, $cols));
  $sql = 'INSERT INTO public."Procedimientos Adjudicados" (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
  $stmt = $pdo->prepare($sql);

  // recorrer las filas del Excel
  for ($i=2; $i <= count($rows); $i++) {
    $r = $rows[$i];
    if (!$r) continue;

    $params = [];
    $idx = 0;
    foreach ($cols as $c) {
      if ($c === 'import_id') { $params[':import_id'] = $import_id; continue; }

      $val = array_values($r)[$idx] ?? null;
      $idx++;

      // normalizar fechas y números
      if (preg_match('/fecha/i', $c) && !empty($val)) {
        $ts = strtotime(str_replace('/', '-', trim((string)$val)));
        $val = $ts ? date('Y-m-d', $ts) : null;
      } elseif (preg_match('/monto|cantidad|anio|numero|id/i', $c)) {
        $val = preg_replace('/[^\d\.\-]/','',$val);
        $val = $val !== '' ? (float)$val : null;
      }
      $params[':'.$c] = $val;
    }

    try {
      $stmt->execute($params);
      $insertados++;
    } catch(Throwable $e){
      $saltados++;
      if ($saltados < 5) error_log("Fila $i: ".$e->getMessage());
    }
  }

  $pdo->commit();
} catch(Throwable $e){
  $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'Error al insertar datos: '.$e->getMessage()]);
  exit;
}

// ---------------------------------------------
// 8️⃣ Actualizar log de importación
// ---------------------------------------------
$fin = date('Y-m-d H:i:s');
try {
  $pdo->prepare("UPDATE public.procedimientos_import_log 
                 SET inserted=:i, skipped=:s, finished_at=:f 
                 WHERE import_id=:id")
      ->execute([':i'=>$insertados, ':s'=>$saltados, ':f'=>$fin, ':id'=>$import_id]);
} catch(Throwable $e){
  error_log("Error actualizando log: ".$e->getMessage());
}

// ---------------------------------------------
// 9️⃣ Respuesta final
// ---------------------------------------------
echo json_encode([
  'ok'=>true,
  'insertados'=>$insertados,
  'saltados'=>$saltados,
  'total'=>$total,
  'import_id'=>$import_id
], JSON_UNESCAPED_UNICODE);

?>
