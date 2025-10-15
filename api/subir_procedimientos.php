<?php
// subir_procedimientos.php
declare(strict_types=1);
@ini_set('memory_limit','1G');
@set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ==========================================================
   1. Conexión a PostgreSQL
   ========================================================== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], $p['port']??5432, ltrim($p['path'],'/')
  );
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error conexión: '.$e->getMessage()]);
  exit;
}

/* ==========================================================
   2. Funciones helper
   ========================================================== */
function clean_label(string $s): string {
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
  $s = str_replace(["\xC2\xA0","\xE2\x80\x8B","\xE2\x80\x8C","\xE2\x80\x8D"], ' ', $s);
  $s = str_replace(['“','”','"',"'"], '', $s);
  $s = preg_replace('/\s+/u', ' ', trim($s));
  return strtoupper($s);
}

function norm_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = preg_replace('/^(lunes|martes|miércoles|miercoles|jueves|viernes|sábado|sabado|domingo),?\s*/iu', '', $s);
  $s = str_ireplace(['de '], '', $s);
  $s = str_replace([','], '', $s);
  $meses = [
    'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
    'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12
  ];
  if (preg_match('/(\d{1,2})\s+([a-záéíóú]+)\s+(\d{4})/iu',$s,$m)){
    $dia=(int)$m[1]; $mes=$meses[strtolower($m[2])]??0; $anio=(int)$m[3];
    if($mes>0) return sprintf('%04d-%02d-%02d',$anio,$mes,$dia);
  }
  foreach(['d/m/Y','d-m-Y','Y-m-d'] as $fmt){
    $dt=DateTime::createFromFormat($fmt,$s);
    if($dt) return $dt->format('Y-m-d');
  }
  return null;
}

function norm_num(?string $s): ?float {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = str_replace(['₡','$','CRC','USD'], '', $s);
  $s = str_replace([' ', ','], ['', '.'], $s);
  return is_numeric($s) ? (float)$s : null;
}

function norm_bigint_text(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = str_replace([' ', ','], ['', '.'], $s);
  if (preg_match('/^(\d+(?:\.\d+)?)e\+?(-?\d+)$/i', $s, $m)) {
    $mant=$m[1]; $exp=(int)$m[2];
    $mantParts = explode('.', $mant);
    $int = $mantParts[0];
    $frac = $mantParts[1] ?? '';
    $num = $int . $frac . str_repeat('0', max(0, $exp - strlen($frac)));
    return ltrim($num, '0') ?: '0';
  }
  $digits = preg_replace('/\D+/', '', $s);
  return $digits === '' ? null : $digits;
}

/* ==========================================================
   3. Subida y validación
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido']);
  exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$name = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt'])) {
  echo json_encode(['ok'=>false,'error'=>'Solo se permiten archivos CSV o TXT']);
  exit;
}

/* ==========================================================
   4. Lectura e inserción
   ========================================================== */
$fh = fopen($tmp, 'r');
$firstLine = fgets($fh);
rewind($fh);

// ✅ delimitador corregido
$counts = [
  ','  => substr_count($firstLine, ','),
  ';'  => substr_count($firstLine, ';'),
  "\t" => substr_count($firstLine, "\t")
];
$delimiter = array_search(max($counts), $counts) ?: ',';

$header = fgetcsv($fh, 0, $delimiter);
$header = array_map('clean_label', $header);

$cols = [
  'MES DE DESCARGA','AÑO DE REPORTE','CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO','MODALIDAD_PROCEDIMIENTO',
  'FECHA_REV','CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR','PERFIL_PROV','CEDULA_REPRESENTANTE',
  'REPRESENTANTE','OBJETO_GASTO','MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC',
  'MONTO_ADJU_LINEA_USD','FECHA_ADJUD_FIRME','FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO',
  'CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO','MONEDA_PRECIO_EST','FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];

// ✅ Validar encabezados
$diff = array_diff($cols, $header);
if (count($diff) > 0) {
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden con el formato esperado','faltantes'=>$diff]);
  fclose($fh);
  exit;
}

// ✅ Nombre de tabla corregido
$stmt = $pdo->prepare('INSERT INTO public.procedimientos_adjudicados ("' . implode('","', $cols) . '") VALUES (' .
  implode(',', array_map(fn($c) => ':' . strtolower(str_replace(' ','_',$c)), $cols)) . ')');

$insertados = 0; $saltados = 0; $errores = [];
$pdo->beginTransaction();

while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
  if (count(array_filter($r, fn($x)=>trim((string)$x)!='')) == 0) continue;
  if (count($r) < count($cols)) { $saltados++; continue; }

  $params = [];
  foreach ($cols as $i => $c) {
    $val = $r[$i] ?? null;
    $key = ':' . strtolower(str_replace(' ','_',$c));
    switch ($c) {
      case 'FECHA_REV': case 'FECHA_ADJUD_FIRME': case 'FECHA_SOL_CONTRA': case 'FECHA_SOL_CONTRA_CL':
        $params[$key] = norm_date($val); break;
      case 'MONTO_ADJU_LINEA': case 'MONTO_ADJU_LINEA_CRC': case 'MONTO_ADJU_LINEA_USD':
      case 'CANTIDAD': case 'MONTO_UNITARIO':
        $params[$key] = norm_num($val); break;
      case 'PROD_ID': case 'PROD_ID_CL':
        $params[$key] = norm_bigint_text($val); break;
      default:
        $params[$key] = trim((string)$val) ?: null; break;
    }
  }

  try { $stmt->execute($params); $insertados++; }
  catch (Throwable $e) { $saltados++; if ($saltados < 20) $errores[] = $e->getMessage(); }
}

$pdo->commit();
fclose($fh);

/* ==========================================================
   5. Registrar log
   ========================================================== */
try {
  $pdo->prepare("INSERT INTO procedimientos_import_log (import_id, archivo, insertados, saltados, total_filas, inicio, fin, modo)
                 VALUES (:id,:f,:i,:s,:t,now(),now(),'csv')")
      ->execute([
        ':id' => 'imp_' . uniqid(),
        ':f'  => $name,
        ':i'  => $insertados,
        ':s'  => $saltados,
        ':t'  => $insertados + $saltados
      ]);
} catch (Throwable $e) { /* no fatal */ }

/* ==========================================================
   6. Respuesta JSON
   ========================================================== */
echo json_encode([
  'ok' => true,
  'insertados' => $insertados,
  'saltados' => $saltados,
  'total' => $insertados + $saltados,
  'errores' => $errores
], JSON_UNESCAPED_UNICODE);
/