<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok?200:400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

// Conexión (usa externa si no hay DATABASE_URL)
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $parts = parse_url($DATABASE_URL);
  $host   = $parts['host'];
  $port   = isset($parts['port']) ? (int)$parts['port'] : 5432;
  $user   = $parts['user'];
  $pass   = $parts['pass'];
  $dbname = ltrim($parts['path'], '/');
  parse_str($parts['query'] ?? '', $qs);
  $sslmode = $qs['sslmode'] ?? 'require';
  $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false
  ]);
} catch (Throwable $e) { jexit(false, ['error'=>'DB: '.$e->getMessage()]); }

// Filtros
$q    = trim((string)($_GET['q'] ?? ''));
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
$mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;

$where = ['1=1']; $p=[];
if ($q !== '') {
  $where[] = "(numero_licitacion ILIKE :qilike OR empresa_solicitante ILIKE :qilike)";
  $p[':qilike'] = '%'.$q.'%';
}
if ($anio) { $where[]='anio = :anio'; $p[':anio']=$anio; }
if ($mes)  { $where[]='mes = :mes';   $p[':mes']=$mes;   }

$sql = "SELECT id, numero_licitacion, empresa_solicitante, estado, respuesta, tipo, creado_en, ultima_observacion
        FROM public.licitaciones
        WHERE ".implode(' AND ', $where)."
        ORDER BY actualizado_en DESC, creado_en DESC
        LIMIT 500"; // listado hacia abajo (máx 500 para no explotar

$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$rows = $stmt->fetchAll();

jexit(true, ['rows'=>$rows]);
