<?php
// api/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit','1024M');
@set_time_limit(0);
@ini_set('display_errors','0'); // no mostrar HTML
error_reporting(E_ALL);
ob_start(); // capturar salida accidental

/* ======== CORS ======== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

/* ======== Captura errores PHP y los devuelve en JSON ======== */
set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])){
    http_response_code(500);
    $out = ob_get_clean();
    echo json_encode([
      'ok'=>false,
      'error'=>'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line'],
      'debug'=>$out ? trim(strip_tags($out)) : null
    ],JSON_UNESCAPED_UNICODE);
  }
});

/* ======== Conexión a Postgres ======== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false){
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p=parse_url($DATABASE_URL);
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo=new PDO($dsn,$p['user'],$p['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true
  ]);
  $pdo->exec("SET TIME ZONE 'UTC'");
} catch(Throwable $e){
  if (ob_get_length()) ob_clean();
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);
  exit;
}

/* ======== Helpers ======== */
function new_uuid():string{
  $d=random_bytes(16);
  $d[6]=chr(ord($d[6])&0x0f|0x40);
  $d[8]=chr(ord($d[8])&0x3f|0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
function clean_header($h){
  $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // BOM
  $h = trim(str_replace(['"',"'"], '', $h));
  $h = preg_replace('/\s+/u',' ',$h);
  $map = ['Á'=>'A','á'=>'a','É'=>'E','é'=>'e','Í'=>'I','í'=>'i','Ó'=>'O','ó'=>'o','Ú'=>'U','ú'=>'u','Ü'=>'U','ü'=>'u','Ñ'=>'N','ñ'=>'n'];
  $h = strtr($h,$map);
  return $h;
}
function build_fingerprint(array $t): string {
  return md5(implode('|',[
    $t['Mes de Descarga']??'',
    $t['Año de reporte']??'',
    $t['CEDULA']??'',
    $t['INSTITUCION']??'',
    $t['NUMERO_PROCEDIMIENTO']??'',
    $t['LINEA']??'',
    $t['CEDULA_PROVEEDOR']??'',
    $t['NOMBRE_PROVEEDOR']??'',
    $t['MONTO_ADJU_LINEA_CRC']??''
  ]));
}

/* ======== Inicializar tablas ======== */
try {
  $pdo->exec('CREATE TABLE IF NOT EXISTS public.procedimientos_import_log(
    import_id  UUID PRIMARY KEY,
    filename   TEXT,
    total_rows INTEGER,
    inserted   INTEGER,
    skipped    INTEGER,
    started_at TIMESTAMPTZ DEFAULT now(),
    finished_at TIMESTAMPTZ,
    source_ip  TEXT
  )');

  $pdo->exec('ALTER TABLE public."Procedimientos Adjudicados"
    ADD COLUMN IF NOT EXISTS fingerprint TEXT,
    ADD COLUMN IF NOT EXISTS import_id UUID,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT now(),
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT now()');
} catch(Throwable $e){
  if (ob_get_length()) ob_clean();
  echo json_encode(['ok'=>false,'error'=>'Error inicializando tablas: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);
  exit;
}

/* ======== Endpoint historial ======== */
if (isset($_GET['accion']) && $_GET['accion']==='logs') {
  try {
    $rows = $pdo->query('SELECT * FROM public.procedimientos_import_log ORDER BY started_at DESC LIMIT 100')->fetchAll();
    if (ob_get_length()) ob_clean();
    echo json_encode(['ok'=>true,'logs'=>$rows], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* ======== Validar archivo ======== */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  if (ob_get_length()) ob_clean();
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'],JSON_UNESCAPED_UNICODE);
  exit;
}

$name=$_FILES['archivo']['name'];
$tmp=$_FILES['archivo']['tmp_name'];
$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
if(!in_array($ext,['csv','txt','tsv'],true)){
  if (ob_get_length()) ob_clean();
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido (.csv o .txt)'],JSON_UNESCAPED_UNICODE);
  exit;
}

/* ======== Detectar delimitador ======== */
$fh=fopen($tmp,'r');
if(!$fh){ echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']); exit; }
$first=fgets($fh); rewind($fh);
$delims=[","=>substr_count($first,","),";"=>substr_count($first,";"),"\t"=>substr_count($first,"\t"),"|"=>substr_count($first,"|")];
arsort($delims);
$delim=array_key_first($delims) ?: ";";

/* ======== Leer encabezados ======== */
$headers=fgetcsv($fh,0,$delim);
if(!$headers){ echo json_encode(['ok'=>false,'error'=>'No se pudieron leer encabezados']); exit; }
$headers=array_map('clean_header',$headers);

/* ======== Verificar columnas existentes en la tabla ======== */
$colsBD = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'Procedimientos Adjudicados'")->fetchAll(PDO::FETCH_COLUMN);
$headers = array_values(array_intersect($headers, $colsBD));
if (empty($headers)) {
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden con columnas de la tabla'],JSON_UNESCAPED_UNICODE);
  exit;
}

/* ======== Registrar log ======== */
$import_id=new_uuid();
$ip=$_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$log=$pdo->prepare('INSERT INTO public.procedimientos_import_log(import_id,filename,total_rows,inserted,skipped,started_at,source_ip)
                    VALUES(:id,:fn,0,0,0,now(),:ip)');
$log->execute([':id'=>$import_id,':fn'=>$name,':ip'=>$ip]);

/* ======== SQL dinámico ======== */
$cols=array_map(fn($h)=>'"'.$h.'"',$headers);
$params=array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)),$headers);
$sql='INSERT INTO public."Procedimientos Adjudicados"('.implode(',',$cols).',fingerprint,import_id,created_at,updated_at)
      VALUES('.implode(',',$params).',:finger,:imp,now(),now())
      ON CONFLICT (fingerprint) DO NOTHING';
$stmt=$pdo->prepare($sql);

/* ======== Importar ======== */
$total=0; $inserted=0; $skipped=0;
$pdo->beginTransaction();
try{
  while(($r=fgetcsv($fh,0,$delim))!==false){
    if(count(array_filter($r))==0) continue;
    $total++;
    if(count($r)!=count($headers)){ $skipped++; continue; }
    $row=array_combine($headers,$r);
    $finger=build_fingerprint($row);
    $bind=[];
    foreach($headers as $h){
      $ph=':'.preg_replace('/\W+/','_',strtolower($h));
      $val=trim((string)($row[$h]??''));
      $bind[$ph]=$val===''?null:$val;
    }
    $bind[':finger']=$finger;
    $bind[':imp']=$import_id;
    try{
      $stmt->execute($bind);
      $inserted+=$stmt->rowCount();
    }catch(Throwable $e){ $skipped++; }
  }
  $pdo->commit();
}catch(Throwable $e){
  $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'Error en importación: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);
  exit;
}
fclose($fh);

/* ======== Actualizar log ======== */
$upd=$pdo->prepare('UPDATE public.procedimientos_import_log
                    SET total_rows=:t,inserted=:i,skipped=:s,finished_at=now()
                    WHERE import_id=:id');
$upd->execute([':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id]);

/* ======== Enviar JSON limpio ======== */
if (ob_get_length()) ob_clean();
echo json_encode([
  'ok'=>true,
  'import_id'=>$import_id,
  'insertados'=>$inserted,
  'saltados'=>$skipped,
  'total'=>$total
],JSON_UNESCAPED_UNICODE);
exit;
?>
