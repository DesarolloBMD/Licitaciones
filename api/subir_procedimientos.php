<?php
declare(strict_types=1);
@ini_set('memory_limit','1024M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin', '*');
header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
header('Access-Control-Allow-Headers', 'Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// 🔹 Cargamos PhpSpreadsheet desde paquete local mínimo (no remoto)
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/* === Helpers === */
function limpiar($s){ return trim(preg_replace('/\s+/u',' ',$s ?? '')); }
function normalizar_numero($v){ 
  if ($v === null || $v === '') return null;
  $v = str_replace([' ', ','], ['', '.'], $v);
  return is_numeric($v) ? (float)$v : null;
}
function normalizar_fecha($v){ 
  if (!$v) return null;
  if (is_numeric($v)) return date('Y-m-d', ExcelDate::excelToTimestamp((float)$v));
  $v = str_replace('/', '-', trim($v));
  $ts = strtotime($v);
  return $ts ? date('Y-m-d', $ts) : null;
}
function uuid(){ $d=random_bytes(16);$d[6]=chr(ord($d[6])&0x0f|0x40);$d[8]=chr(ord($d[8])&0x3f|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4)); }

/* === Conexión === */
$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
try {
  $p=parse_url($DATABASE_URL);
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo=new PDO($dsn,$p['user'],$p['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()]);
  exit;
}

/* === Validar archivo === */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}
$name = $_FILES['archivo']['name'];
$tmp = $_FILES['archivo']['tmp_name'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== 'xlsx'){
  echo json_encode(['ok'=>false,'error'=>'Solo se permite formato .xlsx']);
  exit;
}

/* === Leer Excel === */
try {
  $reader = IOFactory::createReader('Xlsx');
  $spreadsheet = $reader->load($tmp);
  $sheet = $spreadsheet->getActiveSheet();
  $rows = $sheet->toArray();
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error leyendo Excel: '.$e->getMessage()]);
  exit;
}

/* === Procesar === */
$headers = array_map('limpiar', $rows[0] ?? []);
$import_id = uuid();
$total = 0; $inserted = 0; $skipped = 0;

$pdo->exec('CREATE TABLE IF NOT EXISTS public.importaciones_log(
  import_id UUID PRIMARY KEY,
  filename TEXT, total_rows INT, inserted INT, skipped INT,
  started_at TIMESTAMPTZ DEFAULT now(), finished_at TIMESTAMPTZ
)');
$pdo->prepare('INSERT INTO public.importaciones_log(import_id,filename) VALUES(:id,:fn)')
    ->execute([':id'=>$import_id, ':fn'=>$name]);

$pdo->beginTransaction();
try {
  for($i=1;$i<count($rows);$i++){
    $fila = $rows[$i];
    if (!array_filter($fila)) { $skipped++; continue; }

    $data = [];
    foreach($headers as $j=>$col){
      $val = $fila[$j] ?? null;
      if (preg_match('/FECHA/i',$col)) $val = normalizar_fecha($val);
      elseif (preg_match('/MONTO|CANTIDAD|ID$/i',$col)) $val = normalizar_numero($val);
      $data[$col] = $val;
    }

    $cols = array_map(fn($h)=>'"'.$h.'"', array_keys($data));
    $phs = array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)), array_keys($data));
    $sql = 'INSERT INTO public."Procedimientos Adjudicados"('.implode(',',$cols).',import_id)
            VALUES('.implode(',',$phs).',:imp)';
    $stmt = $pdo->prepare($sql);
    foreach($data as $h=>$v)
      $stmt->bindValue(':'.preg_replace('/\W+/','_',strtolower($h)),$v);
    $stmt->bindValue(':imp',$import_id);
    try { $stmt->execute(); $inserted+=$stmt->rowCount(); }
    catch(Throwable $e){ $skipped++; }
    $total++;
  }
  $pdo->commit();
} catch(Throwable $e){
  $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'Error insertando: '.$e->getMessage()]);
  exit;
}

$pdo->prepare('UPDATE public.importaciones_log
               SET total_rows=:t, inserted=:i, skipped=:s, finished_at=now()
               WHERE import_id=:id')
    ->execute([':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id]);

echo json_encode(['ok'=>true,'import_id'=>$import_id,'insertados'=>$inserted,'saltados'=>$skipped,'total'=>$total]);
