<?php
// api/procedimientos_importar.php
declare(strict_types=1);

// ---- Cabeceras
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}

function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- Conexión Postgres (Render)
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  // CAMBIA por tu cadena externa de Render si quieres dejarlo “hardcodeado”
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $u = parse_url($DATABASE_URL);
  if (!$u || !isset($u['host'],$u['user'],$u['pass'],$u['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $u['host'], $u['port'] ?? 5432, ltrim($u['path'],'/'));
  $pdo = new PDO($dsn, $u['user'], $u['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  jexit(false, ['error'=>'Error de conexión: '.$e->getMessage()]);
}

// ---- Validar archivo
if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  jexit(false, ['error'=>'No se recibió el archivo (o vino con error)']);
}
$path = $_FILES['archivo']['tmp_name'];
$size = (int)($_FILES['archivo']['size'] ?? 0);
if ($size <= 0) jexit(false, ['error'=>'Archivo vacío']);

// ---- Config
$tieneHeader = isset($_POST['tiene_header']) && $_POST['tiene_header'] === '1';

// Los nombres oficiales, tal cual están en la tabla (con mayúsculas y espacios)
$COLUMNS = [
  "Mes de Descarga","CEDULA","INSTITUCION","ANO","NUMERO_PROCEDIMIENTO",
  "DESCR_PROCEDIMIENTO","LINEA","NRO_SICOP","TIPO_PROCEDIMIENTO",
  "MODALIDAD_PROCEDIMIENTO","fecha_rev","CEDULA_PROVEEDOR","NOMBRE_PROVEEDOR",
  "PERFIL_PROV","CEDULA_REPRESENTANTE","REPRESENTANTE","OBJETO_GASTO",
  "MONEDA_ADJUDICADA","MONTO_ADJU_LINEA","MONTO_ADJU_LINEA_CRC","MONTO_ADJU_LINEA_USD",
  "FECHA_ADJUD_FIRME","FECHA_SOL_CONTRA","PROD_ID","DESCR_BIEN_SERVICIO",
  "CANTIDAD","UNIDAD_MEDIDA","MONTO_UNITARIO","MONEDA_PRECIO_EST",
  "FECHA_SOL_CONTRA_CL","PROD_ID_CL"
];

// ---- Detección de separador
$fh = fopen($path, 'rb');
if ($fh === false) jexit(false, ['error'=>'No se pudo abrir el archivo']);

$firstLine = fgets($fh);
if ($firstLine === false) jexit(false, ['error'=>'No se pudo leer el archivo']);

$sepCandidates = [",",";","\t"];
$sep = ",";
$maxParts = 0;
foreach ($sepCandidates as $cand){
  $parts = str_getcsv($firstLine, $cand);
  if (count($parts) > $maxParts){ $maxParts = count($parts); $sep = $cand; }
}
// Retrocede al inicio para recorrer todo
rewind($fh);

// ---- Si hay encabezado, léelo y mapea columnas
$map = []; // índiceCSV -> nombreColumna
if ($tieneHeader) {
  $header = str_getcsv(rtrim(fgets($fh)), $sep);
  // Normalización simple (quitar BOM, trim)
  foreach ($header as &$h) {
    $h = trim(preg_replace('/^\xEF\xBB\xBF/', '', $h));
  }
  unset($h);

  // Verificar presencia (permitimos orden arbitrario)
  $faltan = [];
  foreach ($COLUMNS as $col) {
    $idx = array_search($col, $header, true);
    if ($idx === false) $faltan[] = $col; else $map[$idx] = $col;
  }
  if ($faltan) {
    fclose($fh);
    jexit(false, ['error'=>'Encabezados no coinciden con la tabla', 'detalle'=>['faltan'=>$faltan,'en_archivo'=>$header]]);
  }
} else {
  // Sin encabezado: asumimos el mismo orden exacto que $COLUMNS
  foreach ($COLUMNS as $i=>$col) $map[$i] = $col;
}

// ---- Preparar INSERT
$colsSql = '"' . implode('","', $COLUMNS) . '"';
$placeholders = implode(',', array_fill(0, count($COLUMNS), '?'));
$sql = 'INSERT INTO public."Procedimientos Adjudicados" ('.$colsSql.') VALUES ('.$placeholders.')';
$stmt = $pdo->prepare($sql);

// Helpers
function toNullEmpty(?string $s): ?string {
  if ($s === null) return null;
  $s = trim($s);
  return $s === '' ? null : $s;
}
function toNumber(?string $s): ?string {
  // Acepta 1.234,56 o 1234.56 → devuelve con punto decimal
  $s = toNullEmpty($s);
  if ($s === null) return null;
  // si tiene coma decimal y puntos de mil → los normaliza
  $s = str_replace(['.',' '], '', $s); // quita miles
  $s = str_replace(',', '.', $s);      // coma → punto
  return is_numeric($s) ? $s : null;
}

// ---- Transacción e importación
$insertados = 0; $saltados = 0; $errores = 0; $warnings = [];
$linea = 0;

try {
  $pdo->beginTransaction();

  while (($row = fgetcsv($fh, 0, $sep)) !== false) {
    $linea++;

    // Si venía con encabezado, la primera que leímos ya fue header
    if ($tieneHeader && $linea === 1) continue;

    // Arma el registro en el orden de $COLUMNS
    $vals = [];
    foreach ($COLUMNS as $c) $vals[$c] = null;

    foreach ($map as $idx=>$col) {
      $vals[$col] = $row[$idx] ?? null;
    }

    // Normalizaciones típicas (numéricos y vacíos)
    $numericCols = [
      "MONTO_ADJU_LINEA","MONTO_ADJU_LINEA_CRC","MONTO_ADJU_LINEA_USD",
      "CANTIDAD","MONTO_UNITARIO"
    ];
    foreach ($numericCols as $nc){
      if (array_key_exists($nc, $vals)) $vals[$nc] = toNumber($vals[$nc]);
    }
    foreach ($vals as $k=>$v) $vals[$k] = toNullEmpty($v);

    try {
      $stmt->execute(array_values($vals));
      $insertados++;
    } catch (Throwable $e) {
      $errores++;
      $warnings[] = "Línea ".($tieneHeader ? $linea-1 : $linea).": ".$e->getMessage();
      // Si prefieres abortar todo ante el primer error:
      // throw $e;
      // Por ahora, continuamos y contamos como saltado
      $saltados++;
    }
  }

  $pdo->commit();
  fclose($fh);

  jexit(true, [
    'insertados'=>$insertados,
    'saltados'=>$saltados,
    'errores'=>$errores,
    'warnings'=>$warnings
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (is_resource($fh)) fclose($fh);
  jexit(false, ['error'=>'Fallo al importar: '.$e->getMessage()]);
}
