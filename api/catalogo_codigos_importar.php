<?php
// api/catalogo_codigos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ================= UI mínima (GET) ================= */
if ($method === 'GET') {
  header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html>
<html lang="es"><head>
  <meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Importar Catálogo de Códigos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head><body class="p-3">
  <div class="container">
    <h1 class="h5 mb-3">Importar Catálogo de Códigos</h1>
    <form id="frm" class="vstack gap-2" enctype="multipart/form-data">
      <input class="form-control" type="file" name="archivo" accept=".csv,.tsv,.txt" required>
      <button class="btn btn-success mt-2">Subir e importar</button>
    </form>
    <pre id="out" class="mt-3 small"></pre>
  </div>
  <script>
    document.getElementById('frm').addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd = new FormData(e.target);
      const r = await fetch(location.pathname,{method:'POST',body:fd});
      const t = await r.text();
      try{ document.getElementById('out').textContent = JSON.stringify(JSON.parse(t), null, 2); }
      catch{ document.getElementById('out').textContent = t; }
    });
  </script>
</body></html>
<?php exit; }

/* ============== CORS + salida JSON SEGURA ============== */
if ($method === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ini_set('display_errors','0');
ob_start();
set_error_handler(function($sev,$msg,$file,$line){ throw new ErrorException($msg,0,$sev,$file,$line); });
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])){
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true);
    $buf = ob_get_clean();
    echo json_encode(['ok'=>false,'error'=>'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line'],'debug'=>$buf?:null], JSON_UNESCAPED_UNICODE);
  }
});

if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE); exit; }
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE); exit;
}

/* ============== DB ============== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port']??5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true,
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
}

/* ============== Helpers de normalización ============== */
function to_utf8($s): string {
  $s = (string)($s ?? '');
  if ($s === '') return '';
  if (function_exists('mb_detect_encoding') && mb_detect_encoding($s,'UTF-8',true)) return $s;
  foreach (['Windows-1252','ISO-8859-1','ISO-8859-15'] as $enc) {
    $conv = @mb_convert_encoding($s,'UTF-8',$enc);
    if ($conv !== false && $conv !== '') return $conv;
  }
  return $s;
}
function u_upper(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s,'UTF-8') : strtoupper($s); }
function norm_header($s): string {
  $s = to_utf8($s);
  $s = preg_replace('/^\xEF\xBB\xBF/','',$s);
  $s = str_replace(["\xC2\xA0","\xE2\x80\x8B","\xE2\x80\x8C","\xE2\x80\x8D"], ' ', $s);
  $s = trim($s);
  $r = preg_replace('/\s+/u',' ',$s);
  if ($r===null) $r = preg_replace('/\s+/',' ',$s);
  return $r ?? '';
}
function strip_accents($s): string {
  $s = to_utf8($s);
  $from='ÁáÉéÍíÓóÚúÜüÑñ'; $to='AaEeIiOoUuUuNn'; return strtr($s,$from,$to);
}
function label_key($s): string {
  $s = strip_accents(norm_header($s)); $s = u_upper($s);
  $r = preg_replace('/[^A-Z0-9]+/u',' ',$s); if ($r===null) $r = preg_replace('/[^A-Z0-9]+/',' ',$s);
  $s = $r ?? ''; $r = preg_replace('/\s+/u',' ',trim($s)); if ($r===null) $r = preg_replace('/\s+/',' ',trim($s));
  return $r ?? '';
}
function norm_code($s): ?int {
  // solo dígitos; si queda vacío => NULL
  $digits = preg_replace('/\D+/','', (string)($s ?? ''));
  if ($digits === '' || !ctype_digit($digits)) return null;
  // cabe en BIGINT
  return (int)$digits;
}

/* ============== Definición de columnas y sinónimos ============== */
$canon = [
  'Tipo de Clasificación',
  'Codigo de Clasificación',
  'Descripción de Clasificación'
];

$ph = [
  'Tipo de Clasificación'       => 'c_tipo',
  'Codigo de Clasificación'     => 'c_codigo',
  'Descripción de Clasificación' => 'c_desc'
];

$syn = [
  // Tipo
  'TIPO DE CLASIFICACION' => 'Tipo de Clasificación',
  'TIPO CLASIFICACION'    => 'Tipo de Clasificación',
  'TIPO'                  => 'Tipo de Clasificación',
  'CLASIFICACION TIPO'    => 'Tipo de Clasificación',

  // Código
  'CODIGO DE CLASIFICACION' => 'Codigo de Clasificación',
  'CODIGO CLASIFICACION'    => 'Codigo de Clasificación',
  'CODIGO'                  => 'Codigo de Clasificación',
  'CÓDIGO'                  => 'Codigo de Clasificación',

  // Descripción
  'DESCRIPCION DE CLASIFICACION' => 'Descripción de Clasificación',
  'DESCRIPCION CLASIFICACION'    => 'Descripción de Clasificación',
  'DESCRIPCION'                  => 'Descripción de Clasificación',
  'DESCRIPCIÓN'                  => 'Descripción de Clasificación'
];

/* ============== Abrir archivo y detectar delimitador ============== */
$tmp = $_FILES['archivo']['tmp_name'];
$fh  = fopen($tmp, 'r');
if (!$fh) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo subido'], JSON_UNESCAPED_UNICODE); exit; }

$first = fgets($fh);
if ($first === false) { fclose($fh); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo vacío'], JSON_UNESCAPED_UNICODE); exit; }
rewind($fh);
$counts = [","=>substr_count($first,","), ";"=>substr_count($first,";"), "\t"=>substr_count($first,"\t"), "|"=>substr_count($first,"|")];
arsort($counts); $delim = array_key_first($counts) ?: ","; if (($counts[$delim]??0)===0) $delim = ",";

/* ============== Leer encabezados y mapear ============== */
$header = fgetcsv($fh, 0, $delim);
if ($header === false || $header === null) { fclose($fh); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo leer la fila de encabezados'], JSON_UNESCAPED_UNICODE); exit; }

$map = []; $present = array_fill_keys($canon, false);
for ($i=0; $i<count($header); $i++){
  $h = $header[$i]; if ($h === null || $h === '') continue;
  $key = label_key($h);
  $canonName = $syn[$key] ?? (in_array($h, $canon, true) ? $h : null);
  if ($canonName === null) {
    // intenta por clave exacta normalizada
    foreach ($canon as $c) { if (label_key($c) === $key) { $canonName = $c; break; } }
  }
  if ($canonName !== null) { $map[$i] = $canonName; $present[$canonName] = true; }
}
$missing = [];
foreach ($present as $c=>$ok){ if(!$ok) $missing[]=$c; }
if ($missing) { fclose($fh); echo json_encode(['ok'=>false,'error'=>'Faltan columnas requeridas','faltan'=>$missing,'en_archivo'=>$header], JSON_UNESCAPED_UNICODE); exit; }

/* ============== Prepare UPSERT ============== */
$colsSql  = implode('","', $canon);
$placeSql = implode(', ', array_map(fn($c)=>':'.$ph[$c], $canon));
$setSql   = '"Descripción de Clasificación" = EXCLUDED."Descripción de Clasificación", updated_at = now()';

$sql = 'INSERT INTO public."Catalogo Codigos" ("'.$colsSql.'") VALUES ('.$placeSql.')
        ON CONFLICT ("Tipo de Clasificación","Codigo de Clasificación")
        DO UPDATE SET '.$setSql;

$stmt = $pdo->prepare($sql);

/* ============== Importar ============== */
$insertados=0; $actualizados=0; $saltados=0; $errores=[]; $linea=1;

$pdo->beginTransaction();
while (($row = fgetcsv($fh, 0, $delim)) !== false) {
  $linea++;

  // fila vacía
  $empty = true; foreach ($row as $c){ if (trim((string)$c) !== '') { $empty=false; break; } }
  if ($empty) continue;

  $vals = [];
  foreach ($map as $idx=>$cn){ $vals[$cn] = $row[$idx] ?? null; }

  $tipo = trim((string)($vals['Tipo de Clasificación'] ?? ''));
  $codigo = norm_code($vals['Codigo de Clasificación'] ?? null);
  $desc = trim((string)($vals['Descripción de Clasificación'] ?? ''));

  if ($tipo === '' || $codigo === null){
    $saltados++;
    if (count($errores)<300) $errores[] = "Línea $linea: tipo/código inválidos";
    continue;
  }

  $params = [
    ':'.$ph['Tipo de Clasificación']        => $tipo,
    ':'.$ph['Codigo de Clasificación']      => $codigo,
    ':'.$ph['Descripción de Clasificación'] => ($desc === '' ? null : $desc),
  ];

  $sp = 'sp_'.$linea; $pdo->exec("SAVEPOINT $sp");
  try{
    $stmt->execute($params);
    // rowCount(): 1 si insertó; 0 si UPDATE por conflicto (en PostgreSQL el UPDATE cuenta como 1 también).
    // Diferenciamos preguntando si cambió algo: asumimos que si vino conflicto, cuenta como actualizado.
    if ($stmt->rowCount() === 1) { $insertados++; } else { $actualizados++; }
  }catch(Throwable $e){
    $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
    $saltados++;
    if (count($errores)<300) $errores[] = "Línea $linea: ".$e->getMessage();
  }
}
$pdo->commit();
fclose($fh);

/* ============== Respuesta ============== */
$debug = ob_get_contents(); ob_end_clean();
echo json_encode([
  'ok'=>true,
  'insertados'=>$insertados,
  'actualizados'=>$actualizados,
  'saltados'=>$saltados,
  'errores'=>$errores,
  'debug'=>$debug ? trim(strip_tags($debug)) : null
], JSON_UNESCAPED_UNICODE);
