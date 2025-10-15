<?php
declare(strict_types=1);
@ini_set('memory_limit', '1G');
@set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$DATABASE_URL = getenv('DATABASE_URL') ?: 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;sslmode=require", $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Error conexión: ' . $e->getMessage()]);
  exit;
}

/* === HISTORIAL === */
if (isset($_GET['action']) && $_GET['action'] === 'historial') {
  try {
    $q = $pdo->query("SELECT * FROM public.procedimientos_import_log ORDER BY started_at DESC");
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'rows' => $rows]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/* === ANULAR === */
if (isset($_GET['action']) && $_GET['action'] === 'anular' && isset($_GET['id'])) {
  $id = $_GET['id'];
  try {
    $stmt = $pdo->prepare("SELECT public.anular_importacion(:id, 'Anulado desde interfaz')");
    $stmt->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/* === SUBIR CSV === */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
  exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok' => false, 'error' => 'Archivo no recibido']);
  exit;
}

$nombre = $_FILES['archivo']['name'];
$tmp = $_FILES['archivo']['tmp_name'];
$fh = fopen($tmp, 'r');
if (!$fh) {
  echo json_encode(['ok' => false, 'error' => 'No se pudo leer el archivo']);
  exit;
}

$cols = fgetcsv($fh, 0, ',');
if (!$cols) {
  echo json_encode(['ok' => false, 'error' => 'Archivo vacío o encabezados inválidos']);
  exit;
}

$import_id = uniqid('imp_');
$insertados = 0;
$saltados = 0;
$errores = [];

$pdo->beginTransaction();

try {
  $stmt = $pdo->prepare('INSERT INTO public."Procedimientos Adjudicados" (import_id, "' . implode('","', $cols) . '") VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')');
  while (($r = fgetcsv($fh, 0, ',')) !== false) {
    if (count(array_filter($r)) == 0) continue;
    array_unshift($r, $import_id);
    try { $stmt->execute($r); $insertados++; }
    catch (Throwable $e) { $saltados++; if ($saltados < 10) $errores[] = $e->getMessage(); }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
} finally { fclose($fh); }

$pdo->prepare('INSERT INTO public.procedimientos_import_log(import_id, filename, total_rows, inserted, skipped, started_at, finished_at, source_ip)
               VALUES(:i,:f,:t,:ins,:sk,now(),now(),:ip)')
    ->execute([
      ':i' => $import_id,
      ':f' => $nombre,
      ':t' => $insertados + $saltados,
      ':ins' => $insertados,
      ':sk' => $saltados,
      ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

echo json_encode(['ok' => true, 'insertados' => $insertados, 'saltados' => $saltados, 'total' => $insertados + $saltados, 'errores' => $errores]);
