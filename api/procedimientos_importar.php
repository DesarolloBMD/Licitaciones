<?php
// API/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ==============================
   UI (GET) opcional
   ============================== */
if ($method === 'GET') {
  header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Importar Procedimientos Adjudicados</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
</head>
<body class="p-3">
  <div class="container"><h1 class="h5">Endpoint de importación</h1><p>Usa POST con archivo.</p></div>
</body></html>
<?php exit; }

/* ==============================
   CORS / HEADERS (POST) + JSON-safe
   ============================== */
if ($method === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ini_set('display_errors', '0'); // no escupir HTML
ob_start();
set_error_handler(function($severity,$message,$file,$line){
  throw new ErrorException($message,0,$severity,$file,$line);
});
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true);
    $out = ob_get_clean();
    echo json_encode([
      'ok'    => false,
      'error' => 'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line'],
      'debug' => $out ? trim(strip_tags($out)) : null,
    ], JSON_UNESCAPED_UNICODE);
  }
});

if ($method !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==============================
   Validación extensión
   ============================== */
$name = $_FILES['archivo']['name'] ?? '';
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt','tsv'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido. Use .csv, .tsv o .txt'], JSON_UNESCAPED_UNICODE);
  exit;
}

ignore_user_abort(false);

/* ==============================
   CONEXIÓN A POSTGRES
   ============================== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], isset($p['port'])?(int)$p['port']:5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => true,
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==============================
   HELPERS (encoding + normalización)
   ============================== */
function to_utf8($s): string {
  $s = (string)($s ?? '');
  if ($s === '') return '';
  if (function_exists('mb_detect_encoding') && mb_detect_encoding($s, 'UTF-8', true)) return $s;
  foreach (['Windows-1252','ISO-8859-1','ISO-8859-15'] as $enc) {
    $conv = @mb_convert_encoding($s, 'UTF-8', $enc);
    if ($conv !== false && $conv !== '') return $conv;
  }
  return $s;
}
function u_upper(string $s): string {
  return function_exists('mb_strtoupper') ? mb_strtoupper($s,'UTF-8') : strtoupper($s);
}
function norm_header($s): string {
  $s = to_utf8($s);
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = str_replace(["\xC2\xA0","\xE2\x80\x8B","\xE2\x80\x8C","\xE2\x80\x8D"], ' ', $s);
  $s = trim($s);
  $tmp = preg_replace('/\s+/u', ' ', $s);
  if ($tmp === null) $tmp = preg_replace('/\s+/', ' ', $s);
  return $tmp ?? '';
}
function norm_key($s): string { return u_upper(norm_header((string)$s)); }

function norm_date(?string $s): ?string {
  $s = trim((string)$s); if ($s==='' || strtoupper($s)==='NULL') return null;
  $s2 = str_replace('/', '-', $s);
  foreach (['d-m-Y','d-m-y','Y-m-d','d-m-Y H:i:s','d-m-Y H:i'] as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $s2);
    if ($dt && $dt->format($fmt) === $s2) return $dt->format('Y-m-d');
  }
  if (is_numeric($s)) { $v=(int)$s; if ($v>25569 && $v<60000) return gmdate('Y-m-d', ($v-25569)*86400); }
  return null;
}
function norm_num(?string $s): ?float {
  $s = trim((string)$s); if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = str_replace(['₡','$','CRC','USD',' '], '', $s);
  if (substr_count($s, ',')===1 && substr_count($s, '.')===0) $s = str_replace(',', '.', $s);
  else $s = str_replace(',', '', $s);
  return is_numeric($s) ? (float)$s : null;
}
function norm_bigint_text(?string $s): ?string {
  $s = trim((string)$s); if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = str_replace(' ', '', $s); $s = str_replace(',', '.', $s);
  if (preg_match('/^(\d+(?:\.\d+)?)e\+?(-?\d+)$/i', $s, $m)) {
    $mant=$m[1]; $exp=(int)$m[2]; $int=$mant; $frac='';
    if (strpos($mant,'.')!==false) [$int,$frac]=explode('.',$mant,2);
    if ($exp >= strlen($frac)) $digits = $int.$frac.str_repeat('0',$exp - strlen($frac)); else $digits = $int.substr($frac,0,$exp);
    $digits = ltrim($digits,'0'); return $digits===''?'0':$digits;
  }
  $digits = preg_replace('/\D+/', '', $s); return $digits === '' ? null : $digits;
}

/* —— Normalización de etiquetas (encabezados) —— */
function strip_accents($s): string {
  $s = to_utf8($s);
  $from = 'ÁáÉéÍíÓóÚúÜüÑñ';
  $to   = 'AaEeIiOoUuUuNn';
  return strtr($s, $from, $to);
}
function label_key($s): string {
  $s = norm_header($s);
  $s = strip_accents($s);
  $s = u_upper($s);
  $tmp = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
  if ($tmp === null) $tmp = preg_replace('/[^A-Z0-9]+/', ' ', $s);
  $s = $tmp ?? '';
  $tmp = preg_replace('/\s+/u', ' ', trim($s));
  if ($tmp === null) $tmp = preg_replace('/\s+/', ' ', trim($s));
  $s = $tmp ?? '';
  $tokens = array_values(array_filter(explode(' ', $s), function($t){
    static $stop=['DE','DEL','EL','LA','LOS','LAS','OF','THE'];
    return $t!=='' && !in_array($t,$stop,true);
  }));
  return implode(' ', $tokens); // ej: "ANO REPORTE"
}

/* ==============================
   DEFINICIÓN DE COLUMNAS
   ============================== */
$canon = [
  'Mes de Descarga',
  'Año de reporte',
  'CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO',
  'MODALIDAD_PROCEDIMIENTO','fecha_rev','CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR',
  'PERFIL_PROV','CEDULA_REPRESENTANTE','REPRESENTANTE','OBJETO_GASTO',
  'MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD',
  'FECHA_ADJUD_FIRME','FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO',
  'CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO','MONEDA_PRECIO_EST',
  'FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];
$optional = ['Año de reporte' => true];

$syn = [
  'MES DE DESCARGA'  => 'Mes de Descarga',
  'AÑO DE REPORTE'   => 'Año de reporte',
  'ANIO DE REPORTE'  => 'Año de reporte',
  'AÑO REPORTE'      => 'Año de reporte',
  'ANIO REPORTE'     => 'Año de reporte',
  'AÑO DEL REPORTE'  => 'Año de reporte',
  'ANIO DEL REPORTE' => 'Año de reporte',
  'YEAR OF REPORT'   => 'Año de reporte',
  'YEAR REPORT'      => 'Año de reporte',
  'YEAR_REPORT'      => 'Año de reporte',
];

$ph = [
  'Mes de Descarga'=>'c_mes_descarga',
  'Año de reporte'=>'c_ano_reporte',
  'CEDULA'=>'c_cedula', 'INSTITUCION'=>'c_institucion', 'ANO'=>'c_ano',
  'NUMERO_PROCEDIMIENTO'=>'c_numero_procedimiento', 'DESCR_PROCEDIMIENTO'=>'c_descr_procedimiento',
  'LINEA'=>'c_linea', 'NRO_SICOP'=>'c_nro_sicop', 'TIPO_PROCEDIMIENTO'=>'c_tipo_procedimiento',
  'MODALIDAD_PROCEDIMIENTO'=>'c_modalidad_procedimiento', 'fecha_rev'=>'c_fecha_rev',
  'CEDULA_PROVEEDOR'=>'c_cedula_proveedor', 'NOMBRE_PROVEEDOR'=>'c_nombre_proveedor',
  'PERFIL_PROV'=>'c_perfil_prov', 'CEDULA_REPRESENTANTE'=>'c_cedula_representante',
  'REPRESENTANTE'=>'c_representante', 'OBJETO_GASTO'=>'c_objeto_gasto',
  'MONEDA_ADJUDICADA'=>'c_moneda_adjudicada', 'MONTO_ADJU_LINEA'=>'c_monto_adju_linea',
  'MONTO_ADJU_LINEA_CRC'=>'c_monto_adju_linea_crc', 'MONTO_ADJU_LINEA_USD'=>'c_monto_adju_linea_usd',
  'FECHA_ADJUD_FIRME'=>'c_fecha_adjud_firme', 'FECHA_SOL_CONTRA'=>'c_fecha_sol_contra',
  'PROD_ID'=>'c_prod_id', 'DESCR_BIEN_SERVICIO'=>'c_descr_bien_servicio', 'CANTIDAD'=>'c_cantidad',
  'UNIDAD_MEDIDA'=>'c_unidad_medida', 'MONTO_UNITARIO'=>'c_monto_unitario',
  'MONEDA_PRECIO_EST'=>'c_moneda_precio_est', 'FECHA_SOL_CONTRA_CL'=>'c_fecha_sol_contra_cl',
  'PROD_ID_CL'=>'c_prod_id_cl'
];

/* —— Diccionario canónico por clave normalizada —— */
$canonByKey = [];
foreach ($canon as $c) { $canonByKey[label_key($c)] = $c; }
foreach ($syn as $k => $target) { $canonByKey[label_key($k)] = $target; }

/* ==============================
   ABRIR ARCHIVO + DELIMITADOR
   ============================== */
$tmp = $_FILES['archivo']['tmp_name'];
$fh  = fopen($tmp, 'r');
if (!$fh) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo subido'], JSON_UNESCAPED_UNICODE); exit; }

/* Detectar delimitador */
$firstLineRaw = fgets($fh);
if ($firstLineRaw === false) { fclose($fh); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo vacío'], JSON_UNESCAPED_UNICODE); exit; }
rewind($fh);
$cands = [","=>substr_count($firstLineRaw,","), ";"=>substr_count($firstLineRaw,";"), "\t"=>substr_count($firstLineRaw,"\t"), "|"=>substr_count($firstLineRaw,"|")];
arsort($cands); $delimiter = array_key_first($cands) ?: ","; if (($cands[$delimiter] ?? 0) === 0) $delimiter = ",";

/* Leer encabezados */
$header = fgetcsv($fh, 0, $delimiter);
if ($header === false || $header === null) {
  fclose($fh);
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'No se pudo leer la fila de encabezados'], JSON_UNESCAPED_UNICODE);
  exit;
}
$header = array_map('norm_header', (array)$header);
$header_norm_join = implode('|', array_map('label_key', $header));

/* Mapear encabezados */
$map = []; $canon_set = array_fill_keys($canon, false); $en_archivo=[];
for ($i=0; $i<count($header); $i++) {
  $h = $header[$i]; if ($h==='') continue;
  $key = label_key($h);
  $hCanon = $canonByKey[$key] ?? null;
  if ($hCanon !== null) { $map[$i]=$hCanon; $canon_set[$hCanon]=true; }
  $en_archivo[]=$h;
}
$faltan = [];
foreach ($canon_set as $c=>$ok) { if (!$ok && empty($optional[$c])) $faltan[]=$c; }
if ($faltan) {
  fclose($fh);
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden con la tabla','faltan'=>$faltan,'en_archivo'=>$en_archivo], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==============================
   PREP INSERT (con fingerprint + ON CONFLICT)
   ============================== */
$colsSql  = implode('","', $canon);
$placeSql = implode(', ', array_map(fn($c)=>':'.$ph[$c], $canon));
$sql = 'INSERT INTO public."Procedimientos Adjudicados" ("'.$colsSql.'","fingerprint")
        VALUES ('.$placeSql.', :fp)
        ON CONFLICT ("fingerprint") DO NOTHING';
$stmt = $pdo->prepare($sql);

/* ==============================
   IMPORTAR (desduplicación por archivo + BD)
   ============================== */
$insertados=0; $saltados=0; $errores=[]; $linea=1;
$seen = []; // huellas vistas en ESTE upload

$pdo->beginTransaction();

while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
  $linea++;

  // Saltar filas completamente vacías
  $allEmpty = true;
  foreach ($row as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
  if ($allEmpty) continue;

  // Saltar encabezados repetidos dentro del archivo
  $row_norm_join = implode('|', array_map('label_key', array_map('strval', $row)));
  if ($row_norm_join === $header_norm_join) continue;

  // valores brutos por columna canónica
  $vals = [];
  foreach ($map as $idx=>$canonName) { $vals[$canonName] = $row[$idx] ?? null; }

  // Si NO viene "Año de reporte" en el archivo, queda NULL (sin derivar)
  if (!array_key_exists('Año de reporte', $vals)) {
    $vals['Año de reporte'] = null;
  }

  // Normalización por tipo
  $params = [];
  foreach ($canon as $cname) {
    $raw = $vals[$cname] ?? null;
    switch ($cname) {
      case 'fecha_rev':
      case 'FECHA_ADJUD_FIRME':
      case 'FECHA_SOL_CONTRA':
      case 'FECHA_SOL_CONTRA_CL':
        $params[':'.$ph[$cname]] = norm_date($raw);
        break;

      case 'MONTO_ADJU_LINEA':
      case 'MONTO_ADJU_LINEA_CRC':
      case 'MONTO_ADJU_LINEA_USD':
      case 'CANTIDAD':
      case 'MONTO_UNITARIO':
        $params[':'.$ph[$cname]] = norm_num($raw);
        break;

      case 'PROD_ID':
      case 'PROD_ID_CL':
        $params[':'.$ph[$cname]] = norm_bigint_text($raw);
        break;

      case 'Año de reporte': // guardar exactamente lo que viene (texto)
        $val = trim((string)$raw);
        $params[':'.$ph[$cname]] = ($val === '') ? null : $val;
        break;

      default:
        $val = trim((string)$raw);
        $params[':'.$ph[$cname]] = ($val==='') ? null : $val;
        break;
    }
  }

  // Huella de la fila YA normalizada (orden canónico, separador '|')
  $concat = [];
  foreach ($canon as $cname) {
    $concat[] = (string)($params[':'.$ph[$cname]] ?? '');
  }
  $fp = md5(implode('|', $concat));
  $params[':fp'] = $fp;

  // Desduplicación dentro del mismo upload (evita insertar y forzar conflicto innecesario)
  if (isset($seen[$fp])) { $saltados++; continue; }
  $seen[$fp] = true;

  // Insert con SAVEPOINT (tolerante a errores) + ON CONFLICT DO NOTHING
  $sp = 'sp_'.$linea;
  $pdo->exec("SAVEPOINT $sp");
  try {
    $stmt->execute($params);
    if ($stmt->rowCount() === 1) $insertados++; else $saltados++; // conflicto → 0 filas
  } catch (Throwable $e) {
    $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
    $saltados++;
    if (count($errores)<1000) $errores[] = "Línea $linea: ".$e->getMessage();
  }
}
$pdo->commit();
fclose($fh);

/* Respuesta JSON */
$debug = ob_get_contents();
ob_end_clean();
echo json_encode([
  'ok'         => true,
  'insertados' => $insertados,
  'saltados'   => $saltados,
  'errores'    => $errores,
  'warnings'   => [],
  'debug'      => $debug ? trim(strip_tags($debug)) : null,
], JSON_UNESCAPED_UNICODE);
