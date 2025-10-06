<?php
declare(strict_types=1);
require __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Método no permitido (GET)']); exit; }

function jexit($ok, $extra=[]){ http_response_code($ok?200:400); echo json_encode(array_merge(['ok'=>$ok],$extra), JSON_UNESCAPED_UNICODE); exit; }

$page = max(1, (int)($_GET['page'] ?? 1));
$size = min(100, max(1,(int)($_GET['page_size'] ?? 20)));

$q          = trim((string)($_GET['q'] ?? ''));     // empresa o Nº licitación
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

// Total
$sqlCount = "SELECT COUNT(*) FROM public.licitaciones WHERE $whereSql";
$stmt = $pdo->prepare($sqlCount);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Datos
$offset = ($page - 1) * $size;
$sql = "SELECT id, numero_licitacion, empresa_solicitante,
               estado, respuesta, tipo, creado_en, ultima_observacion
        FROM public.licitaciones
        WHERE $whereSql
        ORDER BY creado_en DESC, id DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v){ $stmt->bindValue($k, $v); }
$stmt->bindValue(':lim', $size, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

jexit(true, ['page'=>$page,'page_size'=>$size,'total'=>$total,'rows'=>$rows]);
