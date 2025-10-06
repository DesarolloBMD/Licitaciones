<?php
// licitaciones_resumen.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (GET)'], JSON_UNESCAPED_UNICODE);
  exit;
}
function jexit($ok, $extra=[]){ http_response_code($ok?200:400); echo json_encode(array_merge(['ok'=>$ok],$extra), JSON_UNESCAPED_UNICODE); exit; }

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try{
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port'] ?? 5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION ]);
}catch(Throwable $e){ jexit(false, ['error'=>'DB: '.$e->getMessage()]); }

$q          = trim((string)($_GET['q'] ?? ''));
$anio       = isset($_GET['anio']) ? (int)$_GET['anio'] : null;
$mes        = isset($_GET['mes'])  ? (int)$_GET['mes']  : null;
$estado     = $_GET['estado']     ?? null;
$respuesta  = $_GET['respuesta']  ?? null;
$tipo       = $_GET['tipo']       ?? null;

$where = ['1=1']; $params = [];
if ($q !== '') {
  $where[] = "(to_tsvector('spanish', coalesce(empresa_solicitante,'') || ' ' || coalesce(descripcion,'') || ' ' || coalesce(ultima_observacion,'')) @@ plainto_tsquery('spanish', :qft)
               OR numero_licitacion ILIKE :qilike)";
  $params[':qft']    = $q;
  $params[':qilike'] = '%'.$q.'%';
}
if ($anio)      { $where[] = 'anio = :anio'; $params[':anio'] = $anio; }
if ($mes)       { $where[] = 'mes = :mes';   $params[':mes']  = $mes;  }
if ($estado)    { $where[] = 'estado = :estado'; $params[':estado'] = $estado; }
if ($respuesta) { $where[] = 'respuesta = :respuesta'; $params[':respuesta'] = $respuesta; }
if ($tipo)      { $where[] = 'tipo = :tipo'; $params[':tipo'] = $tipo; }
$whereSql = implode(' AND ', $where);

$sql = "SELECT
  COUNT(*) AS total,
  COUNT(*) FILTER (WHERE respuesta='pendiente') AS pendientes,
  COUNT(*) FILTER (WHERE respuesta='perdida')   AS perdidas,
  COUNT(*) FILTER (WHERE respuesta='ganada')    AS ganadas,
  COUNT(*) FILTER (WHERE estado='en proceso')   AS en_proceso,
  COUNT(*) FILTER (WHERE estado='finalizada')   AS finalizadas
FROM public.licitaciones
WHERE $whereSql";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

jexit(true, [
  'total' => (int)($agg['total'] ?? 0),
  'por_respuesta' => [
    'pendiente' => (int)($agg['pendientes'] ?? 0),
    'perdida'   => (int)($agg['perdidas'] ?? 0),
    'ganada'    => (int)($agg['ganadas'] ?? 0),
  ],
  'por_estado' => [
    'en_proceso' => (int)($agg['en_proceso'] ?? 0),
    'finalizada' => (int)($agg['finalizadas'] ?? 0),
  ],
]);
