<?php
// /API/licitaciones_listar.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa GET)'], JSON_UNESCAPED_UNICODE);
  exit;
}
function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok?200:400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  require __DIR__ . '/db.php';
} catch (Throwable $e) {
  jexit(false, ['error'=>'Error de conexión: '.$e->getMessage()]);
}

// ===== Parámetros =====
$page      = max(1, (int)($_GET['page'] ?? 1));
$page_size = (int)($_GET['page_size'] ?? 20);
if ($page_size < 1)   $page_size = 20;
if ($page_size > 200) $page_size = 200;
$offset = ($page - 1) * $page_size;

$q         = trim((string)($_GET['q'] ?? ''));
$anio      = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes       = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;
$estado    = isset($_GET['estado'])    ? (string)$_GET['estado']    : '';
$respuesta = isset($_GET['respuesta']) ? (string)$_GET['respuesta'] : '';
$tipo      = isset($_GET['tipo'])      ? (string)$_GET['tipo']      : '';

$where = ['1=1']; $params = [];
if ($q !== '') {
  $where[] = "(numero_licitacion ILIKE :qilike
              OR empresa_solicitante ILIKE :qilike
              OR to_tsvector('spanish', coalesce(empresa_solicitante,'') || ' ' || coalesce(descripcion,'') || ' ' || coalesce(ultima_observacion,'')) @@ plainto_tsquery('spanish', :qft))";
  $params[':qilike'] = '%'.$q.'%';
  $params[':qft']    = $q;
}
if ($anio)      { $where[] = 'anio = :anio';         $params[':anio'] = $anio; }
if ($mes)       { $where[] = 'mes = :mes';           $params[':mes']  = $mes;  }
if ($estado)    { $where[] = 'estado = :estado';     $params[':estado'] = $estado; }
if ($respuesta) { $where[] = 'respuesta = :respuesta'; $params[':respuesta'] = $respuesta; }
if ($tipo)      { $where[] = 'tipo = :tipo';         $params[':tipo'] = $tipo; }

$whereSql = implode(' AND ', $where);

// Total
$sqlCount = "SELECT COUNT(*) AS c FROM public.licitaciones WHERE $whereSql";
$stmt = $pdo->prepare($sqlCount);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Datos
$sql = "SELECT
          id, anio, mes,
          fecha_presentacion, fecha_cierre, fecha,
          numero_licitacion, empresa_solicitante, descripcion,
          presupuesto_sicop, presupuesto_bmd,
          encargado, estado, respuesta, tipo,
          observaciones_iniciales, ultima_observacion,
          creado_en, actualizado_en
        FROM public.licitaciones
        WHERE $whereSql
        ORDER BY actualizado_en DESC, creado_en DESC
        LIMIT :lim OFFSET :off";

$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $page_size, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

jexit(true, [
  'total' => $total,
  'page'  => $page,
  'page_size' => $page_size,
  'rows'  => $rows,
]);
