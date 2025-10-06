<?php
declare(strict_types=1);
require __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Método no permitido (GET)']); exit; }

$q          = trim((string)($_GET['q'] ?? ''));
$anio       = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes        = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;
$estado     = $_GET['estado']     ?? '';
$respuesta  = $_GET['respuesta']  ?? '';
$tipo       = $_GET['tipo']       ?? '';

$where = ['1=1']; $params = [];
if ($q !== '') {
  $where[] = "(numero_licitacion ILIKE :qilike
              OR empresa_solicitante ILIKE :qilike
              OR to_tsvector('spanish', coalesce(empresa_solicitante,'') || ' ' || coalesce(descripcion,'') || ' ' || coalesce(ultima_observacion,'')) @@ plainto_tsquery('spanish', :qft))";
  $params[':qilike'] = '%'.$q.'%';
  $params[':qft']    = $q;
}
if ($anio)      { $where[] = 'anio = :anio'; $params[':anio'] = $anio; }
if ($mes)       { $where[] = 'mes = :mes';   $params[':mes']  = $mes;  }
if ($estado)    { $where[] = 'estado = :estado'; $params[':estado'] = $estado; }
if ($respuesta) { $where[] = 'respuesta = :respuesta'; $params[':respuesta'] = $respuesta; }
if ($tipo)      { $where[] = 'tipo = :tipo'; $params[':tipo'] = $tipo; }

$whereSql = implode(' AND ', $where);

$sql = "SELECT
          COUNT(*)                                                     AS total,
          COUNT(*) FILTER (WHERE respuesta = 'pendiente')              AS pendiente,
          COUNT(*) FILTER (WHERE respuesta = 'perdida')                AS perdida,
          COUNT(*) FILTER (WHERE respuesta = 'ganada')                 AS ganada,
          COUNT(*) FILTER (WHERE estado    = 'en proceso')             AS en_proceso,
          COUNT(*) FILTER (WHERE estado    = 'finalizada')             AS finalizada
        FROM public.licitaciones
        WHERE $whereSql";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
  'ok' => true,
  'total' => (int)($row['total'] ?? 0),
  'por_respuesta' => [
    'pendiente' => (int)($row['pendiente'] ?? 0),
    'perdida'   => (int)($row['perdida'] ?? 0),
    'ganada'    => (int)($row['ganada'] ?? 0),
  ],
  'por_estado' => [
    'en_proceso'  => (int)($row['en_proceso'] ?? 0),
    'finalizada'  => (int)($row['finalizada'] ?? 0),
  ],
], JSON_UNESCAPED_UNICODE);
