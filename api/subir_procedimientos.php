<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('memory_limit', '1024M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ob_start();
set_error_handler(function($sev, $msg, $file, $line){
  throw new ErrorException($msg, 0, $sev, $file, $line);
});
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode([
      'ok'=>false,
      'error'=>'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line']
    ], JSON_UNESCAPED_UNICODE);
  }
});

$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
try {
  $p=parse_url($DATABASE_URL);
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo=new PDO($dsn,$p['user'],$p['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()]);
  exit;
}

/* ---------- Helpers ---------- */
function limpiar_encabezado($s): string {
  $s = (string)($s ?? '');
  $s = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], '', $s);
  $s = trim(preg_replace('/\s+/u',' ',$s));
  return mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
}
function convertir_utf8(string $s): string {
  if (mb_detect_encoding($s,'UTF-8',true)) return $s;
  return mb_convert_encoding($s,'UTF-8','ISO-8859-1, Windows-1252');
}
function normalizar_numero(?string $s): ?float {
  if ($s === null || trim($s) === '') return null;
  $s = str_replace([' ', ','], ['', '.'], trim($s));
  return is_numeric($s) ? (float)$s : null;
}
function normalizar_fecha(?string $s): ?string {
  if (!$s) return null;
  $s = str_ireplace(['lunes,','martes,','miércoles,','jueves,','viernes,','sábado,','domingo,'], '', trim($s));
  $s = str_replace('/','-',$s);
  $ts = strtotime($s);
  return $ts ? date('Y-m-d',$ts) : null;
}
function uuid(): string {
  $d = random_bytes(16);
  $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
  $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/* ---------- Tabla de logs ---------- */
$pdo->exec('CREATE TABLE IF NOT EXISTS public.importaciones_log (
  import_id UUID PRIMARY KEY,
  filename TEXT,
  total_rows INTEGER DEFAULT 0,
  inserted INTEGER DEFAULT 0,
  skipped INTEGER DEFAULT 0,
  started_at TIMESTAMPTZ DEFAULT now(),
  finished_at TIMESTAMPTZ,
  anulado_at TIMESTAMPTZ,
  source_ip TEXT
)');

/* ---------- Mostrar logs ---------- */
if (isset($_GET['accion']) && $_GET['accion']==='logs') {
  $rows=$pdo->query('SELECT * FROM public.importaciones_log ORDER BY started_at DESC LIMIT 50')->fetchAll();
  echo json_encode(['ok'=>true,'logs'=>$rows]);
  exit;
}

/* ---------- Anular import ---------- */
if (isset($_POST['accion']) && $_POST['accion']==='anular') {
  $id=$_POST['import_id']??'';
  try {
    $pdo->beginTransaction();
    if ($id===''){
      $id=$pdo->query('SELECT import_id FROM public.importaciones_log ORDER BY started_at DESC LIMIT 1')->fetchColumn();
    }
    if(!$id) throw new Exception('No hay importaciones para anular.');
    $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id=:id')->execute([':id'=>$id]);
    $pdo->prepare('UPDATE public.importaciones_log SET anulado_at=now() WHERE import_id=:id')->execute([':id'=>$id]);
    $pdo->commit();
    echo json_encode(['ok'=>true,'anulado'=>$id]);
  }catch(Throwable $e){
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ---------- Importar ---------- */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$name=$_FILES['archivo']['name'];
$tmp=$_FILES['archivo']['tmp_name'];
$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
if(!in_array($ext,['csv','xlsx'])) {
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido (.csv o .xlsx)']);
  exit;
}

$fh=fopen($tmp,'r');
if(!$fh){ echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']); exit; }

$first=fgets($fh); rewind($fh);
$delims=[";"=>substr_count($first,";"),"\t"=>substr_count($first,"\t"),","=>substr_count($first,",")];
arsort($delims);
$delim=array_key_first($delims) ?: ";";

$headers=fgetcsv($fh,0,$delim);
$headers=array_map('limpiar_encabezado',$headers);

$import_id=uuid();
$ip=$_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??'';

$pdo->prepare('INSERT INTO public.importaciones_log(import_id,filename,source_ip,started_at)
               VALUES(:i,:f,:ip,now())')->execute([':i'=>$import_id,':f'=>$name,':ip'=>$ip]);

$total=0; $inserted=0; $skipped=0;
$pdo->beginTransaction();

try {
  while(($row=fgetcsv($fh,0,$delim))!==false){
    $total++;
    if(count(array_filter($row))==0){ $skipped++; continue; }

    $data=[];
    foreach($headers as $i=>$h){
      $v=$row[$i]??null;
      $v=convertir_utf8((string)$v);
      if(preg_match('/FECHA/i',$h)) $v=normalizar_fecha($v);
      elseif(preg_match('/MONTO|CANTIDAD|ID$/i',$h)) $v=normalizar_numero($v);
      $data[$h]=$v;
    }

    $cols=array_map(fn($h)=>'"'.$h.'"',array_keys($data));
    $phs=array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)),array_keys($data));
    $sql='INSERT INTO public."Procedimientos Adjudicados"('.implode(',',$cols).',import_id)
          VALUES('.implode(',',$phs).',:import_id)';
    $stmt=$pdo->prepare($sql);
    foreach($data as $h=>$v){ $stmt->bindValue(':'.preg_replace('/\W+/','_',strtolower($h)),$v); }
    $stmt->bindValue(':import_id',$import_id);
    try { $stmt->execute(); $inserted+=$stmt->rowCount(); } catch(Throwable $e){ $skipped++; }
  }
  $pdo->commit();
}catch(Throwable $e){
  $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit;
}
fclose($fh);

$pdo->prepare('UPDATE public.importaciones_log
               SET total_rows=:t,inserted=:i,skipped=:s,finished_at=now()
               WHERE import_id=:id')->execute([
                 ':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id
               ]);

echo json_encode(['ok'=>true,'import_id'=>$import_id,'insertados'=>$inserted,'saltados'=>$skipped,'total'=>$total]);
