<?php
// licitaciones_listar.php
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

$page = max(1, (int)($_GET['page'] ?? 1));
$size = min(100, max(1,(int)($_GET['page_size'] ?? 20)));

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

$sqlCount = "SELECT COUNT(*) FROM public.licitaciones WHERE $whereSql";
$stmt = $pdo->prepare($sqlCount); $stmt->execute($params);
$total = (int)$stmt->fetchColumn();

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
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

jexit(true, ['page'=>$page,'page_size'=>$size,'total'=>$total,'rows'=>$rows]);
