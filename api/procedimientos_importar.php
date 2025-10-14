<?php
// API/procedimientos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

/* 🔹 Modo depuración y JSON seguro */
ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/* 🔹 Manejo global de errores */
function fatal($msg, $code = 500) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

set_exception_handler(fn($e) => fatal("Excepción no controlada: " . $e->getMessage()));
set_error_handler(fn($errno, $errstr, $errfile, $errline) => fatal("PHP error: $errstr ($errfile:$errline)"));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

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
   Conexión a la base de datos
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
  fatal("Error de conexión: " . $e->getMessage());
}

/* ─────────────────────────────────────────────────────────
   Lógica principal (todo dentro de try/catch)
   ───────────────────────────────────────────────────────── */
try {

  /* ─────────────────────────────────────────────────────────
     Esquema auxiliar
     ───────────────────────────────────────────────────────── */
  function ensure_schema(PDO $pdo): void {
    $pdo->exec('ALTER TABLE public."Procedimientos Adjudicados" ADD COLUMN IF NOT EXISTS fingerprint text');
    $pdo->exec('ALTER TABLE public."Procedimientos Adjudicados" ADD COLUMN IF NOT EXISTS import_id text');
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
      if (!$row) fatal('No hay importaciones previas para anular');
      $import_id = $row['import_id'];
    }
    $pdo->beginTransaction();
    try {
      $del = $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id = :id');
      $del->execute([':id'=>$import_id]);
      $deleted = $del->rowCount();
      $pdo->prepare('DELETE FROM public.import_log WHERE import_id = :id')->execute([':id'=>$import_id]);
      $pdo->commit();
      echo json_encode(['ok'=>true,'anulado'=>$import_id,'eliminadas'=>$deleted], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
      $pdo->rollBack();
      fatal('No se pudo anular: '.$e->getMessage());
    }
    exit;
  }

  /* ─────────────────────────────────────────────────────────
     POST: importar
     ───────────────────────────────────────────────────────── */
  if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    fatal('Archivo no recibido (campo "archivo")', 400);
  }

  // 🔹 Aquí podés pegar tu bloque de importación original completo.
  // A partir de este punto ya todo error SQL o PHP será devuelto como JSON legible
  // y no más "error desconocido".

} catch (Throwable $e) {
  fatal("Error inesperado: " . $e->getMessage());
}
?>
