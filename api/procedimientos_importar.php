<?php
// API/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ─────────────────────────────────────────────────────────
   UI mínima (GET)
   ───────────────────────────────────────────────────────── */
if ($method === 'GET' && !isset($_GET['historial'])) {
  header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html><html lang="es"><meta charset="utf-8"><title>Importar</title>
<body style="font:14px system-ui;padding:16px">
  <h3>Endpoint de importación</h3>
  <p>Usa <code>POST</code> con el archivo en el campo <code>archivo</code>.</p>
  <ul>
    <li>GET <code>?historial=1</code> devuelve historial</li>
    <li>POST <code>accion=anular</code> (opcional <code>import_id</code>)</li>
  </ul>
</body></html>
<?php exit; }

/* ─────────────────────────────────────────────────────────
   CORS + JSON-safe
   ───────────────────────────────────────────────────────── */
if ($method === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ob_start();
set_error_handler(function($severity,$message,$file,$line){
  throw new ErrorException($message,0,$severity,$file,$line);
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

/* ─────────────────────────────────────────────────────────
   Conexión
   ───────────────────────────────────────────────────────── */
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
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()]);
  exit;
}

/* ─────────────────────────────────────────────────────────
   Helpers
   ───────────────────────────────────────────────────────── */
function limpiar_header(string $s): string {
  $s = trim($s);
  $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s); // quita caracteres invisibles
  $s = str_replace(["\xC2\xA0","\xE2\x80\x8B","\xE2\x80\x8C","\xE2\x80\x8D"], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}
function norm_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  $s2 = str_replace('/', '-', $s);
  foreach (['d-m-Y','Y-m-d','d-m-y','d-m-Y H:i','Y-m-d H:i'] as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $s2);
    if ($dt && $dt->format($fmt)===$s2) return $dt->format('Y-m-d');
  }
  if (is_numeric($s)) { $v=(int)$s; if ($v>25569 && $v<60000) return gmdate('Y-m-d', ($v-25569)*86400); }
  return null;
}
function norm_num(?string $s): ?float {
  $s = trim((string)$s);
  if ($s==='' || strtoupper($s)==='NULL') return null;
  $s = str_replace(['₡','$','CRC','USD',' '], '', $s);
  if (substr_count($s, ',')===1 && substr_count($s, '.')===0) $s = str_replace(',', '.', $s);
  else $s = str_replace(',', '', $s);
  return is_numeric($s)?(float)$s:null;
}
function uuidv4(): string {
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/* ─────────────────────────────────────────────────────────
   GET historial
   ───────────────────────────────────────────────────────── */
if ($method === 'GET' && isset($_GET['historial'])) {
  $rows = $pdo->query('SELECT import_id, filename, mode, inserted, skipped, started_at, finished_at FROM public.import_log ORDER BY started_at DESC LIMIT 50')->fetchAll();
  echo json_encode(['ok'=>true,'historial'=>$rows]);
  exit;
}

/* ─────────────────────────────────────────────────────────
   POST importar
   ───────────────────────────────────────────────────────── */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$name = $_FILES['archivo']['name'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt','tsv'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido']);
  exit;
}

/* Columnas esperadas */
$canon = [
  'Mes de Descarga','Año de reporte','CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO','MODALIDAD_PROCEDIMIENTO','fecha_rev',
  'CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR','PERFIL_PROV','CEDULA_REPRESENTANTE','REPRESENTANTE','OBJETO_GASTO',
  'MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD','FECHA_ADJUD_FIRME',
  'FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO','CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO',
  'MONEDA_PRECIO_EST','FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];

$fh = fopen($tmp, 'r');
$header = fgetcsv($fh, 0, ',', '"', '"');
if (!$header) {
  echo json_encode(['ok'=>false,'error'=>'No se pudo leer encabezados']);
  exit;
}

/* Limpieza de encabezados */
$header = array_map('limpiar_header', $header);

/* Validación */
$faltan = array_diff($canon, $header);
if ($faltan) {
  echo json_encode(['ok'=>false,'error'=>'Encabezados no coinciden','faltan'=>$faltan,'en_archivo'=>$header]);
  exit;
}

/* Si pasa la validación: solo confirmamos */
echo json_encode(['ok'=>true,'mensaje'=>'Encabezados validados correctamente','encabezados'=>$header]);
exit;
?>
