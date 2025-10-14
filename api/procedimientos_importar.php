<?php
// api/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit','1024M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$CLIENT_IP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

/* ======== CORS ======== */
if ($method === 'OPTIONS') { http_response_code(204); exit; }
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/* ======== Manejador de errores JSON ======== */
ini_set('display_errors','0');
ob_start();
set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])){
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok'=>false,
      'error'=>'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line']
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
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);
  exit;
}

/* ======== Helpers ======== */
function to_utf8($s){ if(!$s)return''; if(mb_detect_encoding($s,'UTF-8',true))return $s; return mb_convert_encoding($s,'UTF-8','auto'); }
function norm_header($s){ $s=to_utf8($s); $s=preg_replace('/^\xEF\xBB\xBF/','',$s); return trim(preg_replace('/\s+/u',' ',$s)); }
function new_uuid():string{ $d=random_bytes(16);$d[6]=chr(ord($d[6])&0x0f|0x40);$d[8]=chr(ord($d[8])&0x3f|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }

/* ======== Inicializar tablas ======== */
$pdo->exec('CREATE TABLE IF NOT EXISTS public.procedimientos_import_log(
  import_id  UUID PRIMARY KEY,
  filename   TEXT,
  total_rows INTEGER,
  inserted   INTEGER,
  skipped    INTEGER,
  started_at TIMESTAMPTZ DEFAULT now(),
  finished_at TIMESTAMPTZ,
  anulado_at TIMESTAMPTZ,
  source_ip  TEXT
)');

$pdo->exec('ALTER TABLE public."Procedimientos Adjudicados"
  ADD COLUMN IF NOT EXISTS fingerprint TEXT,
  ADD COLUMN IF NOT EXISTS import_id UUID,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT now(),
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT now()');

/* ======== Función de fingerprint ======== */
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

/* ======== Importación ======== */
header('Content-Type: application/json; charset=utf-8');
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'],JSON_UNESCAPED_UNICODE);
  exit;
}
$name=$_FILES['archivo']['name'];
$tmp=$_FILES['archivo']['tmp_name'];
$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
if(!in_array($ext,['csv','txt','tsv'],true)){
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido (.csv o .txt)'],JSON_UNESCAPED_UNICODE);
  exit;
}

/* Detectar delimitador */
$fh=fopen($tmp,'r'); if(!$fh){ echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']); exit; }
$first=fgets($fh); rewind($fh);
$delims=[","=>substr_count($first,","),";"=>substr_count($first,";"),"\t"=>substr_count($first,"\t"),"|"=>substr_count($first,"|")];
arsort($delims); $delim=array_key_first($delims);

/* Leer encabezados */
$headers=fgetcsv($fh,0,$delim);
if(!$headers){ echo json_encode(['ok'=>false,'error'=>'No se pudieron leer encabezados']); exit; }
$headers=array_map('norm_header',$headers);

/* Inicia log */
$import_id=new_uuid();
$log=$pdo->prepare('INSERT INTO public.procedimientos_import_log(import_id,filename,total_rows,inserted,skipped,started_at,source_ip)
                    VALUES(:id,:fn,0,0,0,now(),:ip)');
$log->execute([':id'=>$import_id,':fn'=>$name,':ip'=>$CLIENT_IP]);

/* UPSERT preparado */
$cols=array_map(fn($h)=>'"'.$h.'"',$headers);
$params=array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)),$headers);
$sql='INSERT INTO public."Procedimientos Adjudicados"('.implode(',',$cols).',fingerprint,import_id,created_at,updated_at)
      VALUES('.implode(',',$params).',:finger,:imp,now(),now())
      ON CONFLICT (fingerprint) DO NOTHING';
$stmt=$pdo->prepare($sql);

/* Importar filas */
$total=0; $inserted=0; $skipped=0;
$pdo->beginTransaction();
try{
  while(($r=fgetcsv($fh,0,$delim))!==false){
    $total++;
    if(count(array_filter($r))==0){ $skipped++; continue; }
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
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
  exit;
}
fclose($fh);

$upd=$pdo->prepare('UPDATE public.procedimientos_import_log
                    SET total_rows=:t,inserted=:i,skipped=:s,finished_at=now()
                    WHERE import_id=:id');
$upd->execute([':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id]);

echo json_encode(['ok'=>true,'import_id'=>$import_id,'insertados'=>$inserted,'saltados'=>$skipped,'total'=>$total],JSON_UNESCAPED_UNICODE);
