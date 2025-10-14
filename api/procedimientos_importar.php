<?php
// API/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

/* 🔹 Modo depuración */
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
   Lógica principal (dentro de try/catch global)
   ───────────────────────────────────────────────────────── */
try {

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
     POST: anular / importar
     ───────────────────────────────────────────────────────── */
  $accion = $_POST['accion'] ?? 'importar';
  if ($accion === 'anular') {
    $import_id = trim((string)($_POST['import_id'] ?? ''));
    if ($import_id === '') {
      $row = $pdo->query('SELECT import_id FROM public.import_log ORDER BY started_at DESC LIMIT 1')->fetch();
      if (!$row) { echo json_encode(['ok'=>false,'error'=>'No hay importaciones previas para anular']); exit; }
      $import_id = $row['import_id'];
    }
    $pdo->beginTransaction();
    try{
      $del = $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id = :id');
      $del->execute([':id'=>$import_id]);
      $deleted = $del->rowCount();
      $pdo->prepare('DELETE FROM public.import_log WHERE import_id = :id')->execute([':id'=>$import_id]);
      $pdo->commit();
      echo json_encode(['ok'=>true,'anulado'=>$import_id,'eliminadas'=>$deleted], JSON_UNESCAPED_UNICODE);
    }catch(Throwable $e){
      $pdo->rollBack();
      echo json_encode(['ok'=>false,'error'=>'No se pudo anular: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
  }

  /* ─────────────────────────────────────────────────────────
     POST: importar
     ───────────────────────────────────────────────────────── */
  if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 🔹 Aquí iría todo tu bloque de importación original, tal como ya lo tenías,
  // incluyendo la lectura del CSV, creación de fingerprint, inserts, commits, etc.

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'Error inesperado: '.$e->getMessage(),
    'trace' => $e->getTraceAsString()
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
?>
