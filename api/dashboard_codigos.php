<?php
// api/dashboard_codigos.php
declare(strict_types=1);

@ini_set('memory_limit','512M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') { http_response_code(204); exit; }
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors','0');
ob_start();
set_error_handler(function($sev,$msg,$file,$line){ throw new ErrorException($msg,0,$sev,$file,$line); });
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    $out = ob_get_clean();
    echo json_encode(['ok'=>false,'error'=>'Fallo fatal: '.$e['message'].' @ '.$e['file'].':'.$e['line'],'debug'=>$out?:null], JSON_UNESCAPED_UNICODE);
  }
});

/* ========== DB ========== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try{
  $p = parse_url($DATABASE_URL);
  if(!$p||!isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo = new PDO($dsn,$p['user'],$p['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true
  ]);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ========== Acciones ========== */
$accion = $_GET['accion'] ?? 'list';

/* --- accion=list: obtener catálogo para tipos --- */
if ($accion === 'list') {
  $tiposParam = $_GET['tipos'] ?? ''; // "Asesoría,Imprenta" etc.
  $limit = max(1, min(5000, (int)($_GET['limit'] ?? 2000)));

  $tipos = array_filter(array_map('trim', explode(',', $tiposParam)));
  if (!$tipos) { $tipos = ['Asesoría','Imprenta']; }

  // Tomamos catálogo y conteo de uso (usage_count) desde Procedimientos Adjudicados por codigo_madre
  $sql = '
    WITH cat AS (
      SELECT "Tipo de Clasificación" AS tipo,
             "Codigo de Clasificación" AS codigo,
             coalesce("Descripción de Clasificación","") AS descripcion
      FROM public."Catalogo Codigos"
      WHERE "Tipo de Clasificación" = ANY(:tipos)
    ),
    uso AS (
      SELECT coalesce(p.codigo_madre, substring(p."PROD_ID" FOR 8)) AS codigo_madre,
             COUNT(*)::int AS usage_count
      FROM public."Procedimientos Adjudicados" p
      WHERE coalesce(p.codigo_madre, substring(p."PROD_ID" FOR 8)) IS NOT NULL
      GROUP BY 1
    )
    SELECT c.tipo AS "Tipo de Clasificación",
           c.codigo AS "Codigo de Clasificación",
           c.descripcion AS "Descripción de Clasificación",
           coalesce(u.usage_count,0) AS usage_count
    FROM cat c
    LEFT JOIN uso u ON u.codigo_madre = c.codigo
    ORDER BY c.tipo, c.codigo
    LIMIT :lim
  ';
  $st = $pdo->prepare($sql);
  // array parameter: usar pgsql array literal
  $pgArray = '{'.implode(',', array_map(fn($t)=>'"'.str_replace('"','\"',$t).'"', $tipos)).'}';
  $st->bindValue(':tipos', $pgArray, PDO::PARAM_STR);
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->execute();
  $items = $st->fetchAll();

  $debug = ob_get_contents(); ob_end_clean();
  echo json_encode(['ok'=>true,'items'=>$items,'debug'=>$debug?:null], JSON_UNESCAPED_UNICODE);
  exit;
}

/* --- accion=insprov: instituciones y proveedores por codigo madre --- */
if ($accion === 'insprov') {
  $codigo = $_GET['codigo'] ?? '';
  $codigo = trim($codigo);

  // Validar: 8 dígitos
  if (!preg_match('/^\d{8}$/', $codigo)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Código madre inválido (se esperan 8 dígitos)'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Instituciones
  $sqlI = '
    SELECT p."INSTITUCION" AS institucion,
           COUNT(*)::int AS total
    FROM public."Procedimientos Adjudicados" p
    WHERE COALESCE(p.codigo_madre, substring(p."PROD_ID" FOR 8)) = :cod
      AND p."INSTITUCION" IS NOT NULL AND p."INSTITUCION" <> \'\'
    GROUP BY 1
    ORDER BY total DESC, institucion
    LIMIT 500
  ';
  $sti = $pdo->prepare($sqlI);
  $sti->execute([':cod'=>$codigo]);
  $instituciones = $sti->fetchAll();

  // Proveedores
  $sqlP = '
    SELECT p."NOMBRE_PROVEEDOR" AS proveedor,
           p."CEDULA_PROVEEDOR" AS cedula,
           COUNT(*)::int AS total
    FROM public."Procedimientos Adjudicados" p
    WHERE COALESCE(p.codigo_madre, substring(p."PROD_ID" FOR 8)) = :cod
      AND p."NOMBRE_PROVEEDOR" IS NOT NULL AND p."NOMBRE_PROVEEDOR" <> \'\'
    GROUP BY 1,2
    ORDER BY total DESC, proveedor
    LIMIT 500
  ';
  $stp = $pdo->prepare($sqlP);
  $stp->execute([':cod'=>$codigo]);
  $proveedores = $stp->fetchAll();

  $debug = ob_get_contents(); ob_end_clean();
  echo json_encode(['ok'=>true,'codigo'=>$codigo,'instituciones'=>$instituciones,'proveedores'=>$proveedores,'debug'=>$debug?:null], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Accion desconocida */
http_response_code(400);
$debug = ob_get_contents(); ob_end_clean();
echo json_encode(['ok'=>false,'error'=>'Acción no soportada','debug'=>$debug?:null], JSON_UNESCAPED_UNICODE);
