<?php
declare(strict_types=1);
@ini_set('memory_limit', '2G');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ==========================================================
   1. Conexión a la base de datos
   ========================================================== */
try {
  $DATABASE_URL = getenv('DATABASE_URL');
  if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
    $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
  }

  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port']??5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]);
  exit;
}

/* ==========================================================
   2. Acciones de historial / anular
   ========================================================== */
$action = $_GET['action'] ?? '';
if ($action === 'historial') {
  try {
    $rows = $pdo->query("SELECT import_id, filename, inserted, skipped, total_rows, finished_at 
                         FROM public.procedimientos_import_log 
                         ORDER BY finished_at DESC LIMIT 50")->fetchAll();
    echo json_encode(['ok'=>true,'rows'=>$rows]);
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

/* ==========================================================
   3. Validación del archivo CSV
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'Método no permitido']); exit;
}
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']); exit;
}

$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'])) {
  echo json_encode(['ok'=>false,'error'=>'Solo se permiten archivos .csv o .txt']); exit;
}

/* ==========================================================
   4. Lectura del CSV (auto detección delimitador)
   ========================================================== */
$firstLine = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? '';
$delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
$fh = fopen($tmp, 'r');

// Detectar encoding y convertir a UTF-8
$sample = fread($fh, 10000);
rewind($fh);
$encoding = mb_detect_encoding($sample, ['UTF-8','ISO-8859-1','WINDOWS-1252'], true);
if ($encoding && $encoding !== 'UTF-8') {
  $content = file_get_contents($tmp);
  $content = mb_convert_encoding($content, 'UTF-8', $encoding);
  file_put_contents($tmp, $content);
  fclose($fh);
  $fh = fopen($tmp, 'r');
}

// Leer encabezado
$header = fgetcsv($fh, 0, $delimiter);
if (!$header) {
  echo json_encode(['ok'=>false,'error'=>'No se pudo leer el encabezado del CSV']);
  exit;
}
$header = array_map(fn($h)=>trim(strtoupper($h)), $header);

/* ==========================================================
   5. Validar columnas esperadas
   ========================================================== */
$expected = [
 'MES DE DESCARGA','AÑO DE DESCARGA','MES DE ADJUDICACIÓN','AÑO DE ADJUDICACIÓN','CÉDULA','INSTITUCIÓN','AÑO','NUMERO DE PROCEDIMIENTO',
 'DESCRIPCIÓN DE PROCEDIMIENTO','LINEA','NUMERO DE SICOP','TIPO DE PROCEDIMIENTO','MODALIDAD DE PROCEDIMIENTO','FECHA REV',
 'CÉDULA DEL PROVEEDOR','NOMBRE DEL PROVEEDOR','PERFIL DEL PROVEEDOR','CÉDULA DEL REPRESENTANTE',
 'REPRESENTANTE','OBJETO GASTO','MONEDA ADJUDICADA','MONTO ADJUDICADO POR LINEA','MONTO ADJUDICADO POR LINEA CRC',
 'MONTO ADJUDICADO POR LINEA USD','FECHA ADJUDICACIÓN FIRMA','FECHA SOLICITUD DE CONTRATO','PRODUCTO ID',
 'DESCRIPCIÓN DEL BIEN Y SERVICIO','CANTIDAD','UNIDAD DE MEDIDA','MONTO UNITARIO','MONEDA DE PRECIO',
 'FECHA SOLICITUD DE CONTRATO CL','PRODUCTO ID CL'
];

$missing = array_diff($expected, $header);
if ($missing) {
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden con el formato esperado. Faltan: '.implode(', ',$missing)]);
  exit;
}

/* ==========================================================
   6. Inserción a la base de datos
   ========================================================== */
$import_id = uniqid('imp_');
$insertados = 0;
$saltados = 0;
$total = 0;
$fingerprint = md5_file($tmp);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'localhost';

$pdo->prepare("INSERT INTO public.procedimientos_import_log
(import_id, filename, total_rows, inserted, skipped, started_at, source_ip, fingerprint)
VALUES (:id, :f, 0, 0, 0, now(), :ip, :fp)")
->execute([':id'=>$import_id,':f'=>$name,':ip'=>$ip,':fp'=>$fingerprint]);

$cols = [
 'mes_descarga','anio_descarga','mes_adjudicacion','anio_adjudicacion','cedula','institucion','anio','numero_procedimiento',
 'descripcion_procedimiento','linea','numero_sicop','tipo_procedimiento','modalidad_procedimiento','fecha_rev',
 'cedula_proveedor','nombre_proveedor','perfil_proveedor','cedula_representante','representante','objeto_gasto',
 'moneda_adjudicada','monto_adjudicado_linea','monto_adjudicado_linea_crc','monto_adjudicado_linea_usd','fecha_adjudicacion_firma',
 'fecha_solicitud_contrato','producto_id','descripcion_bien_servicio','cantidad','unidad_medida','monto_unitario',
 'moneda_precio','fecha_solicitud_contrato_cl','producto_id_cl','import_id'
];

$placeholders = implode(',', array_map(fn($c)=>':'.$c, $cols));
$sql = 'INSERT INTO public."Procedimientos Adjudicados" (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
$stmt = $pdo->prepare($sql);

$pdo->beginTransaction();
while(($r=fgetcsv($fh, 0, $delimiter))!==false){
  $total++;
  if (!array_filter($r, fn($x)=>trim((string)$x)!='')) continue;

  $params=[];
  foreach($cols as $i=>$c){
    if($c==='import_id'){ $params[':import_id']=$import_id; continue; }
    $val=$r[$i]??null;
    if (preg_match('/fecha/i',$c) && $val){
      $ts=strtotime(str_replace(['/','.'], '-',(string)$val));
      $val=$ts?date('Y-m-d',$ts):null;
    } elseif (preg_match('/monto|cantidad|anio|numero|id/i',$c)){
      $val=preg_replace('/[^\d\.\-]/','',$val);
      $val=$val!==''?(float)$val:null;
    } else {
      $val=trim((string)$val)?:null;
    }
    $params[':'.$c]=$val;
  }
  try{
    $stmt->execute($params);
    $insertados++;
  }catch(Throwable $e){
    $saltados++;
  }
}
$pdo->commit();
fclose($fh);

$pdo->prepare("UPDATE public.procedimientos_import_log
 SET inserted=:i, skipped=:s, total_rows=:t, finished_at=now()
 WHERE import_id=:id")
->execute([':i'=>$insertados,':s'=>$saltados,':t'=>$total,':id'=>$import_id]);

echo json_encode([
  'ok'=>true,
  'insertados'=>$insertados,
  'saltados'=>$saltados,
  'total'=>$total,
  'import_id'=>$import_id
], JSON_UNESCAPED_UNICODE);
