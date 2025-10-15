<?php
declare(strict_types=1);
@ini_set('memory_limit', '1G');
@set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/* ============================================================
   1. Conexión a PostgreSQL
   ============================================================ */
$DATABASE_URL = getenv('DATABASE_URL') ?: 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;sslmode=require", $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Error conexión: ' . $e->getMessage()]);
  exit;
}

/* ============================================================
   2. Mostrar historial
   ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'historial') {
  try {
    $rows = $pdo->query("SELECT * FROM public.procedimientos_import_log ORDER BY started_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'rows' => $rows]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/* ============================================================
   3. Validar archivo y datos
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
  exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok' => false, 'error' => 'Archivo no recibido']);
  exit;
}

$mes_descarga = $_POST['mes_descarga'] ?? null;
$anio_descarga = $_POST['anio_descarga'] ?? null;
if (!$mes_descarga || !$anio_descarga) {
  echo json_encode(['ok' => false, 'error' => 'Debe seleccionar Mes y Año de Descarga']);
  exit;
}

/* ============================================================
   4. Validar extensión y abrir archivo
   ============================================================ */
$nombre = $_FILES['archivo']['name'];
$tmp = $_FILES['archivo']['tmp_name'];
$ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt'])) {
  echo json_encode(['ok' => false, 'error' => 'Solo se permiten archivos .csv o .txt']);
  exit;
}

$fh = fopen($tmp, 'r');
if (!$fh) {
  echo json_encode(['ok' => false, 'error' => 'No se pudo abrir el archivo']);
  exit;
}

/* ============================================================
   5. Detectar delimitador automáticamente
   ============================================================ */
$firstLine = fgets($fh);
rewind($fh);
$counts = [
  ','  => substr_count($firstLine, ','),
  ';'  => substr_count($firstLine, ';'),
  "\t" => substr_count($firstLine, "\t"),
];
arsort($counts);
$delimiter = key($counts);

/* ============================================================
   6. Leer y limpiar encabezados
   ============================================================ */
$header = fgetcsv($fh, 0, $delimiter);
if (!$header) {
  echo json_encode(['ok' => false, 'error' => 'Archivo vacío o sin encabezados']);
  exit;
}

function clean_header(string $h): string {
  $h = str_replace(
    ["\xEF\xBB\xBF", "\r", "\n", "\t", "\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}"],
    '',
    trim($h)
  );
  $h = preg_replace('/\s+/u', ' ', $h);
  $h = str_replace(['Á','É','Í','Ó','Ú','á','é','í','ó','ú'], ['A','E','I','O','U','A','E','I','O','U'], $h);
  return strtoupper(trim($h));
}
$csv_cols = array_map('clean_header', $header);

/* ============================================================
   7. Mapa esperado de encabezados → columnas base
   ============================================================ */
$mapa = [
  'CEDULA' => 'cedula',
  'INSTITUCION' => 'institucion',
  'ANO' => 'ano',
  'NUMERO_PROCEDIMIENTO' => 'numero_procedimiento',
  'DESCR_PROCEDIMIENTO' => 'descr_procedimiento',
  'LINEA' => 'linea',
  'NRO_SICOP' => 'nro_sicop',
  'TIPO_PROCEDIMIENTO' => 'tipo_procedimiento',
  'MODALIDAD_PROCEDIMIENTO' => 'modalidad_procedimiento',
  'FECHA_REV' => 'fecha_rev',
  'CEDULA_PROVEEDOR' => 'cedula_proveedor',
  'NOMBRE_PROVEEDOR' => 'nombre_proveedor',
  'PERFIL_PROV' => 'perfil_prov',
  'CEDULA_REPRESENTANTE' => 'cedula_representante',
  'REPRESENTANTE' => 'representante',
  'OBJETO_GASTO' => 'objeto_gasto',
  'MONEDA_ADJUDICADA' => 'moneda_adjudicada',
  'MONTO_ADJU_LINEA' => 'monto_adju_linea',
  'MONTO_ADJU_LINEA_CRC' => 'monto_adju_linea_crc',
  'MONTO_ADJU_LINEA_USD' => 'monto_adju_linea_usd',
  'FECHA_ADJUD_FIRME' => 'fecha_adjud_firme',
  'FECHA_SOL_CONTRA' => 'fecha_sol_contra',
  'PROD_ID' => 'prod_id',
  'DESCR_BIEN_SERVICIO' => 'descr_bien_servicio',
  'CANTIDAD' => 'cantidad',
  'UNIDAD_MEDIDA' => 'unidad_medida',
  'MONTO_UNITARIO' => 'monto_unitario',
  'MONEDA_PRECIO_EST' => 'moneda_precio_est',
  'FECHA_SOL_CONTRA_CL' => 'fecha_sol_contra_cl',
  'PROD_ID_CL' => 'prod_id_cl'
];

/* ============================================================
   8. Validar encabezados
   ============================================================ */
$faltan = array_diff(array_keys($mapa), $csv_cols);
$sobran = array_diff($csv_cols, array_keys($mapa));

if ($faltan || $sobran) {
  echo json_encode([
    'ok' => false,
    'error' => 'Encabezados no coinciden con el formato esperado',
    'faltan' => array_values($faltan),
    'sobran' => array_values($sobran),
    'detectados' => array_values($csv_cols)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============================================================
   9. Inserción masiva (con limpieza de datos y fechas)
   ============================================================ */
function parseDate($val) {
  $val = trim((string)$val);
  if ($val === '' || strtolower($val) === 'null') return null;
  $formats = ['d/m/Y', 'd-m-Y', 'Y/m/d', 'Y-m-d'];
  foreach ($formats as $f) {
    $d = DateTime::createFromFormat($f, $val);
    if ($d && $d->format($f) === $val) return $d->format('Y-m-d');
  }
  return null;
}

$cols_pg = array_map(fn($c) => $mapa[$c], $csv_cols);
$import_id = uniqid('imp_');
$sql = 'INSERT INTO public."Procedimientos Adjudicados" (import_id, mes_descarga, anio_descarga, "' . implode('","', $cols_pg) . '")
        VALUES (' . implode(',', array_fill(0, count($cols_pg) + 3, '?')) . ')';
$stmt = $pdo->prepare($sql);

$insertados = 0;
$saltados = 0;
$errores = [];
$pdo->beginTransaction();

while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
  if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) == 0) continue;

  // Limpiar valores vacíos
  foreach ($r as &$v) {
    $v = trim($v);
    if ($v === '') $v = null;
  }

  // Convertir fechas
  $fechas = ['FECHA_REV','FECHA_ADJUD_FIRME','FECHA_SOL_CONTRA','FECHA_SOL_CONTRA_CL'];
  foreach ($csv_cols as $i => $col) {
    if (in_array($col, $fechas)) $r[$i] = parseDate($r[$i]);
  }

  $valores = array_merge([$import_id, $mes_descarga, $anio_descarga], $r);

  try {
    $stmt->execute($valores);
    $insertados++;
  } catch (Throwable $e) {
    $saltados++;
    if ($saltados < 10) $errores[] = $e->getMessage();
  }
}

$pdo->commit();
fclose($fh);

/* ============================================================
   10. Registrar importación en log
   ============================================================ */
try {
  $pdo->prepare('INSERT INTO public.procedimientos_import_log (import_id, filename, mes_descarga, anio_descarga, total_rows, inserted, skipped, started_at, finished_at, source_ip)
                 VALUES (:id,:f,:m,:a,:t,:i,:s,now(),now(),:ip)')
      ->execute([
        ':id' => $import_id,
        ':f' => $nombre,
        ':m' => $mes_descarga,
        ':a' => $anio_descarga,
        ':t' => $insertados + $saltados,
        ':i' => $insertados,
        ':s' => $saltados,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
      ]);
} catch (Throwable $e) {}

/* ============================================================
   11. Respuesta final
   ============================================================ */
echo json_encode([
  'ok' => true,
  'insertados' => $insertados,
  'saltados' => $saltados,
  'total' => $insertados + $saltados,
  'errores' => $errores
], JSON_UNESCAPED_UNICODE);
