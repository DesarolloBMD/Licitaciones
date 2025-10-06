<?php
// /API/tabulacion_cargar.php
declare(strict_types=1);

// ===== Cabeceras (JSON + CORS) =====
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Solo GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (usa GET)'], JSON_UNESCAPED_UNICODE);
  exit;
}

function jexit(bool $ok, array $extra = []): void {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== Conexión a PostgreSQL (Render) =====
// 1) DATABASE_URL (recomendada)
// 2) Fallback: tu URL pública con sslmode=require
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $parts = parse_url($DATABASE_URL);
  if (!$parts || !isset($parts['host'],$parts['user'],$parts['pass'],$parts['path'])) {
    throw new RuntimeException('DATABASE_URL inválida');
  }
  $host   = $parts['host'];
  $port   = isset($parts['port']) ? (int)$parts['port'] : 5432;
  $user   = $parts['user'];
  $pass   = $parts['pass'];
  $dbname = ltrim($parts['path'], '/');

  // Forzar SSL
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $host, $port, $dbname);
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  jexit(false, ['error' => 'Error de conexión: ' . $e->getMessage()]);
}

// ===== Parámetros opcionales (paginación + filtros sencillos) =====
$page      = max(1, (int)($_GET['page'] ?? 1));
$pageSize  = min(200, max(1, (int)($_GET['page_size'] ?? 200)));
$offset    = ($page - 1) * $pageSize;

$where  = [];
$params = [];

if (isset($_GET['anio']) && $_GET['anio'] !== '') {
  $where[]            = 'anio = :anio';
  $params[':anio']    = (int)$_GET['anio'];
}
if (isset($_GET['mes']) && $_GET['mes'] !== '') {
  $where[]            = 'mes = :mes';
  $params[':mes']     = (int)$_GET['mes'];
}
if (isset($_GET['q']) && $_GET['q'] !== '') {
  $q                  = trim((string)$_GET['q']);
  $where[]            = '(numero_licitacion ILIKE :q OR empresa_solicitante ILIKE :q)';
  $params[':q']       = '%'.$q.'%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ===== Consulta =====
$sql = "
SELECT
  id,
  anio,
  mes,
  fecha_presentacion,
  fecha_cierre,
  fecha,
  numero_licitacion,
  empresa_solicitante,
  descripcion,
  presupuesto_sicop,
  presupuesto_bmd,
  encargado,
  estado,
  respuesta,
  tipo,
  observaciones_iniciales,
  ultima_observacion,
  creado_en,
  actualizado_en
FROM public.licitaciones
{$whereSql}
ORDER BY creado_en DESC, id DESC
LIMIT :limit OFFSET :offset
";

try {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll();
  jexit(true, [
    'rows'      => $rows,
    'count'     => count($rows),
    'page'      => $page,
    'page_size' => $pageSize
  ]);

} catch (Throwable $e) {
  jexit(false, ['error' => 'DB error: ' . $e->getMessage()]);
}
