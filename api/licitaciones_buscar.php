<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $parts = parse_url($DATABASE_URL);
  if (!$parts || !isset($parts['host'],$parts['user'],$parts['pass'],$parts['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $parts['host'],
    isset($parts['port']) ? (int)$parts['port'] : 5432,
    ltrim($parts['path'],'/')
  );
  $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){ jexit(false, ['error'=>'Conexión: '.$e->getMessage()]); }

// Filtros
$q    = isset($_GET['q'])    ? trim((string)$_GET['q']) : '';
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;

$sql = "SELECT id, numero_licitacion, empresa_solicitante, estado, respuesta, tipo, creado_en, ultima_observacion
        FROM public.licitaciones
        WHERE 1=1";
$params = [];
if ($q !== '') {
  $sql .= " AND (numero_licitacion ILIKE :q OR empresa_solicitante ILIKE :q)";
  $params[':q'] = '%'.$q.'%';
}
if ($anio) { $sql .= " AND anio = :anio"; $params[':anio'] = $anio; }
if ($mes)  { $sql .= " AND mes  = :mes";  $params[':mes']  = $mes;  }
$sql .= " ORDER BY creado_en DESC LIMIT 500";

try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  jexit(true, ['rows'=>$rows]);
} catch(Throwable $e){
  jexit(false, ['error'=>'DB: '.$e->getMessage()]);
}
