<?php
// api/subir_procedimientos.php
declare(strict_types=1);
@ini_set('memory_limit', '1024M');
@set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ======== Conexión a Postgres ======== */
$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
try {
  $p=parse_url($DATABASE_URL);
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo=new PDO($dsn,$p['user'],$p['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()],JSON_UNESCAPED_UNICODE);
  exit;
}

/* ======== Helpers ======== */
function limpiar_encabezado(?string $s): string {
  if ($s === null) return '';
  $s = trim(preg_replace('/^\xEF\xBB\xBF/','', $s));
  return mb_convert_encoding($s, 'UTF-8', 'auto');
}

function normalizar_texto(?string $s): string {
  if ($s === null) return '';
  return trim(mb_convert_encoding($s, 'UTF-8', 'auto'));
}

function normalizar_numero(?string $s): ?string {
  if (!$s) return null;
  $s = str_replace([' ', '₡', '$'], '', $s);
  $s = str_replace(',', '.', $s);
  $s = preg_replace('/[^0-9.\-]/', '', $s);
  return $s === '' ? null : $s;
}

function normalizar_fecha(?string $s): ?string {
  if (!$s) return null;
  $s = trim(str_ireplace(
    ['lunes,', 'martes,', 'miércoles,', 'miercoles,', 'jueves,', 'viernes,', 'sábado,', 'sabado,', 'domingo,'],
    '',
    $s
  ));
  $s = trim($s);
  $ts = strtotime($s);
  return $ts ? date('Y-m-d', $ts) : null;
}

function build_fingerprint(array $r): string {
  return md5(implode('|', [
    $r['Mes de Descarga'] ?? '',
    $r['Año de reporte'] ?? '',
    $r['CEDULA'] ?? '',
    $r['INSTITUCION'] ?? '',
    $r['NUMERO_PROCEDIMIENTO'] ?? '',
    $r['LINEA'] ?? '',
    $r['CEDULA_PROVEEDOR'] ?? '',
    $r['NOMBRE_PROVEEDOR'] ?? '',
    $r['MONTO_ADJU_LINEA_CRC'] ?? ''
  ]));
}

/* ======== Validar archivo ======== */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}
$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','xlsx'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido (solo CSV o XLSX)']);
  exit;
}

/* ======== Leer CSV ======== */
$fh = fopen($tmp, 'r');
if (!$fh) { echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']); exit; }
$firstLine = fgets($fh); rewind($fh);
$delims = [","=>substr_count($firstLine,","),";"=>substr_count($firstLine,";"),"\t"=>substr_count($firstLine,"\t")];
arsort($delims); $delim = array_key_first($delims) ?: ";";
$headers = fgetcsv($fh, 0, $delim);
$headers = array_map('limpiar_encabezado', $headers);

/* ======== Preparar inserción ======== */
$sql = 'INSERT INTO public."Procedimientos Adjudicados"
("Mes de Descarga","Año de reporte","CEDULA","INSTITUCION","ANO","NUMERO_PROCEDIMIENTO","DESCR_PROCEDIMIENTO","LINEA","NRO_SICOP",
 "TIPO_PROCEDIMIENTO","MODALIDAD_PROCEDIMIENTO","fecha_rev","CEDULA_PROVEEDOR","NOMBRE_PROVEEDOR","PERFIL_PROV","CEDULA_REPRESENTANTE",
 "REPRESENTANTE","OBJETO_GASTO","MONEDA_ADJUDICADA","MONTO_ADJU_LINEA","MONTO_ADJU_LINEA_CRC","MONTO_ADJU_LINEA_USD",
 "FECHA_ADJUD_FIRME","FECHA_SOL_CONTRA","PROD_ID","DESCR_BIEN_SERVICIO","CANTIDAD","UNIDAD_MEDIDA","MONTO_UNITARIO","MONEDA_PRECIO_EST",
 "FECHA_SOL_CONTRA_CL","PROD_ID_CL",fingerprint,import_id,created_at,updated_at)
 VALUES (:Mes,:Anio_rep,:CED,:INST,:ANO,:NUM,:DESCR,:LINEA,:SICOP,:TIPO,:MODAL,:FREV,:CEDPROV,:NOMPROV,:PERFIL,:CEDREP,:REP,:OBJ,
         :MONEDA,:MONTOL,:MONTOLC,:MONTOLU,:FADJ,:FSOL,:PROD,:DESCRB,:CANT,:UNIDAD,:MONTOU,:MONEDAE,:FSOLCL,:PRODCL,:FP,:IMP,now(),now())
 ON CONFLICT (fingerprint) DO NOTHING';
$stmt = $pdo->prepare($sql);

/* ======== Bucle de importación ======== */
$total = 0; $inserted = 0; $skipped = 0; $errors = [];
$import_id = bin2hex(random_bytes(8));

while (($row = fgetcsv($fh, 0, $delim)) !== false) {
  $total++;
  if (count(array_filter($row)) == 0) continue;
  if (count($row) != count($headers)) { $skipped++; continue; }

  $r = array_combine($headers, array_map('normalizar_texto', $row));
  $fp = build_fingerprint($r);
  if (!$fp || strlen($fp) < 10) $fp = md5(json_encode($r)); // fallback

  try {
    $bind = [
      ':Mes'      => $r['Mes de Descarga'] ?? null,
      ':Anio_rep' => $r['Año de reporte'] ?? null,
      ':CED'      => $r['CEDULA'] ?? null,
      ':INST'     => $r['INSTITUCION'] ?? null,
      ':ANO'      => $r['ANO'] ?? null,
      ':NUM'      => $r['NUMERO_PROCEDIMIENTO'] ?? null,
      ':DESCR'    => $r['DESCR_PROCEDIMIENTO'] ?? null,
      ':LINEA'    => $r['LINEA'] ?? null,
      ':SICOP'    => $r['NRO_SICOP'] ?? null,
      ':TIPO'     => $r['TIPO_PROCEDIMIENTO'] ?? null,
      ':MODAL'    => $r['MODALIDAD_PROCEDIMIENTO'] ?? null,
      ':FREV'     => normalizar_fecha($r['fecha_rev'] ?? null),
      ':CEDPROV'  => $r['CEDULA_PROVEEDOR'] ?? null,
      ':NOMPROV'  => $r['NOMBRE_PROVEEDOR'] ?? null,
      ':PERFIL'   => $r['PERFIL_PROV'] ?? null,
      ':CEDREP'   => $r['CEDULA_REPRESENTANTE'] ?? null,
      ':REP'      => $r['REPRESENTANTE'] ?? null,
      ':OBJ'      => $r['OBJETO_GASTO'] ?? null,
      ':MONEDA'   => $r['MONEDA_ADJUDICADA'] ?? null,
      ':MONTOL'   => normalizar_numero($r['MONTO_ADJU_LINEA'] ?? null),
      ':MONTOLC'  => normalizar_numero($r['MONTO_ADJU_LINEA_CRC'] ?? null),
      ':MONTOLU'  => normalizar_numero($r['MONTO_ADJU_LINEA_USD'] ?? null),
      ':FADJ'     => normalizar_fecha($r['FECHA_ADJUD_FIRME'] ?? null),
      ':FSOL'     => normalizar_fecha($r['FECHA_SOL_CONTRA'] ?? null),
      ':PROD'     => $r['PROD_ID'] ?? null,
      ':DESCRB'   => $r['DESCR_BIEN_SERVICIO'] ?? null,
      ':CANT'     => normalizar_numero($r['CANTIDAD'] ?? null),
      ':UNIDAD'   => $r['UNIDAD_MEDIDA'] ?? null,
      ':MONTOU'   => normalizar_numero($r['MONTO_UNITARIO'] ?? null),
      ':MONEDAE'  => $r['MONEDA_PRECIO_EST'] ?? null,
      ':FSOLCL'   => normalizar_fecha($r['FECHA_SOL_CONTRA_CL'] ?? null),
      ':PRODCL'   => $r['PROD_ID_CL'] ?? null,
      ':FP'       => $fp,
      ':IMP'      => $import_id
    ];

    $stmt->execute($bind);
    $rows = $stmt->rowCount();
    if ($rows === 0) {
      $skipped++;
      $errors[] = "Línea {$total}: duplicada (fingerprint ya existe)";
    } else {
      $inserted += $rows;
    }

  } catch (Throwable $e) {
    $skipped++;
    $errors[] = "Línea {$total}: ".$e->getMessage();
  }
}

fclose($fh);

echo json_encode([
  'ok'=>true,
  'insertados'=>$inserted,
  'saltados'=>$skipped,
  'total'=>$total,
  'errores'=>$errors
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
