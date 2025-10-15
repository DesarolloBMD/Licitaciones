<?php
declare(strict_types=1);
@ini_set('memory_limit','1G');
@set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$DATABASE_URL = getenv('DATABASE_URL') ?: 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port']??5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]);
  exit;
}

/* ============================================================
   1. Historial de importaciones
   ============================================================ */
if (isset($_GET['action']) && $_GET['action']==='historial') {
  try {
    $q = $pdo->query('SELECT * FROM public.procedimientos_import_log ORDER BY started_at DESC');
    echo json_encode(['ok'=>true,'rows'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
  } catch(Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ============================================================
   2. Anular importación
   ============================================================ */
if (isset($_GET['action']) && $_GET['action']==='anular' && isset($_GET['id'])) {
  $id = $_GET['id'];
  try {
    $stmt = $pdo->prepare('SELECT public.anular_importacion(:id, :motivo)');
    $stmt->execute([':id'=>$id, ':motivo'=>'Anulado desde interfaz']);
    echo json_encode(['ok'=>true]);
  } catch(Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ============================================================
   3. Subida e importación CSV
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'Método no permitido']);
  exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$nombre = $_FILES['archivo']['name'];
$tmp = $_FILES['archivo']['tmp_name'];
$fh = fopen($tmp,'r');
if (!$fh) { echo json_encode(['ok'=>false,'error'=>'No se pudo leer el archivo']); exit; }

$header = fgetcsv($fh,0,',');
if (!$header) { echo json_encode(['ok'=>false,'error'=>'Archivo vacío o sin encabezados']); exit; }

/* ============================================================
   4. Mapa de correspondencia CSV → columnas PostgreSQL
   ============================================================ */
$mapa = [
  'Mes de Descarga' => 'mes_descarga',
  'Año de Descarga' => 'anio_descarga',
  'Mes de Adjudicación' => 'mes_adjudicacion',
  'Año de Adjudicación' => 'anio_adjudicacion',
  'Cédula' => 'cedula',
  'Institución' => 'institucion',
  'Año' => 'anio',
  'Numero de Procedimiento' => 'numero_procedimiento',
  'Descripción de Procedimiento' => 'descripcion_procedimiento',
  'Linea' => 'linea',
  'Numero de SICOP' => 'numero_sicop',
  'Tipo de Procedimiento' => 'tipo_procedimiento',
  'Modalidad de Procedimiento' => 'modalidad_procedimiento',
  'Fecha Rev' => 'fecha_rev',
  'Cédula del Proveedor' => 'cedula_proveedor',
  'Nombre del Proveedor' => 'nombre_proveedor',
  'Perfil del Proveedor' => 'perfil_proveedor',
  'Cédula del Representante' => 'cedula_representante',
  'Representante' => 'representante',
  'Objeto Gasto' => 'objeto_gasto',
  'Moneda Adjudicada' => 'moneda_adjudicada',
  'Monto Adjudicado por Linea' => 'monto_adjudicado_linea',
  'Monto Adjudicado por Linea CRC' => 'monto_adjudicado_linea_crc',
  'Monto Adjudicado por Linea USD' => 'monto_adjudicado_linea_usd',
  'Fecha Adjudicación Firma' => 'fecha_adjudicacion_firma',
  'Fecha Solicitud de Contrato' => 'fecha_solicitud_contrato',
  'Producto ID' => 'producto_id',
  'Descripción del Bien y Servicio' => 'descripcion_bien_servicio',
  'Cantidad' => 'cantidad',
  'Unidad de Medida' => 'unidad_medida',
  'Monto Unitario' => 'monto_unitario',
  'Moneda de Precio' => 'moneda_precio',
  'Fecha Solicitud de Contrato CL' => 'fecha_solicitud_contrato_cl',
  'Producto ID CL' => 'producto_id_cl'
];

/* ============================================================
   5. Validar encabezados
   ============================================================ */
$csv_cols = array_map('trim', $header);
$valid_cols = array_keys($mapa);

$faltan = array_diff($valid_cols, $csv_cols);
$sobran = array_diff($csv_cols, $valid_cols);

if ($faltan || $sobran) {
  echo json_encode([
    'ok'=>false,
    'error'=>'⚠ Encabezados no coinciden con el formato esperado',
    'faltan'=>$faltan,
    'sobran'=>$sobran
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============================================================
   6. Preparar inserción
   ============================================================ */
$cols_pg = array_map(fn($c)=>$mapa[$c], $csv_cols);
$sql = 'INSERT INTO public."Procedimientos Adjudicados" (import_id,"' . implode('","', $cols_pg) . '") VALUES (' . implode(',', array_fill(0, count($cols_pg)+1, '?')) . ')';
$stmt = $pdo->prepare($sql);

$insertados = 0;
$saltados = 0;
$errores = [];
$import_id = uniqid('imp_');

$pdo->beginTransaction();
while(($r=fgetcsv($fh,0,','))!==false){
  if(count(array_filter($r,fn($x)=>trim((string)$x)!=''))==0) continue;
  array_unshift($r,$import_id);
  try { $stmt->execute($r); $insertados++; }
  catch(Throwable $e){ $saltados++; if($saltados<10) $errores[]=$e->getMessage(); }
}
$pdo->commit();
fclose($fh);

/* ============================================================
   7. Registrar log
   ============================================================ */
try {
  $pdo->prepare('INSERT INTO public.procedimientos_import_log (import_id, filename, total_rows, inserted, skipped, started_at, finished_at, source_ip)
                 VALUES (:id,:f,:t,:i,:s,now(),now(),:ip)')
      ->execute([
        ':id'=>$import_id,
        ':f'=>$nombre,
        ':t'=>$insertados+$saltados,
        ':i'=>$insertados,
        ':s'=>$saltados,
        ':ip'=>$_SERVER['REMOTE_ADDR']??null
      ]);
} catch(Throwable $e){ /* no fatal */ }

/* ============================================================
   8. Respuesta JSON
   ============================================================ */
echo json_encode([
  'ok'=>true,
  'insertados'=>$insertados,
  'saltados'=>$saltados,
  'total'=>$insertados+$saltados,
  'errores'=>$errores
], JSON_UNESCAPED_UNICODE);
