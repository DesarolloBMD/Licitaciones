<?php
// API/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

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

ini_set('display_errors', '0');
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

/* ─────────────────────────────────────────────────────────
   Esquema auxiliar
   ───────────────────────────────────────────────────────── */
function ensure_schema(PDO $pdo): void {
  $pdo->exec('ALTER TABLE public."Procedimientos Adjudicados" ADD COLUMN IF NOT EXISTS fingerprint text');
  $pdo->exec('ALTER TABLE public."Procedimientos Adjudicados" ADD COLUMN IF NOT EXISTS import_id  text');
  $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS "uniq_procadj_fingerprint" ON public."Procedimientos Adjudicados"(fingerprint)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS public.import_log (
    import_id   text PRIMARY KEY,
    filename    text,
    mode        text,
    inserted    integer DEFAULT 0,
    skipped     integer DEFAULT 0,
    mes_ano     text[],
    started_at  timestamptz DEFAULT now(),
    finished_at timestamptz
  )');
}
ensure_schema($pdo);

/* ─────────────────────────────────────────────────────────
   Helpers
   ───────────────────────────────────────────────────────── */
function to_utf8($s): string {
  $s = (string)($s ?? '');
  if ($s === '') return '';
  if (function_exists('mb_detect_encoding') && mb_detect_encoding($s,'UTF-8',true)) return $s;
  foreach (['Windows-1252','ISO-8859-1','ISO-8859-15'] as $enc) {
    $conv = @mb_convert_encoding($s, 'UTF-8', $enc);
    if ($conv !== false && $conv !== '') return $conv;
  }
  return $s;
}
function u_upper(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s,'UTF-8') : strtoupper($s); }
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
function strip_accents($s): string { $s = to_utf8($s); return strtr($s,'ÁáÉéÍíÓóÚúÜüÑñ','AaEeIiOoUuUuNn'); }
function label_key($s): string {
  $s = strip_accents(norm_header($s));
  $s = u_upper($s);
  $tmp = preg_replace('/[^A-Z0-9]+/u', ' ', $s); if ($tmp===null) $tmp = preg_replace('/[^A-Z0-9]+/',' ',$s); $s=$tmp??'';
  $tmp = preg_replace('/\s+/u', ' ', trim($s));  if ($tmp===null) $tmp = preg_replace('/\s+/',' ',trim($s)); $s=$tmp??'';
  $tokens = array_values(array_filter(explode(' ', $s), fn($t)=>$t!=='' && !in_array($t,['DE','DEL','EL','LA','LOS','LAS','OF','THE'],true)));
  return implode(' ', $tokens);
}
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
  $rows = $pdo->query('SELECT import_id, filename, mode, inserted, skipped, started_at, finished_at
                       FROM public.import_log
                       ORDER BY started_at DESC
                       LIMIT 50')->fetchAll();
  echo json_encode(['ok'=>true,'historial'=>$rows], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ─────────────────────────────────────────────────────────
   POST: importar (modo fijo: append)
   ───────────────────────────────────────────────────────── */
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE);
  exit;
}

$canon = [
  'Mes de Descarga','Año de reporte','CEDULA','INSTITUCION','ANO','NUMERO_PROCEDIMIENTO',
  'DESCR_PROCEDIMIENTO','LINEA','NRO_SICOP','TIPO_PROCEDIMIENTO','MODALIDAD_PROCEDIMIENTO','fecha_rev',
  'CEDULA_PROVEEDOR','NOMBRE_PROVEEDOR','PERFIL_PROV','CEDULA_REPRESENTANTE','REPRESENTANTE','OBJETO_GASTO',
  'MONEDA_ADJUDICADA','MONTO_ADJU_LINEA','MONTO_ADJU_LINEA_CRC','MONTO_ADJU_LINEA_USD','FECHA_ADJUD_FIRME',
  'FECHA_SOL_CONTRA','PROD_ID','DESCR_BIEN_SERVICIO','CANTIDAD','UNIDAD_MEDIDA','MONTO_UNITARIO',
  'MONEDA_PRECIO_EST','FECHA_SOL_CONTRA_CL','PROD_ID_CL'
];
$optional = ['Año de reporte'=>true];
$ph = [
  'Mes de Descarga'=>'c_mes_descarga','Año de reporte'=>'c_ano_reporte','CEDULA'=>'c_cedula','INSTITUCION'=>'c_institucion','ANO'=>'c_ano',
  'NUMERO_PROCEDIMIENTO'=>'c_numero_procedimiento','DESCR_PROCEDIMIENTO'=>'c_descr_procedimiento','LINEA'=>'c_linea','NRO_SICOP'=>'c_nro_sicop',
  'TIPO_PROCEDIMIENTO'=>'c_tipo_procedimiento','MODALIDAD_PROCEDIMIENTO'=>'c_modalidad_procedimiento','fecha_rev'=>'c_fecha_rev',
  'CEDULA_PROVEEDOR'=>'c_cedula_proveedor','NOMBRE_PROVEEDOR'=>'c_nombre_proveedor','PERFIL_PROV'=>'c_perfil_prov',
  'CEDULA_REPRESENTANTE'=>'c_cedula_representante','REPRESENTANTE'=>'c_representante','OBJETO_GASTO'=>'c_objeto_gasto',
  'MONEDA_ADJUDICADA'=>'c_moneda_adjudicada','MONTO_ADJU_LINEA'=>'c_monto_adju_linea','MONTO_ADJU_LINEA_CRC'=>'c_monto_adju_linea_crc',
  'MONTO_ADJU_LINEA_USD'=>'c_monto_adju_linea_usd','FECHA_ADJUD_FIRME'=>'c_fecha_adjud_firme','FECHA_SOL_CONTRA'=>'c_fecha_sol_contra',
  'PROD_ID'=>'c_prod_id','DESCR_BIEN_SERVICIO'=>'c_descr_bien_servicio','CANTIDAD'=>'c_cantidad','UNIDAD_MEDIDA'=>'c_unidad_medida',
  'MONTO_UNITARIO'=>'c_monto_unitario','MONEDA_PRECIO_EST'=>'c_moneda_precio_est','FECHA_SOL_CONTRA_CL'=>'c_fecha_sol_contra_cl','PROD_ID_CL'=>'c_prod_id_cl'
];

/* INSERT con fingerprint+import_id */
$colsSql  = implode('","', $canon);
$placeSql = implode(', ', array_map(fn($c)=>':'.$ph[$c], $canon));
$sql = 'INSERT INTO public."Procedimientos Adjudicados"
        ("'.$colsSql.'","fingerprint","import_id")
        VALUES ('.$placeSql.', :fp, :import_id)
        ON CONFLICT (fingerprint) DO NOTHING';
$stmt = $pdo->prepare($sql);

$import_id = uuidv4();
$insertados=0; $saltados=0; $errores=[]; $linea=1; $seen=[];

$pdo->beginTransaction();

while (($row = fgetcsv(fopen($_FILES['archivo']['tmp_name'],'r'), 0, ',')) !== false) {
  $linea++;
  $params=[];
  foreach($canon as $cname){
    $raw=$row[array_search($cname,$canon)]??null;
    switch($cname){
      case 'fecha_rev':
      case 'FECHA_ADJUD_FIRME':
      case 'FECHA_SOL_CONTRA':
      case 'FECHA_SOL_CONTRA_CL': $params[':'.$ph[$cname]]=norm_date($raw); break;
      case 'MONTO_ADJU_LINEA':
      case 'MONTO_ADJU_LINEA_CRC':
      case 'MONTO_ADJU_LINEA_USD':
      case 'CANTIDAD':
      case 'MONTO_UNITARIO':
      case 'NRO_SICOP': $params[':'.$ph[$cname]]=norm_num($raw); break;
      case 'ANO': $params[':'.$ph[$cname]]=is_numeric($raw)?(int)$raw:null; break;
      case 'PROD_ID':
      case 'PROD_ID_CL': $params[':'.$ph[$cname]]=norm_num($raw); break;
      default: $val=trim((string)$raw); $params[':'.$ph[$cname]]=$val===''?null:$val; break;
    }
  }
  $concat=[]; foreach($canon as $cname){ $v=$params[':'.$ph[$cname]]??''; $concat[]=is_null($v)?'NULL':(string)$v; }
  $fp = md5(implode('|',$concat));
  if(isset($seen[$fp])){ $saltados++; continue; }
  $seen[$fp]=true;
  $params[':fp']=$fp; $params[':import_id']=$import_id;
  try{
    $stmt->execute($params);
    if($stmt->rowCount()===1) $insertados++; else $saltados++;
  }catch(Throwable $e){ $saltados++; if(count($errores)<1000)$errores[]="Línea $linea: ".$e->getMessage(); }
}

$pdo->commit();
echo json_encode(['ok'=>true,'insertados'=>$insertados,'saltados'=>$saltados,'errores'=>$errores], JSON_UNESCAPED_UNICODE);
