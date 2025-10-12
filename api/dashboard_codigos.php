<?php
// api/catalogo_codigos_list.php
declare(strict_types=1);

@ini_set('display_errors','0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ===== JSON-safe fatal ===== */
ob_start();
set_error_handler(function($severity,$message,$file,$line){
  throw new ErrorException($message,0,$severity,$file,$line);
});
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true);
    $out = ob_get_clean();
    echo json_encode([
      'ok'=>false,
      'error'=>'Fallo fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line'],
      'debug'=>$out ? trim(strip_tags($out)) : null
    ], JSON_UNESCAPED_UNICODE);
  }
});

header('Content-Type: application/json; charset=utf-8');

/* ===== Conexión a Postgres ===== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}

try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], isset($p['port'])?(int)$p['port']:5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true
  ]);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== Parámetros ===== */
$q      = trim((string)($_GET['q'] ?? ''));
$tipos  = trim((string)($_GET['tipos'] ?? '')); // ej: "Asesoría,Imprenta"
$limit  = (int)($_GET['limit'] ?? 500);
if ($limit < 1)   $limit = 1;
if ($limit > 1000)$limit = 1000;

$params = [];
$where  = [];

// Filtro por texto (código o descripción)
if ($q !== '') {
  $where[] = '(
    cc."Codigo de Clasificación" ILIKE :q
    OR cc."Descripción de Clasificación" ILIKE :q
  )';
  $params[':q'] = '%'.$q.'%';
}

// Filtro por tipos (coma separada)
if ($tipos !== '') {
  $arr = array_values(array_filter(array_map('trim', explode(',', $tipos)), fn($x)=>$x!==''));
  if ($arr) {
    // construimos placeholders :t0, :t1, ...
    $phs = [];
    foreach ($arr as $i=>$t) {
      $k = ':t'.$i; $phs[] = $k; $params[$k] = $t;
    }
    $where[] = 'cc."Tipo de Clasificación" IN ('.implode(',', $phs).')';
  }
}

$whereSQL = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ===== Consulta =====
   usage_count viene de "Procedimientos Adjudicados" comparando contra columna "codigo madre" */
$sql = "
  WITH usage_map AS (
    SELECT \"codigo madre\" AS codigo_madre, COUNT(*) AS usage_count
    FROM public.\"Procedimientos Adjudicados\"
    WHERE \"codigo madre\" IS NOT NULL AND \"codigo madre\" <> ''
    GROUP BY 1
  )
  SELECT
    cc.\"Tipo de Clasificación\"        AS \"Tipo de Clasificación\",
    cc.\"Codigo de Clasificación\"      AS \"Codigo de Clasificación\",
    cc.\"Descripción de Clasificación\" AS \"Descripción de Clasificación\",
    COALESCE(um.usage_count, 0)         AS usage_count
  FROM public.\"Catalogo Codigos\" cc
  LEFT JOIN usage_map um
    ON um.codigo_madre = cc.\"Codigo de Clasificación\"
  $whereSQL
  ORDER BY cc.\"Tipo de Clasificación\", cc.\"Codigo de Clasificación\"
  LIMIT $limit
";

try {
  $st = $pdo->prepare($sql);
  foreach($params as $k=>$v){ $st->bindValue($k, $v, PDO::PARAM_STR); }
  $st->execute();
  $rows = $st->fetchAll();

  $debug = ob_get_contents(); ob_end_clean();
  echo json_encode([
    'ok'    => true,
    'items' => $rows,
    'count' => count($rows),
    'debug' => $debug ? trim(strip_tags($debug)) : null
  ], JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
