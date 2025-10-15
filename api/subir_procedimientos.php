<?php
declare(strict_types=1);
@ini_set('memory_limit','1024M');
@set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/* === CONEXIÓN === */
$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
try{
  $p=parse_url($DATABASE_URL);
  $pdo=new PDO("pgsql:host={$p['host']};port={$p['port']};dbname=".ltrim($p['path'],'/').";sslmode=require",$p['user'],$p['pass']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
}catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]);
  exit;
}

/* === LOGS === */
if(isset($_GET['accion']) && $_GET['accion']==='logs'){
  $rows=$pdo->query('SELECT * FROM public.procedimientos_import_log ORDER BY started_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true,'logs'=>$rows]); exit;
}

/* === FUNCIONES === */
function new_uuid(){ $d=random_bytes(16);$d[6]=chr(ord($d[6])&0x0f|0x40);$d[8]=chr(ord($d[8])&0x3f|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }
function clean($s){ return trim(preg_replace('/\s+/u',' ',$s??'')); }
function excel_date($v){ if(!$v)return null; return is_numeric($v)?date('Y-m-d',ExcelDate::excelToTimestamp($v)):date('Y-m-d',strtotime($v)); }
function build_fingerprint($r){ return md5(join('|',[$r['Mes de Descarga']??'',$r['Año de reporte']??'',$r['CEDULA']??'',$r['NUMERO_PROCEDIMIENTO']??'',$r['CEDULA_PROVEEDOR']??'',$r['NOMBRE_PROVEEDOR']??'',$r['MONTO_ADJU_LINEA_CRC']??''])); }

/* === VALIDAR ARCHIVO === */
if(!isset($_FILES['archivo'])||$_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']); exit;
}
$name=$_FILES['archivo']['name']; $tmp=$_FILES['archivo']['tmp_name'];
$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
if(!in_array($ext,['csv','xlsx'])){ echo json_encode(['ok'=>false,'error'=>'Solo .csv o .xlsx']); exit; }

/* === LEER DATOS === */
$rows=[];
if($ext==='csv'){
  $fh=fopen($tmp,'r'); $delim=','; $first=fgets($fh); rewind($fh);
  $delims=[","=>substr_count($first,","),";"=>substr_count($first,";"),"\t"=>substr_count($first,"\t")];
  arsort($delims); $delim=array_key_first($delims);
  while(($r=fgetcsv($fh,0,$delim))!==false){ $rows[]=$r; } fclose($fh);
}else{
  $sheet=IOFactory::load($tmp)->getActiveSheet();
  $rows=$sheet->toArray();
}
if(!$rows){ echo json_encode(['ok'=>false,'error'=>'Archivo vacío']); exit; }

$headers=array_map('clean',$rows[0]);
$data=array_slice($rows,1);
$import_id=new_uuid();

/* === LOG === */
$pdo->exec('CREATE TABLE IF NOT EXISTS public.procedimientos_import_log(import_id UUID PRIMARY KEY,filename TEXT,total_rows INT,inserted INT,skipped INT,started_at TIMESTAMPTZ DEFAULT now(),finished_at TIMESTAMPTZ,anulado_at TIMESTAMPTZ)');
$pdo->prepare('INSERT INTO public.procedimientos_import_log(import_id,filename) VALUES(:id,:fn)')->execute([':id'=>$import_id,':fn'=>$name]);

$total=0;$ins=0;$skip=0;
$pdo->beginTransaction();
try{
  foreach($data as $r){
    if(!array_filter($r)){ $skip++; continue; }
    $row=array_combine($headers,$r);
    $finger=build_fingerprint($row);
    $row['fingerprint']=$finger; $row['import_id']=$import_id;
    $cols=array_keys($row);
    $phs=array_map(fn($c)=>':'.preg_replace('/\W+/','_',strtolower($c)),$cols);
    $sql='INSERT INTO public."Procedimientos Adjudicados"("'.implode('","',$cols).'") VALUES('.implode(',',$phs).') ON CONFLICT (fingerprint) DO NOTHING';
    $st=$pdo->prepare($sql);
    foreach($row as $k=>$v){ $st->bindValue(':'.preg_replace('/\W+/','_',strtolower($k)),$v); }
    try{ $st->execute(); $ins+=$st->rowCount(); }catch(Throwable $e){ $skip++; }
    $total++;
  }
  $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

$pdo->prepare('UPDATE public.procedimientos_import_log SET total_rows=:t,inserted=:i,skipped=:s,finished_at=now() WHERE import_id=:id')->execute([':t'=>$total,':i'=>$ins,':s'=>$skip,':id'=>$import_id]);

echo json_encode(['ok'=>true,'import_id'=>$import_id,'insertados'=>$ins,'saltados'=>$skip,'total'=>$total]);
