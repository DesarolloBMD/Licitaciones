<?php
// api/subir_procedimientos.php
declare(strict_types=1);

@ini_set('memory_limit','1024M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ===========================================================
   🔹 CONEXIÓN A LA BASE DE DATOS
   =========================================================== */
$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], $p['port'] ?? 5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>'Error de conexión: '.$e->getMessage()]);
  exit;
}

/* ===========================================================
   🔹 VALIDAR ARCHIVO SUBIDO
   =========================================================== */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false, 'error'=>'Archivo no recibido']);
  exit;
}

$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

if (!in_array($ext, ['csv','xlsx'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Solo se permiten archivos .csv o .xlsx']);
  exit;
}

/* ===========================================================
   🔹 LEER ARCHIVO (CSV o XLSX)
   =========================================================== */
$rows = [];
try {
  if ($ext === 'csv') {
    $fh = fopen($tmp, 'r');
    $firstLine = fgets($fh);
    rewind($fh);

    $delims = ["," => substr_count($firstLine, ","), ";" => substr_count($firstLine, ";"), "\t" => substr_count($firstLine, "\t")];
    arsort($delims);
    $delim = array_key_first($delims);

    while (($data = fgetcsv($fh, 0, $delim)) !== false) {
      $rows[] = $data;
    }
    fclose($fh);
  } else {
    // XLSX — requiere PhpSpreadsheet
    require_once __DIR__.'/../vendor/autoload.php';
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $spreadsheet = $reader->load($tmp);
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($sheet->toArray() as $row) {
      $rows[] = $row;
    }
  }
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>'Error leyendo archivo: '.$e->getMessage()]);
  exit;
}

if (count($rows) < 2) {
  echo json_encode(['ok'=>false,'error'=>'El archivo está vacío o sin encabezados.']);
  exit;
}

/* ===========================================================
   🔹 VALIDAR ENCABEZADOS
   =========================================================== */
$headers = array_map('trim', $rows[0]);
$expected = [
  'Mes de Descarga','Año de reporte','CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO','MODALIDAD_PROCEDIMIENTO','fecha_rev',
  'CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR','PERFIL_PROV','CEDULA_REPRESENTANTE','REPRESENTANTE','OBJETO_GASTO',
  'MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD','FECHA_ADJUD_FIRME',
  'FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO','CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO',
  'MONEDA_PRECIO_EST','FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];

$diff = array_diff($expected, $headers);
if ($diff) {
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden','faltan'=>$diff]);
  exit;
}

/* ===========================================================
   🔹 INSERTAR EN LA BASE
   =========================================================== */
$insertados = 0;
$saltados = 0;
$errores = [];

try {
  $pdo->beginTransaction();

  $colsSql = '"' . implode('","', $expected) . '"';
  $placeSql = implode(',', array_map(fn($c)=>':'.preg_replace('/\W+/','_', strtolower($c)), $expected));

  $sql = "INSERT INTO public.\"Procedimientos Adjudicados\" ($colsSql)
          VALUES ($placeSql)";
  $stmt = $pdo->prepare($sql);

  for ($i=1; $i < count($rows); $i++) {
    $row = $rows[$i];
    if (count(array_filter($row)) === 0) continue;

    $params = [];
    foreach ($expected as $idx=>$key) {
      $ph = ':'.preg_replace('/\W+/','_', strtolower($key));
      $params[$ph] = isset($row[$idx]) ? trim((string)$row[$idx]) : null;
    }

    try {
      $stmt->execute($params);
      $insertados++;
    } catch (Throwable $e) {
      $saltados++;
      if (count($errores) < 10) $errores[] = "Fila $i: ".$e->getMessage();
    }
  }

  $pdo->commit();

} catch (Throwable $e) {
  $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'Error durante inserción: '.$e->getMessage()]);
  exit;
}

/* ===========================================================
   🔹 RESPUESTA FINAL
   =========================================================== */
echo json_encode([
  'ok'=>true,
  'insertados'=>$insertados,
  'saltados'=>$saltados,
  'errores'=>$errores,
  'total'=>count($rows)-1
], JSON_UNESCAPED_UNICODE);
