<?php
declare(strict_types=1);
require __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Método no permitido (POST)']); exit; }

$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$raw = file_get_contents('php://input');
$body = (strpos($ct,'application/json') !== false) ? json_decode($raw, true) : null;
if (!is_array($body)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'JSON inválido']); exit; }

$id = (int)($body['id'] ?? 0);
$estado = trim((string)($body['estado'] ?? ''));
$respuesta = trim((string)($body['respuesta'] ?? ''));
$tipo = trim((string)($body['tipo'] ?? ''));
$ultima = trim((string)($body['ultima_observacion'] ?? ''));

if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'ID requerido']); exit; }

try {
  $sql = "UPDATE public.licitaciones
          SET estado = COALESCE(NULLIF(:estado,''), estado),
              respuesta = COALESCE(NULLIF(:respuesta,''), respuesta),
              tipo = COALESCE(NULLIF(:tipo,''), tipo),
              ultima_observacion = :ultima,
              actualizado_en = now()
          WHERE id = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':estado'    => $estado,
    ':respuesta' => $respuesta,
    ':tipo'      => $tipo,
    ':ultima'    => $ultima !== '' ? $ultima : null,
    ':id'        => $id,
  ]);
  echo json_encode(['ok'=>true, 'id'=>$id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
