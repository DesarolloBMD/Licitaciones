<?php
declare(strict_types=1);
@ini_set('memory_limit','1024M');
@set_time_limit(0);

/* === HEADERS / CORS === */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* === CONEXIÓN A POSTGRES === */
$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';

try {
  $p=parse_url($DATABASE_URL);
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo=new PDO($dsn,$p['user'],$p['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
  $pdo->exec("SET TIME ZONE 'UTC'");
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()]);
  exit;
}

/* === FUNCIONES AUXILIARES === */
function clean($s){ return trim(preg_replace('/\s+/u',' ',$s??'')); }
function new_uuid():string{ $d=random_bytes(16);$d[6]=chr(ord($d[6])&0x0f|0x40);$d[8]=chr(ord($d[8])&0x3f|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }
function build_fingerprint(array $r):string {
  return md5(join('|',[
    $r['Mes de Descarga']??'',
    $r['Año de reporte']??'',
    $r['CEDULA']??'',
    $r['NUMERO_PROCEDIMIENTO']??'',
    $r['CEDULA_PROVEEDOR']??'',
    $r['NOMBRE_PROVEEDOR']??'',
    $r['MONTO_ADJU_LINEA_CRC']??''
  ]));
}

/* === CREAR TABLA DE LOG SI NO EXISTE === */
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

/* === ENDPOINT DE LOGS === */
if (isset($_GET['accion']) && $_GET['accion']==='logs') {
  try {
    $rows=$pdo->query('SELECT * FROM public.procedimientos_import_log ORDER BY started_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'logs'=>$rows],JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* === ENDPOINT ANULAR === */
if (isset($_POST['accion']) && $_POST['accion']==='anular' && isset($_POST['import_id'])) {
  try {
    $id=$_POST['import_id'];
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id=:id')->execute([':id'=>$id]);
    $pdo->prepare('UPDATE public.procedimientos_import_log SET anulado_at=now() WHERE import_id=:id')->execute([':id'=>$id]);
    $pdo->commit();
    echo json_encode(['ok'=>true]);
  } catch(Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* === VALIDAR ARCHIVO === */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$name=$_FILES['archivo']['name'];
$tmp=$_FILES['archivo']['tmp_name'];
$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
if(!in_array($ext,['csv'])) {
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido (solo .csv)']);
  exit;
}

/* === DETECTAR DELIMITADOR === */
$fh=fopen($tmp,'r');
if(!$fh){ echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']); exit; }
$first=fgets($fh); rewind($fh);
$delims=[","=>substr_count($first,","),";"=>substr_count($first,";"),"\t"=>substr_count($first,"\t")];
arsort($delims);
$delim=array_key_first($delims);

/* === LEER CABECERAS === */
$headers=fgetcsv($fh,0,$delim);
if(!$headers){ echo json_encode(['ok'=>false,'error'=>'No se pudieron leer los encabezados']); exit; }
$headers=array_map('clean',$headers);

/* === INSERTAR LOG DE IMPORTACIÓN === */
$import_id=new_uuid();
$ip=$_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$log=$pdo->prepare('INSERT INTO public.procedimientos_import_log(import_id,filename,total_rows,inserted,skipped,started_at,source_ip)
                    VALUES(:id,:fn,0,0,0,now(),:ip)');
$log->execute([':id'=>$import_id,':fn'=>$name,':ip'=>$ip]);

/* === PREPARAR SQL === */
$cols=array_map(fn($h)=>'"'.$h.'"',$headers);
$params=array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)),$headers);
$sql='INSERT INTO public."Procedimientos Adjudicados"('.implode(',',$cols).',fingerprint,import_id)
      VALUES('.implode(',',$params).',:finger,:imp)
      ON CONFLICT (fingerprint) DO NOTHING';
$stmt=$pdo->prepare($sql);

/* === PROCESAR FILAS === */
$total=0; $inserted=0; $skipped=0;
$pdo->beginTransaction();
try {
  while(($r=fgetcsv($fh,0,$delim))!==false){
    if(!array_filter($r)){ $skipped++; continue; }
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

    try {
      $stmt->execute($bind);
      $inserted+=$stmt->rowCount();
    } catch(Throwable $e) {
      $skipped++;
    }
  }
  $pdo->commit();
} catch(Throwable $e) {
  $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'Error en importación: '.$e->getMessage()]);
  exit;
}
fclose($fh);

/* === ACTUALIZAR LOG === */
$upd=$pdo->prepare('UPDATE public.procedimientos_import_log
                    SET total_rows=:t,inserted=:i,skipped=:s,finished_at=now()
                    WHERE import_id=:id');
$upd->execute([':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id]);

/* === RESPUESTA FINAL === */
echo json_encode([
  'ok'=>true,
  'import_id'=>$import_id,
  'insertados'=>$inserted,
  'saltados'=>$skipped,
  'total'=>$total
],JSON_UNESCAPED_UNICODE);
?>
