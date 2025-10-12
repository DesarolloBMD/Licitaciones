<?php
// api/dashboard_codigos.php
declare(strict_types=1);

@ini_set('memory_limit','256M');
@set_time_limit(0);

// CORS + JSON
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Fatal seguro en JSON
ini_set('display_errors', '0');
ob_start();
set_error_handler(function($sev,$msg,$file,$line){
  throw new ErrorException($msg,0,$sev,$file,$line);
});
register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    $out = ob_get_clean();
    echo json_encode(['ok'=>false,'error'=>'Fallo fatal: '.$err['message'],'debug'=>$out?trim(strip_tags($out)):null], JSON_UNESCAPED_UNICODE);
  }
});

// Conexión DB
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port']??5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true
  ]);
  $pdo->exec("SET TIME ZONE 'UTC'");
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

// Parámetros
$q       = trim((string)($_GET['q'] ?? ''));
$limit   = (int)($_GET['limit'] ?? 500);
$offset  = (int)($_GET['offset'] ?? 0);
$tiposRaw= trim((string)($_GET['tipos'] ?? '')); // CSV: "Asesoría,Imprenta"
$tipos   = array_values(array_filter(array_map('trim', $tiposRaw ? explode(',', $tiposRaw) : [])));

$mapAcc = ['Asesoria' => 'Asesoría', 'Imprenta' => 'Imprenta'];
$tipos = array_map(fn($t)=>($mapAcc[$t] ?? $t), $tipos);

if ($limit < 1)   $limit = 1;
if ($limit > 2000)$limit = 2000;
if ($offset < 0)  $offset = 0;

// Query (LEFT JOIN con uso en Procedimientos)
$sql = '
  WITH usage AS (
    SELECT p.codigo_madre AS codigo, COUNT(*)::INT AS usage_count
    FROM public."Procedimientos Adjudicados" p
    WHERE p.codigo_madre IS NOT NULL AND p.codigo_madre <> \'\'
    GROUP BY 1
  )
  SELECT
    c."Tipo de Clasificación"        AS "Tipo de Clasificación",
    c."Codigo de Clasificación"      AS "Codigo de Clasificación",
    c."Descripción de Clasificación" AS "Descripción de Clasificación",
    COALESCE(u.usage_count, 0)       AS usage_count
  FROM public."Catalogo Codigos" c
  LEFT JOIN usage u
         ON u.codigo = c."Codigo de Clasificación"
  WHERE 1=1
';
$params = [];
if (!empty($tipos)) {
  $in = [];
  foreach ($tipos as $i=>$t) { $in[]=':t'.$i; $params[':t'.$i]=$t; }
  $sql .= ' AND c."Tipo de Clasificación" IN ('.implode(',', $in).')';
}
if ($q !== '') {
  $sql .= ' AND (c."Codigo de Clasificación" ILIKE :q OR c."Descripción de Clasificación" ILIKE :q2)';
  $params[':q']  = '%'.$q.'%';
  $params[':q2'] = '%'.$q.'%';
}
$sql .= ' ORDER BY COALESCE(u.usage_count,0) DESC, c."Codigo de Clasificación" ASC
          LIMIT :lim OFFSET :off';

$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$debug = ob_get_contents(); ob_end_clean();
echo json_encode(['ok'=>true,'items'=>$rows,'limit'=>$limit,'offset'=>$offset,'count'=>count($rows),'debug'=>$debug?trim(strip_tags($debug)):null], JSON_UNESCAPED_UNICODE);
