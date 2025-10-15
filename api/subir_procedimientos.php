<?php
declare(strict_types=1);
@ini_set('display_errors', '0');
@ini_set('memory_limit', '1024M');
@set_time_limit(0);

/* ==========================================================
   🧩  MANEJO GLOBAL DE ERRORES (todo responde en JSON)
   ========================================================== */
ob_start();
set_error_handler(function($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function() {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true);
    echo json_encode([
      'ok' => false,
      'error' => 'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line']
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ==========================================================
   🧠 CONEXIÓN A POSTGRES
   ========================================================== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==========================================================
   📜 ENDPOINT: HISTORIAL DE IMPORTACIONES
   ========================================================== */
if (isset($_GET['accion']) && $_GET['accion'] === 'logs') {
  try {
    $rows = $pdo->query('
      SELECT import_id, filename, total_rows, inserted, skipped, started_at, finished_at
      FROM public.procedimientos_import_log
      ORDER BY started_at DESC
      LIMIT 100
    ')->fetchAll();
    echo json_encode(['ok' => true, 'logs' => $rows], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* ==========================================================
   ❌ ENDPOINT: ANULAR IMPORTACIÓN
   ========================================================== */
if (isset($_GET['accion']) && $_GET['accion'] === 'anular' && isset($_GET['id'])) {
  $id = $_GET['id'];
  try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id = :id')->execute([':id'=>$id]);
    $pdo->prepare('DELETE FROM public.procedimientos_import_log WHERE import_id = :id')->execute([':id'=>$id]);
    $pdo->commit();
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* ==========================================================
   📤 IMPORTAR ARCHIVO CSV / TXT
   ========================================================== */
if ($method !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE);
  exit;
}

$name = $_FILES['archivo']['name'] ?? '';
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido. Use .csv o .txt'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$fh  = fopen($tmp, 'r');
if (!$fh) {
  echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo subido'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Detectar delimitador */
$firstLine = fgets($fh);
rewind($fh);
$delims = [","=>substr_count($firstLine,","), ";"=>substr_count($firstLine,";"), "\t"=>substr_count($firstLine,"\t"), "|"=>substr_count($firstLine,"|")];
arsort($delims);
$delimiter = array_key_first($delims) ?? ",";
if (($delims[$delimiter] ?? 0) === 0) $delimiter = ",";

$headers = fgetcsv($fh, 0, $delimiter);
if (!$headers) {
  echo json_encode(['ok'=>false,'error'=>'Archivo vacío o sin encabezados'], JSON_UNESCAPED_UNICODE);
  exit;
}
$headers = array_map(fn($h)=>trim(preg_replace('/\s+/',' ',$h)),$headers);

/* Registrar log inicial */
$import_id = uniqid('imp_', true);
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$pdo->prepare('
  INSERT INTO public.procedimientos_import_log(import_id, filename, total_rows, inserted, skipped, started_at, source_ip)
  VALUES(:id, :fn, 0, 0, 0, now(), :ip)
')->execute([':id'=>$import_id, ':fn'=>$name, ':ip'=>$ip]);

/* Armar sentencia de inserción */
$cols = array_map(fn($h)=>'"'.$h.'"', $headers);
$params = array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)),$headers);

$sql = 'INSERT INTO public."Procedimientos Adjudicados" ('.implode(',', $cols).', import_id, created_at, updated_at)
        VALUES ('.implode(',', $params).', :imp, now(), now())';
$stmt = $pdo->prepare($sql);

/* Procesar archivo */
$total=0; $inserted=0; $skipped=0;
$pdo->beginTransaction();

while(($row=fgetcsv($fh,0,$delimiter))!==false) {
  $total++;
  if (count(array_filter($row))==0) { $skipped++; continue; }
  $bind=[];
  foreach($headers as $i=>$h){
    $ph=':'.preg_replace('/\W+/','_',strtolower($h));
    $val=trim((string)($row[$i]??''));
    $bind[$ph]=$val===''?null:$val;
  }
  $bind[':imp']=$import_id;
  try {
    $stmt->execute($bind);
    $inserted++;
  } catch(Throwable $e) {
    $skipped++;
  }
}
$pdo->commit();
fclose($fh);

/* Actualizar log */
$pdo->prepare('
  UPDATE public.procedimientos_import_log
  SET total_rows = :t, inserted = :i, skipped = :s, finished_at = now()
  WHERE import_id = :id
')->execute([':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id]);

echo json_encode(['ok'=>true,'insertados'=>$inserted,'saltados'=>$skipped,'total'=>$total], JSON_UNESCAPED_UNICODE);
exit;
