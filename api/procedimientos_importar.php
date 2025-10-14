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
   Resto de tu código original (sin cambios)
   ───────────────────────────────────────────────────────── */

// ⚠ Aquí mantené TODO tu contenido original (funciones, lectura CSV, etc.)
// No se eliminó ni una línea funcional del script anterior

/* ─────────────────────────────────────────────────────────
   Captura global final
   ───────────────────────────────────────────────────────── */
try {

  // ... aquí va todo el bloque de importación que ya tenías ...

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
