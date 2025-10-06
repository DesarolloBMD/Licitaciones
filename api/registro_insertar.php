<?php
// licitacion_insertar.php
declare(strict_types=1);

// ===== Cabeceras (JSON + CORS) =====
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');                 // en prod puedes poner tu dominio
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}

function jexit(bool $ok, array $extra = []): void {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== Conexión a PostgreSQL (Render) =====
// 1) DATABASE_URL (variable de entorno recomendada)
// 2) Fallback: tu URL pública de Render con sslmode=require
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

  // Forzar sslmode=require en DSN
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $host, $port, $dbname);

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  jexit(false, ['error' => 'Error de conexión: ' . $e->getMessage()]);
}

// ===== Helpers de validación =====
function req_int(string $name, int $min, int $max): ?int {
  $v = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
  return ($v !== false && $v !== null && $v >= $min && $v <= $max) ? $v : null;
}
function opt_int(string $name, ?int $min = null, ?int $max = null): ?int {
  if (!isset($_POST[$name]) || $_POST[$name] === '') return null;
  return req_int($name, $min ?? PHP_INT_MIN, $max ?? PHP_INT_MAX);
}
function req_date(string $name): ?string {
  $v = $_POST[$name] ?? '';
  if (!$v) return null;
  $dt = DateTime::createFromFormat('Y-m-d', $v);
  return ($dt && $dt->format('Y-m-d') === $v) ? $v : null;
}
function opt_date(string $name): ?string {
  if (!isset($_POST[$name]) || $_POST[$name] === '') return null;
  return req_date($name);
}
function req_text(string $name, int $maxLen): ?string {
  $v = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
  if ($v === '' || mb_strlen($v) > $maxLen) return null;
  return $v;
}
function opt_text(string $name, int $maxLen): ?string {
  if (!isset($_POST[$name])) return null;
  $v = trim((string)$_POST[$name]);
  if ($v === '') return null;
  return (mb_strlen($v) <= $maxLen) ? $v : null;
}
function opt_money(string $name): ?float {
  if (!isset($_POST[$name]) || $_POST[$name] === '') return 0.0; // default 0 si vacío
  $raw = str_replace(',', '.', (string)$_POST[$name]);
  if (!is_numeric($raw)) return null;
  $n = (float)$raw;
  return $n >= 0 ? $n : null;
}
function req_enum(string $name, array $allowed): ?string {
  $v = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
  return in_array($v, $allowed, true) ? $v : null;
}

// ===== Leer y validar payload del formulario =====
$anio   = req_int('anio', 2000, 2100);
$mes    = req_int('mes', 1, 12);
$fp     = req_date('fecha_presentacion');
$fc     = req_date('fecha_cierre');
$fecha  = opt_date('fecha');

$numero = req_text('numero_licitacion', 50);
$empresa= req_text('empresa_solicitante', 255);
$desc   = req_text('descripcion', 20000);

$psicop = opt_money('presupuesto_sicop'); // 0 si vacío
$pbmd   = opt_money('presupuesto_bmd');   // 0 si vacío
if ($psicop === null || $pbmd === null) {
  jexit(false, ['error'=>'Presupuestos inválidos (usar números positivos)']);
}

$encargado = req_enum('encargado', ['Equipo Consultoría BMD','Equipo Impresión BMD']);
$estado    = req_enum('estado', ['en proceso','finalizada']);
$respuesta = req_enum('respuesta', ['pendiente','perdida','ganada']);
$tipo      = req_enum('tipo', ['cantidad definida','demanda']);

$obs_ini   = opt_text('observaciones_iniciales', 10000);
$ultima    = null; // no se envía desde registro

// Requeridos
$missing = [];
foreach ([
  'anio'=>$anio,'mes'=>$mes,'fecha_presentacion'=>$fp,'fecha_cierre'=>$fc,
  'numero_licitacion'=>$numero,'empresa_solicitante'=>$empresa,'descripcion'=>$desc,
  'encargado'=>$encargado,'estado'=>$estado,'respuesta'=>$respuesta,'tipo'=>$tipo
] as $k=>$v) { if ($v === null) $missing[] = $k; }
if ($missing) jexit(false, ['error'=>'Faltan campos requeridos', 'campos'=>$missing]);

// Coherencia de fechas
if ($fc < $fp) {
  jexit(false, ['error'=>'La fecha de cierre no puede ser anterior a la de presentación']);
}

// ===== Insert =====
$sql = "INSERT INTO public.licitaciones
 (anio, mes, fecha_presentacion, fecha_cierre, fecha,
  numero_licitacion, empresa_solicitante, descripcion,
  presupuesto_sicop, presupuesto_bmd,
  encargado, estado, respuesta, tipo,
  observaciones_iniciales, ultima_observacion)
VALUES
 (:anio, :mes, :fp, :fc, :fecha,
  :numero, :empresa, :descr,
  :psicop, :pbmd,
  :enc, :est, :resp, :tipo,
  :obs_ini, :ultima)
RETURNING id";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':anio'    => $anio,
    ':mes'     => $mes,
    ':fp'      => $fp,
    ':fc'      => $fc,
    ':fecha'   => $fecha,
    ':numero'  => $numero,
    ':empresa' => $empresa,
    ':descr'   => $desc,
    ':psicop'  => $psicop,
    ':pbmd'    => $pbmd,
    ':enc'     => $encargado,
    ':est'     => $estado,
    ':resp'    => $respuesta,
    ':tipo'    => $tipo,
    ':obs_ini' => $obs_ini,
    ':ultima'  => $ultima
  ]);
  $id = (int)$stmt->fetchColumn();
  jexit(true, ['id' => $id]);

} catch (PDOException $e) {
  if ($e->getCode() === '23505') { // unique_violation
    jexit(false, ['error'=>'El número de licitación ya existe']);
  }
  if ($e->getCode() === '23514') { // check_violation
    jexit(false, ['error'=>'Violación de restricción (revisa fechas/valores)']);
  }
  jexit(false, ['error'=>'DB error: '.$e->getMessage()]);
} catch (Throwable $e) {
  jexit(false, ['error'=>'Error inesperado: '.$e->getMessage()]);
}
