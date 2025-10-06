<?php
// /API/licitacion_actualizar.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}
function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok?200:400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

try { require __DIR__ . '/db.php'; }
catch (Throwable $e) { jexit(false, ['error'=>'Error de conexión: '.$e->getMessage()]); }

function req_int(string $name, int $min = 1, int $max = PHP_INT_MAX): ?int {
  $v = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
  return ($v !== false && $v !== null && $v >= $min && $v <= $max) ? $v : null;
}
function opt_enum(string $name, array $allowed): ?string {
  if (!array_key_exists($name, $_POST)) return null;
  $v = trim((string)$_POST[$name]);
  if ($v === '') return null;
  if (!in_array($v, $allowed, true)) jexit(false, ['error'=>"Valor inválido para $name"]);
  return $v;
}
function ultima_from_post(): array {
  if (!array_key_exists('ultima_observacion', $_POST)) return [false, null];
  $raw = trim((string)$_POST['ultima_observacion']);
  if ($raw === '') return [true, null]; // borrar -> NULL
  if (mb_strlen($raw) > 10000) jexit(false, ['error'=>'ultima_observacion demasiado larga']);
  return [true, $raw];
}

$id = req_int('id', 1);
if ($id === null) jexit(false, ['error'=>'ID inválido o faltante']);

$estado    = opt_enum('estado',    ['en proceso','finalizada']);
$respuesta = opt_enum('respuesta', ['pendiente','perdida','ganada']);
$tipo      = opt_enum('tipo',      ['cantidad definida','demanda']);
[$setUltima, $ultima] = ultima_from_post();

$sets = []; $params = [':id'=>$id];
if ($estado !== null)    { $sets[]='estado = :estado';             $params[':estado']=$estado; }
if ($respuesta !== null) { $sets[]='respuesta = :respuesta';       $params[':respuesta']=$respuesta; }
if ($tipo !== null)      { $sets[]='tipo = :tipo';                 $params[':tipo']=$tipo; }
if ($setUltima)          { $sets[]='ultima_observacion = :ultima'; $params[':ultima']=$ultima; }
if (!$sets) jexit(false, ['error'=>'Nada que actualizar']);

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
