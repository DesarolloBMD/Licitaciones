<?php
// api/subir_procedimientos.php
declare(strict_types=1);

@ini_set('memory_limit', '1024M');
@set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// ======================================
// CONFIGURACIÓN DE CONEXIÓN A LA BASE
// ======================================
$DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';

try {
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'],
    $p['port'] ?? 5432,
    ltrim($p['path'], '/')
  );
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
  exit;
}

// ======================================
// HELPERS
// ======================================
function limpiar_encabezado(?string $s): string {
  if ($s === null) return '';
  $s = trim($s);
  $s = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], '', $s); // eliminar BOM y espacios raros
  return preg_replace('/\s+/u', ' ', $s);
}

function convertir_utf8(string $s): string {
  if (mb_detect_encoding($s, 'UTF-8', true)) return $s;
  return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1, Windows-1252');
}

function normalizar_numero(string $s): ?float {
  $s = trim(str_replace([' ', ' '], '', $s)); // elimina espacios y no-break spaces
  $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : null;
}

function normalizar_fecha(?string $s): ?string {
  if (!$s) return null;
  $s = trim($s);
  $s = str_ireplace(
    ['lunes,','martes,','miércoles,','jueves,','viernes,','sábado,','domingo,'],
    '', $s
  );
  $s = trim($s);
  $ts = strtotime($s);
  return $ts ? date('Y-m-d', $ts) : null;
}

function uuid(): string {
  $d = random_bytes(16);
  $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
  $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ======================================
// CREAR TABLA DE LOGS SI NO EXISTE
// ======================================
$pdo->exec('CREATE TABLE IF NOT EXISTS public.importaciones_log (
  import_id UUID PRIMARY KEY,
  filename TEXT,
  total_rows INTEGER DEFAULT 0,
  inserted INTEGER DEFAULT 0,
  skipped INTEGER DEFAULT 0,
  started_at TIMESTAMPTZ DEFAULT now(),
  finished_at TIMESTAMPTZ,
  anulado_at TIMESTAMPTZ,
  source_ip TEXT
)');

// ======================================
// ENDPOINT: HISTORIAL Y ANULAR
// ======================================
if (isset($_GET['accion']) && $_GET['accion'] === 'logs') {
  $rows = $pdo->query('SELECT * FROM public.importaciones_log ORDER BY started_at DESC LIMIT 50')->fetchAll();
  echo json_encode(['ok' => true, 'logs' => $rows]);
  exit;
}

if (isset($_POST['accion']) && $_POST['accion'] === 'anular') {
  $id = $_POST['import_id'] ?? '';
  if ($id === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta import_id']);
    exit;
  }
  try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id = :id')->execute([':id' => $id]);
    $pdo->prepare('UPDATE public.importaciones_log SET anulado_at = now() WHERE import_id = :id')->execute([':id' => $id]);
    $pdo->commit();
    echo json_encode(['ok' => true, 'anulado' => $id]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// ======================================
// ENDPOINT: IMPORTAR CSV/XLSX
// ======================================
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['ok' => false, 'error' => 'Archivo no recibido']);
  exit;
}

$name = $_FILES['archivo']['name'];
$tmp = $_FILES['archivo']['tmp_name'];
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'])) {
  echo json_encode(['ok' => false, 'error' => 'Formato no permitido (.csv o .xlsx)']);
  exit;
}

// Solo procesaremos CSV por ahora (los XLSX se pueden convertir con Excel)
$fh = fopen($tmp, 'r');
if (!$fh) {
  echo json_encode(['ok' => false, 'error' => 'No se pudo abrir el archivo']);
  exit;
}

// Detectar delimitador
$first = fgets($fh);
rewind($fh);
$delims = [";" => substr_count($first, ";"), "\t" => substr_count($first, "\t"), "," => substr_count($first, ",")];
arsort($delims);
$delim = array_key_first($delims) ?: ";";

// Leer encabezados
$headers = fgetcsv($fh, 0, $delim);
$headers = array_map('limpiar_encabezado', $headers);

$import_id = uuid();
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

$pdo->prepare('INSERT INTO public.importaciones_log(import_id,filename,source_ip,started_at)
               VALUES(:i,:f,:ip,now())')->execute([':i' => $import_id, ':f' => $name, ':ip' => $ip]);

$total = 0;
$inserted = 0;
$skipped = 0;

$pdo->beginTransaction();

try {
  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $total++;
    if (count(array_filter($row)) == 0) { $skipped++; continue; }

    $data = [];
    foreach ($headers as $i => $h) {
      $val = $row[$i] ?? null;
      $val = convertir_utf8((string)$val);
      if (preg_match('/FECHA/i', $h)) $val = normalizar_fecha($val);
      elseif (preg_match('/MONTO|CANTIDAD|ID$/i', $h)) $val = normalizar_numero($val);
      $data[$h] = $val;
    }

    $cols = array_map(fn($h) => '"' . $h . '"', array_keys($data));
    $placeholders = array_map(fn($h) => ':' . preg_replace('/\W+/', '_', strtolower($h)), array_keys($data));
    $sql = 'INSERT INTO public."Procedimientos Adjudicados" (' . implode(',', $cols) . ', import_id)
            VALUES (' . implode(',', $placeholders) . ', :import_id)';
    $stmt = $pdo->prepare($sql);

    foreach ($data as $h => $v) {
      $stmt->bindValue(':' . preg_replace('/\W+/', '_', strtolower($h)), $v);
    }
    $stmt->bindValue(':import_id', $import_id);

    try {
      $stmt->execute();
      $inserted += $stmt->rowCount();
    } catch (Throwable $e) {
      $skipped++;
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

fclose($fh);

$pdo->prepare('UPDATE public.importaciones_log
               SET total_rows = :t, inserted = :i, skipped = :s, finished_at = now()
               WHERE import_id = :id')->execute([
                 ':t' => $total, ':i' => $inserted, ':s' => $skipped, ':id' => $import_id
               ]);

echo json_encode([
  'ok' => true,
  'import_id' => $import_id,
  'insertados' => $inserted,
  'saltados' => $skipped,
  'total' => $total
]);
?>
