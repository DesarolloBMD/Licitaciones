<?php
// subir_procedimientos.php
declare(strict_types=1);
@ini_set('memory_limit','1G');
@set_time_limit(0);
@ini_set('upload_max_filesize','512M');
@ini_set('post_max_size','512M');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ==========================================================
   1. Conexión a PostgreSQL
   ========================================================== */
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
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]);
  exit;
}

/* ==========================================================
   2. Funciones helper
   ========================================================== */
function norm_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  foreach(['d/m/Y','Y-m-d','d-m-Y'] as $fmt){
    $dt = DateTime::createFromFormat($fmt,$s);
    if($dt) return $dt->format('Y-m-d');
  }
  return null;
}

function norm_num(?string $s): ?float {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = str_replace(['₡','$','CRC','USD',',',' '], '', $s);
  return is_numeric($s) ? (float)$s : null;
}

function clean_string(?string $s): ?string {
  $s = trim((string)$s);
  return $s === '' ? null : $s;
}

/* ==========================================================
   2.1. Consultar historial (GET)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'historial') {
  try {
    $stmt = $pdo->query("
      SELECT import_id, filename, mes_descarga, anio_descarga, inserted, skipped, total_rows, finished_at
      FROM public.procedimientos_import_log
      WHERE anulado_at IS NULL
      ORDER BY finished_at DESC
      LIMIT 50
    ");
    $rows = $stmt->fetchAll();
    echo json_encode(['ok'=>true, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'Error al cargar historial: '.$e->getMessage()]);
  }
  exit;
}

/* ==========================================================
   3. Validación de método y archivo
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido']);
  exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt'])) {
  echo json_encode(['ok'=>false,'error'=>'Solo se permiten archivos CSV o TXT']);
  exit;
}

/* ==========================================================
   4. Validación de duplicados
   ========================================================== */
$hash = md5_file($tmp);
$check = $pdo->prepare("
  SELECT COUNT(*) 
  FROM public.procedimientos_import_log 
  WHERE (filename = :f OR file_hash = :h)
    AND anulado_at IS NULL
");
$check->execute([':f'=>$name, ':h'=>$hash]);

if ($check->fetchColumn() > 0) {
  echo json_encode([
    'ok'=>false,
    'error'=>'⚠ Este archivo ya fue importado anteriormente. 
    Si desea volver a cargarlo, primero anule la importación desde el historial.'
  ]);
  exit;
}

/* ==========================================================
   5. Lectura del archivo y detección del delimitador
   ========================================================== */
$fh = fopen($tmp,'r');
if(!$fh){
  echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']);
  exit;
}

$firstLine = fgets($fh);
rewind($fh);

$delims = [','=>substr_count($firstLine,','), ';'=>substr_count($firstLine,';'), "\t"=>substr_count($firstLine,"\t")];
$delimiter = array_search(max($delims), $delims);
if (!$delimiter) $delimiter = ';';

$header = fgetcsv($fh, 0, $delimiter);
$header = array_map(fn($h)=>strtoupper(trim($h)), $header);

$expected = [
  'CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO','DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO',
  'MODALIDAD_PROCEDIMIENTO','FECHA_REV','CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR','PERFIL_PROV','CEDULA_REPRESENTANTE',
  'REPRESENTANTE','OBJETO_GASTO','MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD',
  'FECHA_ADJUD_FIRME','FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO','CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO',
  'MONEDA_PRECIO_EST','FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];

$diff = array_diff($expected, $header);
if(count($diff)>0){
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden con el formato esperado','faltan'=>$diff]);
  exit;
}

/* ==========================================================
   6. Preparar inserción
   ========================================================== */
$sqlCols = '"'.implode('","',$expected).'", "mes_descarga", "anio_descarga", "import_id"';
$sqlVals = implode(',', array_map(fn($c)=>':'.strtolower($c), $expected)).', :mes, :anio, :import_id';
$stmt = $pdo->prepare("INSERT INTO public.\"Procedimientos Adjudicados\" ($sqlCols) VALUES ($sqlVals)");

$insertados=0; $saltados=0; $errores=[];
$import_id = 'imp_'.uniqid();
$pdo->beginTransaction();

/* ==========================================================
   7. Leer e insertar filas
   ========================================================== */
while(($r=fgetcsv($fh,0,$delimiter))!==false){
  if(count(array_filter($r,fn($x)=>trim((string)$x)!=''))==0) continue;
  $params=[];
  foreach($expected as $i=>$col){
    $val = $r[$i] ?? null;
    switch($col){
      case 'FECHA_REV':
      case 'FECHA_ADJUD_FIRME':
      case 'FECHA_SOL_CONTRA':
      case 'FECHA_SOL_CONTRA_CL':
        $params[':'.strtolower($col)] = norm_date($val); break;
      case 'MONTO_ADJU_LINEA':
      case 'MONTO_ADJU_LINEA_CRC':
      case 'MONTO_ADJU_LINEA_USD':
      case 'MONTO_UNITARIO':
      case 'CANTIDAD':
        $params[':'.strtolower($col)] = norm_num($val); break;
      default:
        $params[':'.strtolower($col)] = clean_string($val);
    }
  }
  $params[':mes'] = $_POST['mes_descarga'] ?? null;
  $params[':anio'] = $_POST['anio_descarga'] ?? null;
  $params[':import_id'] = $import_id;
  try{ $stmt->execute($params); $insertados++; }
  catch(Throwable $e){ $saltados++; if($saltados<10)$errores[]=$e->getMessage(); }
}
$pdo->commit();
fclose($fh);

/* ==========================================================
   8. Registrar importación
   ========================================================== */
try{
  $pdo->prepare("
    INSERT INTO public.procedimientos_import_log
      (import_id, filename, mes_descarga, anio_descarga, total_rows, inserted, skipped, started_at, finished_at, source_ip, file_hash)
    VALUES
      (:id, :f, :m, :a, :t, :i, :s, NOW(), NOW(), :ip, :h)
  ")->execute([
    ':id'=>$import_id,
    ':f'=>$name,
    ':m'=>$_POST['mes_descarga'] ?? null,
    ':a'=>$_POST['anio_descarga'] ?? null,
    ':t'=>$insertados+$saltados,
    ':i'=>$insertados,
    ':s'=>$saltados,
    ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
    ':h'=>$hash
  ]);
}catch(Throwable $e){
  $errores[]='Error registrando importación: '.$e->getMessage();
}

/* ==========================================================
   9. Respuesta JSON
   ========================================================== */
echo json_encode([
  'ok'=>true,
  'insertados'=>$insertados,
  'saltados'=>$saltados,
  'total'=>$insertados+$saltados,
  'errores'=>$errores
],JSON_UNESCAPED_UNICODE);
