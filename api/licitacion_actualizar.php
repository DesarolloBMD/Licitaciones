<?php
// API/licitacion_actualizar.php
declare(strict_types=1);

// ===== Cabeceras (JSON + CORS) =====
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');                 // en prod puedes restringir a tu dominio
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
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $host, $port, $dbname);

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
} catch (Throwable $e) {
  jexit(false, ['error' => 'Error de conexión: ' . $e->getMessage()]);
}

// ===== Helpers =====
function req_int(string $name, int $min = 1, int $max = PHP_INT_MAX): ?int {
  $v = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
  return ($v !== false && $v !== null && $v >= $min && $v <= $max) ? $v : null;
}
function opt_enum(string $name, array $allowed): ?string {
  if (!array_key_exists($name, $_POST)) return null; // no vino -> no actualizar
  $v = trim((string)$_POST[$name]);
  if ($v === '') return null; // vacío -> no cambia (si quieres forzar null en enums, quita esta línea)
  if (!in_array($v, $allowed, true)) {
    jexit(false, ['error' => "Valor inválido para $name"]);
  }
  return $v;
}
function got_key(string $name): bool {
  return array_key_exists($name, $_POST);
}
function ultima_from_post(): array {
  // Devuelve [set:boolean, value:?string]
  if (!array_key_exists('ultima_observacion', $_POST)) return [false, null];
  $raw = trim((string)$_POST['ultima_observacion']);
  if ($raw === '') return [true, null];               // borrar -> NULL
  if (mb_strlen($raw) > 10000) {
    jexit(false, ['error'=>'ultima_observacion demasiado larga']);
  }
  return [true, $raw];
}

// ===== Leer payload =====
$id = req_int('id', 1);
if ($id === null) jexit(false, ['error'=>'ID inválido o faltante']);

$estado    = opt_enum('estado',    ['en proceso','finalizada']);
$respuesta = opt_enum('respuesta', ['pendiente','perdida','ganada']);
$tipo      = opt_enum('tipo',      ['cantidad definida','demanda']);
[$setUltima, $ultima] = ultima_from_post();

// Construir SET dinámico
$sets = [];
$params = [':id' => $id];

if ($estado !== null)    { $sets[] = 'estado = :estado';       $params[':estado']    = $estado; }
if ($respuesta !== null) { $sets[] = 'respuesta = :respuesta'; $params[':respuesta'] = $respuesta; }
if ($tipo !== null)      { $sets[] = 'tipo = :tipo';           $params[':tipo']      = $tipo; }
if ($setUltima)          { $sets[] = 'ultima_observacion = :ultima'; $params[':ultima'] = $ultima; }

if (!$sets) {
  jexit(false, ['error'=>'Nada que actualizar (envía al menos un campo: estado, respuesta, tipo, ultima_observacion)']);
}

// Siempre toca actualizado_en
$sets[] = 'actualizado_en = now()';
$sql = "UPDATE public.licitaciones SET ".implode(', ', $sets)." WHERE id = :id";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  jexit(true, ['id'=>$id, 'updated_fields'=>array_keys(array_filter([
    'estado' => $estado !== null,
    'respuesta' => $respuesta !== null,
    'tipo' => $tipo !== null,
    'ultima_observacion' => $setUltima,
  ]))]);
} catch (Throwable $e) {
  jexit(false, ['error'=>'DB error: '.$e->getMessage()]);
}
