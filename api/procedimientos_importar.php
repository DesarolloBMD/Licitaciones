<?php
declare(strict_types=1);
@ini_set('memory_limit', '512M');
@set_time_limit(0);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/* --- Leer encabezados del CSV con detección de delimitador --- */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']);
  exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$fh  = fopen($tmp, 'r');
if (!$fh) { echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo']); exit; }

$firstLine = fgets($fh);
rewind($fh);

/* 🔍 Detectar delimitador automáticamente */
$delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
arsort($delims);
$delimiter = array_key_first($delims);

/* Leer encabezados correctamente */
$header = fgetcsv($fh, 0, $delimiter);
fclose($fh);

/* Limpieza de encabezados */
$header = array_map(function($s){
  $s = trim($s ?? '');
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // BOM
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}, $header);

/* Encabezados esperados */
$canon = [
  'Mes de Descarga','Año de reporte','CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO','MODALIDAD_PROCEDIMIENTO','fecha_rev',
  'CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR','PERFIL_PROV','CEDULA_REPRESENTANTE','REPRESENTANTE','OBJETO_GASTO',
  'MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD','FECHA_ADJUD_FIRME',
  'FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO','CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO',
  'MONEDA_PRECIO_EST','FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];

/* Comparación */
$faltan = array_diff($canon, $header);
$sobran = array_diff($header, $canon);

echo json_encode([
  'ok' => empty($faltan),
  'delimiter_detectado' => $delimiter,
  'faltan' => array_values($faltan),
  'sobran' => array_values($sobran),
  'en_archivo' => $header
], JSON_UNESCAPED_UNICODE);
