<?php
// API/pa_dashboard.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* —— conexión (igual que el importador) —— */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try{
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port']??5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true
  ]);
  $pdo->exec("SET datestyle TO 'ISO, DMY'");
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* —— util —— */
function clean_codes(string $s): array {
  if ($s==='') return [];
  $out=[]; foreach (explode(',', $s) as $x) {
    $x = preg_replace('/\D+/', '', $x);
    if ($x!=='') $out[]=$x;
  }
  return array_values(array_unique($out));
}
function placeholders(string $base, int $n): array {
  $ph=[]; for($i=0;$i<$n;$i++) $ph[]=":{$base}{$i}";
  return $ph;
}

/* —— Parámetros de filtro —— */
$action = $_GET['action'] ?? '';
$prodIds = clean_codes(trim((string)($_GET['prod_ids'] ?? '')));
$anio    = trim((string)($_GET['anio'] ?? ''));
$limit   = max(1, min(1000, (int)($_GET['limit'] ?? 100)));
$offset  = max(0, (int)($_GET['offset'] ?? 0));

/* —— Boot: años + top PROD_ID para chips —— */
if ($action === 'boot') {
  try{
    $years = $pdo->query('SELECT DISTINCT "Año de reporte" AS y FROM public."Procedimientos Adjudicados" WHERE "Año de reporte" IS NOT NULL ORDER BY y DESC NULLS LAST')->fetchAll(PDO::FETCH_COLUMN);
    $top   = $pdo->query('SELECT "PROD_ID" AS prod_id, COUNT(*) AS cnt
                          FROM public."Procedimientos Adjudicados"
                          WHERE "PROD_ID" IS NOT NULL AND "PROD_ID" <> \'\'
                          GROUP BY "PROD_ID" ORDER BY cnt DESC, prod_id ASC LIMIT 50')->fetchAll();
    echo json_encode(['ok'=>true,'years'=>$years,'top_prod_ids'=>$top], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* —— WHERE dinámico —— */
$where=[]; $params=[];
if ($prodIds){
  $ph = placeholders('p', count($prodIds));
  $where[] = '"PROD_ID" IN ('.implode(',', $ph).')';
  foreach ($prodIds as $i=>$v) $params[":p{$i}"]=$v;
}
if ($anio !== ''){
  $where[] = '"Año de reporte" = :anio';
  $params[':anio'] = $anio;
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* —— Totales —— */
$sqlTot = 'SELECT COUNT(*) AS count,
                  COALESCE(SUM("MONTO_ADJU_LINEA_CRC"),0) AS sum_crc,
                  COALESCE(SUM("MONTO_ADJU_LINEA_USD"),0) AS sum_usd
           FROM public."Procedimientos Adjudicados" '.$whereSql;
$st = $pdo->prepare($sqlTot); $st->execute($params); $totales = $st->fetch();

/* —— Serie por mes (orden numérico si el mes es 1-12, si no, alfabético) —— */
$sqlSerie = 'SELECT
               "Mes de Descarga" AS mes,
               COUNT(*) AS cnt,
               COALESCE(SUM("MONTO_ADJU_LINEA_CRC"),0) AS total_crc
             FROM public."Procedimientos Adjudicados"
             '.$whereSql.'
             GROUP BY mes
             ORDER BY
               COALESCE(NULLIF(regexp_replace("Mes de Descarga",\'[^0-9]\',\'\',\'g\'),\'\')::int, 999),
               mes';
$st = $pdo->prepare($sqlSerie); $st->execute($params); $serie = $st->fetchAll();

/* —— Top instituciones —— */
$sqlInst = 'SELECT "INSTITUCION",
                   COUNT(*) AS cnt,
                   COALESCE(SUM("MONTO_ADJU_LINEA_CRC"),0) AS total_crc
            FROM public."Procedimientos Adjudicados"
            '.$whereSql.'
            GROUP BY "INSTITUCION"
            ORDER BY total_crc DESC NULLS LAST, cnt DESC
            LIMIT 15';
$st = $pdo->prepare($sqlInst); $st->execute($params); $topInst = $st->fetchAll();

/* —— Top proveedores —— */
$sqlProv = 'SELECT "NOMBRE_PROVEEDOR",
                   COUNT(*) AS cnt,
                   COALESCE(SUM("MONTO_ADJU_LINEA_CRC"),0) AS total_crc
            FROM public."Procedimientos Adjudicados"
            '.$whereSql.'
            GROUP BY "NOMBRE_PROVEEDOR"
            ORDER BY total_crc DESC NULLS LAST, cnt DESC
            LIMIT 15';
$st = $pdo->prepare($sqlProv); $st->execute($params); $topProv = $st->fetchAll();

/* —— Detalle (primeros N) —— */
$sqlDet = 'SELECT
             "Mes de Descarga","INSTITUCION","NOMBRE_PROVEEDOR","PROD_ID",
             "DESCR_BIEN_SERVICIO","MONTO_ADJU_LINEA_CRC","MONTO_ADJU_LINEA_USD","MONTO_UNITARIO"
           FROM public."Procedimientos Adjudicados"
           '.$whereSql.'
           ORDER BY
             COALESCE(NULLIF(regexp_replace("Mes de Descarga",\'[^0-9]\',\'\',\'g\'),\'\')::int, 999),
             "INSTITUCION" ASC
           LIMIT :lim OFFSET :off';
$st = $pdo->prepare($sqlDet);
foreach ($params as $k=>$v) $st->bindValue($k, $v);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$detalle = $st->fetchAll();

/* —— Responder —— */
echo json_encode([
  'ok'=>true,
  'totales'=>$totales,
  'serie_mes'=>$serie,
  'top_inst'=>$topInst,
  'top_prov'=>$topProv,
  'detalle'=>$detalle
], JSON_UNESCAPED_UNICODE);
