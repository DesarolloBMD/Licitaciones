<?php
// API/procedimientos_importar.php
declare(strict_types=1);

// ===== Cabeceras (JSON + CORS) =====
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Solo POST + archivo
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Si el cliente cancela, queremos que PHP PARE (no seguir en background)
ignore_user_abort(false);

// ===== Conexión a PostgreSQL (Render) =====
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $parts = parse_url($DATABASE_URL);
  if (!$parts || !isset($parts['host'], $parts['user'], $parts['pass'], $parts['path'])) {
    throw new RuntimeException('DATABASE_URL inválida');
  }
  $host   = $parts['host'];
  $port   = isset($parts['port']) ? (int)$parts['port'] : 5432;
  $user   = $parts['user'];
  $pass   = $parts['pass'];
  $dbname = ltrim($parts['path'], '/');

  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $host, $port, $dbname);
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Fechas estilo 26/2/2025
  $pdo->exec("SET datestyle TO 'ISO, DMY'");

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== Helpers =====
function norm_header(string $s): string {
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // quitar BOM
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}
function norm_key(string $s): string { return mb_strtoupper(norm_header($s), 'UTF-8'); }

function norm_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '' || strtoupper($s) === 'NULL') return null;
  $s2 = str_replace('/', '-', $s);
  $fmts = ['d-m-Y','d-m-y','Y-m-d','d-m-Y H:i:s','d-m-Y H:i'];
  foreach ($fmts as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $s2);
    if ($dt && $dt->format($fmt) === $s2) return $dt->format('Y-m-d');
  }
  // Serial excel
  if (is_numeric($s)) {
    $v = (int)$s;
    if ($v > 25569 && $v < 60000) return gmdate('Y-m-d', ($v - 25569) * 86400);
  }
  return null;
}
function norm_num(?string $s): ?float {
  $s = trim((string)$s);
  if ($s === '' || strtoupper($s) === 'NULL') return null;
  $s = str_replace(['₡','$','CRC','USD',' '], '', $s);
  if (substr_count($s, ',') === 1 && substr_count($s, '.') === 0) {
    $s = str_replace(',', '.', $s); // coma decimal
  } else {
    $s = str_replace(',', '', $s);  // miles
  }
  return is_numeric($s) ? (float)$s : null;
}

/**
 * Convierte "8,11E+15" / "8.11e+15" a "8110000000000000".
 * Devuelve null si no hay dígitos.
 */
function norm_bigint_text(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '' || strtoupper($s) === 'NULL') return null;
  $s = str_replace(' ', '', $s);
  $s = str_replace(',', '.', $s); // decimal como punto

  if (preg_match('/^(\d+(?:\.\d+)?)e\+?(-?\d+)$/i', $s, $m)) {
    $mant = $m[1];
    $exp  = (int)$m[2];

    $int = $mant;
    $frac = '';
    if (strpos($mant, '.') !== false) {
      [$int, $frac] = explode('.', $mant, 2);
    }
    // mover punto a la derecha exp posiciones
    if ($exp >= strlen($frac)) {
      $digits = $int . $frac . str_repeat('0', $exp - strlen($frac));
    } else {
      $digits = $int . substr($frac, 0, $exp); // recorte si exp < len(frac)
    }
    $digits = ltrim($digits, '0');
    return $digits === '' ? '0' : $digits;
  }

  // Si no es notación científica: quitar no-dígitos y dejar solo números
  $digits = preg_replace('/\D+/', '', $s);
  return $digits === '' ? null : $digits;
}

// ===== Definición de columnas (canon) =====
$canon = [
  'Mes de Descarga',
  'CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO',
  'MODALIDAD_PROCEDIMIENTO','fecha_rev','CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR',
  'PERFIL_PROV','CEDULA_REPRESENTANTE','REPRESENTANTE','OBJETO_GASTO',
  'MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD',
  'FECHA_ADJUD_FIRME','FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO',
  'CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO','MONEDA_PRECIO_EST',
  'FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];

// Sinónimos/correcciones típicas
$syn = [
  'MES DE DESCARGA' => 'Mes de Descarga', // cubre "Mes de descarga"
];

// Mapa canon → placeholder
$ph = [
  'Mes de Descarga'        => 'c_mes_descarga',
  'CEDULA'                 => 'c_cedula',
  'INSTITUCION'            => 'c_institucion',
  'ANO'                    => 'c_ano',
  'NUMERO_PROCEDIMIENTO'   => 'c_numero_procedimiento',
  'DESCR_PROCEDIMIENTO'    => 'c_descr_procedimiento',
  'LINEA'                  => 'c_linea',
  'NRO_SICOP'              => 'c_nro_sicop',
  'TIPO_PROCEDIMIENTO'     => 'c_tipo_procedimiento',
  'MODALIDAD_PROCEDIMIENTO'=> 'c_modalidad_procedimiento',
  'fecha_rev'              => 'c_fecha_rev',
  'CEDULA_PROVEEDOR'       => 'c_cedula_proveedor',
  'NOMBRE_PROVEEDOR'       => 'c_nombre_proveedor',
  'PERFIL_PROV'            => 'c_perfil_prov',
  'CEDULA_REPRESENTANTE'   => 'c_cedula_representante',
  'REPRESENTANTE'          => 'c_representante',
  'OBJETO_GASTO'           => 'c_objeto_gasto',
  'MONEDA_ADJUDICADA'      => 'c_moneda_adjudicada',
  'MONTO_ADJU_LINEA'       => 'c_monto_adju_linea',
  'MONTO_ADJU_LINEA_CRC'   => 'c_monto_adju_linea_crc',
  'MONTO_ADJU_LINEA_USD'   => 'c_monto_adju_linea_usd',
  'FECHA_ADJUD_FIRME'      => 'c_fecha_adjud_firme',
  'FECHA_SOL_CONTRA'       => 'c_fecha_sol_contra',
  'PROD_ID'                => 'c_prod_id',
  'DESCR_BIEN_SERVICIO'    => 'c_descr_bien_servicio',
  'CANTIDAD'               => 'c_cantidad',
  'UNIDAD_MEDIDA'          => 'c_unidad_medida',
  'MONTO_UNITARIO'         => 'c_monto_unitario',
  'MONEDA_PRECIO_EST'      => 'c_moneda_precio_est',
  'FECHA_SOL_CONTRA_CL'    => 'c_fecha_sol_contra_cl',
  'PROD_ID_CL'             => 'c_prod_id_cl',
];

// ===== Abrir el archivo y detectar delimitador =====
$tmp = $_FILES['archivo']['tmp_name'];
$fh  = fopen($tmp, 'r');
if (!$fh) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo subido'], JSON_UNESCAPED_UNICODE);
  exit;
}

// primera línea cruda para detectar separador
$firstLineRaw = fgets($fh);
if ($firstLineRaw === false) {
  fclose($fh);
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Archivo vacío'], JSON_UNESCAPED_UNICODE);
  exit;
}
rewind($fh);

// detectar delimitador (tab; ; ,)
$counts = [
  "\t" => substr_count($firstLineRaw, "\t"),
  ";"  => substr_count($firstLineRaw, ";"),
  ","  => substr_count($firstLineRaw, ","),
];
arsort($counts);
$delimiter = array_key_first($counts);
if (($counts[$delimiter] ?? 0) === 0) $delimiter = ",";

// ===== Parseo rápido del CSV =====
function parse_line(string $line, string $delim): array {
  return str_getcsv($line, $delim);
}

// leer encabezados
$header = parse_line(fgets($fh), $delimiter);
$header = array_map('norm_header', $header);

// construir mapping: indice_archivo -> nombre_canonico
$map = [];
$canon_set = array_fill_keys($canon, false);
$en_archivo = [];

for ($i=0; $i<count($header); $i++) {
  $h  = $header[$i];
  if ($h === '') continue;
  $hk = norm_key($h);

  $hCanon = null;
  if (isset($syn[$hk])) {
    $hCanon = $syn[$hk];
  } else {
    foreach ($canon as $c) {
      if (norm_key($c) === $hk) { $hCanon = $c; break; }
    }
  }
  if ($hCanon !== null) {
    $map[$i] = $hCanon;
    $canon_set[$hCanon] = true;
  }
  $en_archivo[] = $h;
}

// validar que estén TODOS los requeridos
$faltan = [];
foreach ($canon_set as $c=>$ok) if (!$ok) $faltan[] = $c;
if ($faltan) {
  fclose($fh);
  echo json_encode([
    'ok'=>false,
    'error'=>'Encabezados no coinciden con la tabla',
    'faltan'=>$faltan,
    'en_archivo'=>$en_archivo
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== Preparar SQL =====
$colsSql   = implode('","', $canon);
$placeSql  = implode(', ', array_map(fn($c)=>':'.$ph[$c], $canon));
$sql = 'INSERT INTO public."Procedimientos Adjudicados" ("'.$colsSql.'") VALUES ('.$placeSql.')';
$stmt = $pdo->prepare($sql);

// ===== Bucle de inserción con SAVEPOINT por fila =====
$insertados = 0; $saltados = 0; $errores = [];
$linea = 1; // incluyendo encabezados
$pdo->beginTransaction();

while (($line = fgets($fh)) !== false) {
  $linea++;

  // si el usuario canceló la subida desde el front, detenemos
  if (connection_aborted()) { break; }

  $row = parse_line($line, $delimiter);
  if (count($row) === 1 && trim($row[0]) === '') { continue; } // línea vacía

  // construir arreglo canon -> valor bruto
  $vals = [];
  foreach ($map as $idx=>$canonName) {
    $vals[$canonName] = $row[$idx] ?? null;
  }

  // normalizar por tipo (fechas/números/ids)
  $params = [];
  foreach ($canon as $cname) {
    $raw = $vals[$cname] ?? null;

    switch ($cname) {
      // FECHAS
      case 'fecha_rev':
      case 'FECHA_ADJUD_FIRME':
      case 'FECHA_SOL_CONTRA':
      case 'FECHA_SOL_CONTRA_CL':
        $params[':'.$ph[$cname]] = norm_date($raw);
        break;

      // NUMÉRICOS
      case 'MONTO_ADJU_LINEA':
      case 'MONTO_ADJU_LINEA_CRC':
      case 'MONTO_ADJU_LINEA_USD':
      case 'CANTIDAD':
      case 'MONTO_UNITARIO':
        $params[':'.$ph[$cname]] = norm_num($raw);
        break;

      // IDS GRANDES (como texto sin notación científica)
      case 'PROD_ID':
      case 'PROD_ID_CL':
        $params[':'.$ph[$cname]] = norm_bigint_text($raw);
        break;

      default:
        $val = trim((string)$raw);
        $params[':'.$ph[$cname]] = ($val === '') ? null : $val;
        break;
    }
  }

  // SAVEPOINT por fila
  $sp = 'sp_'.$linea;
  $pdo->exec("SAVEPOINT $sp");
  try {
    $stmt->execute($params);
    $insertados++;
  } catch (Throwable $e) {
    $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
    $saltados++;
    if (count($errores) < 1000) {
      $errores[] = "Línea $linea: ".$e->getMessage();
    }
  }
}

$pdo->commit();
fclose($fh);

// ===== Respuesta =====
echo json_encode([
  'ok'        => true,
  'insertados'=> $insertados,
  'saltados'  => $saltados,
  'errores'   => $errores,
  'advertencias'=>[],
], JSON_UNESCAPED_UNICODE);
