<?php
// api/dashboard_codigos.php
declare(strict_types=1);

@ini_set('memory_limit','512M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ===== CORS + fatal-safe JSON ===== */
if ($method === 'OPTIONS') { http_response_code(204); exit; }
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ini_set('display_errors','0');
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

/* ===== DB ===== */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try{
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>true
  ]);
  $pdo->exec("SET TIME ZONE 'UTC'");
} catch(Throwable $e){
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== Helpers ===== */
function j($x){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }

/* ¿Existe CODIGO_MADRE en PA? */
$hasCodMadre = false;
try{
  $chk = $pdo->prepare("SELECT 1
                          FROM information_schema.columns
                         WHERE table_schema='public'
                           AND table_name='Procedimientos Adjudicados'
                           AND column_name='CODIGO_MADRE'
                         LIMIT 1");
  $chk->execute();
  $hasCodMadre = (bool)$chk->fetchColumn();
}catch(Throwable $e){ /* noop */ }
$COD_EXPR = $hasCodMadre ? "\"CODIGO_MADRE\"" : "LEFT(COALESCE(\"PROD_ID\"::text,''),8)";

/* ===== Rutas ===== */
$accion = $_GET['accion'] ?? 'catalogo';

/* ---- 1) Catálogo (filtrado Asesoría / Imprenta) ----
   ?accion=catalogo           -> filtrado (asesor / imprent)
   ?accion=catalogo&todo=1    -> sin filtro (todo el catálogo)
*/
if ($accion === 'catalogo') {
  $todo = isset($_GET['todo']) && $_GET['todo']=='1';

  if ($todo) {
    $sql = "
      SELECT
        c.\"Codigo de Clasificación\"      AS codigo,
        c.\"Descripción de Clasificación\" AS descripcion,
        c.\"Tipo de Clasificación\"        AS tipo,
        COALESCE((
          SELECT COUNT(*) FROM public.\"Procedimientos Adjudicados\" p
           WHERE TRIM($COD_EXPR) = TRIM(c.\"Codigo de Clasificación\")
        ),0) AS total_pa
      FROM public.\"Catalogo Codigos\" c
      ORDER BY c.\"Codigo de Clasificación\" ASC
      LIMIT 2000";
    $rows = $pdo->query($sql)->fetchAll();
    j(['ok'=>true,'rows'=>$rows]);
  } else {
    $sql = "
      SELECT
        c.\"Codigo de Clasificación\"      AS codigo,
        c.\"Descripción de Clasificación\" AS descripcion,
        c.\"Tipo de Clasificación\"        AS tipo,
        COALESCE((
          SELECT COUNT(*) FROM public.\"Procedimientos Adjudicados\" p
           WHERE TRIM($COD_EXPR) = TRIM(c.\"Codigo de Clasificación\")
        ),0) AS total_pa
      FROM public.\"Catalogo Codigos\" c
      WHERE c.\"Descripción de Clasificación\" ILIKE :asesor
         OR c.\"Descripción de Clasificación\" ILIKE :imprent
      ORDER BY c.\"Codigo de Clasificación\" ASC
      LIMIT 1000";
    $st = $pdo->prepare($sql);
    $st->execute([':asesor'=>'%asesor%',':imprent'=>'%imprent%']);
    $rows = $st->fetchAll();
    j(['ok'=>true,'rows'=>$rows]);
  }
}

/* ---- 2) Estadísticas por código madre ----
Parámetros: ?accion=stats&codigo=XXXXXXXX
*/
if ($accion === 'stats') {
  $codigo = $_GET['codigo'] ?? '';
  if (!preg_match('/^[0-9A-Za-z]{1,12}$/', $codigo)) {
    http_response_code(400);
    j(['ok'=>false,'error'=>'Código inválido']);
  }

  // SUMMARY
  $sql = "
    WITH base AS (
      SELECT * FROM public.\"Procedimientos Adjudicados\"
       WHERE TRIM($COD_EXPR) = :cod
    )
    SELECT
      COUNT(*)                                        AS total_rows,
      SUM(COALESCE(\"CANTIDAD\",0))                   AS total_qty,
      SUM(COALESCE(\"MONTO_ADJU_LINEA_CRC\",0))       AS total_crc,
      SUM(COALESCE(\"MONTO_ADJU_LINEA_USD\",0))       AS total_usd,
      COUNT(DISTINCT \"NOMBRE_PROVEEDOR\")            AS proveedores,
      COUNT(DISTINCT \"INSTITUCION\")                 AS instituciones
    FROM base";
  $st = $pdo->prepare($sql); $st->execute([':cod'=>$codigo]); $summary = $st->fetch() ?: [];

  // TOP PROVEEDORES
  $sql = "
    WITH base AS (
      SELECT * FROM public.\"Procedimientos Adjudicados\"
       WHERE TRIM($COD_EXPR) = :cod
    )
    SELECT
      COALESCE(\"NOMBRE_PROVEEDOR\",'(Sin proveedor)') AS proveedor,
      COUNT(*)                                        AS n,
      SUM(COALESCE(\"MONTO_ADJU_LINEA_CRC\",0))       AS crc,
      SUM(COALESCE(\"MONTO_ADJU_LINEA_USD\",0))       AS usd
    FROM base
    GROUP BY 1
    ORDER BY crc DESC NULLS LAST, n DESC
    LIMIT 15";
  $st = $pdo->prepare($sql); $st->execute([':cod'=>$codigo]); $topProv = $st->fetchAll();

  // TOP INSTITUCIONES
  $sql = "
    WITH base AS (
      SELECT * FROM public.\"Procedimientos Adjudicados\"
       WHERE TRIM($COD_EXPR) = :cod
    )
    SELECT
      COALESCE(\"INSTITUCION\",'(Sin institución)')    AS institucion,
      COUNT(*)                                         AS n,
      SUM(COALESCE(\"MONTO_ADJU_LINEA_CRC\",0))        AS crc,
      SUM(COALESCE(\"MONTO_ADJU_LINEA_USD\",0))        AS usd
    FROM base
    GROUP BY 1
    ORDER BY crc DESC NULLS LAST, n DESC
    LIMIT 15";
  $st = $pdo->prepare($sql); $st->execute([':cod'=>$codigo]); $topInst = $st->fetchAll();

  // SERIE por Mes de Descarga
  $sql = "
    WITH base AS (
      SELECT * FROM public.\"Procedimientos Adjudicados\"
       WHERE TRIM($COD_EXPR) = :cod
    ),
    s AS (
      SELECT
        COALESCE(\"Mes de Descarga\", '(Sin mes)') AS mes,
        SUM(COALESCE(\"MONTO_ADJU_LINEA_CRC\",0))  AS crc,
        SUM(COALESCE(\"MONTO_ADJU_LINEA_USD\",0))  AS usd,
        COUNT(*)                                   AS n,
        MIN(COALESCE(\"FECHA_ADJUD_FIRME\",\"fecha_rev\")) AS first_dt
      FROM base
      GROUP BY 1
    )
    SELECT mes, crc, usd, n
      FROM s
     ORDER BY first_dt NULLS LAST, mes ASC";
  $st = $pdo->prepare($sql); $st->execute([':cod'=>$codigo]); $serie = $st->fetchAll();

  // RECIENTES
  $sql = "
    WITH base AS (
      SELECT * FROM public.\"Procedimientos Adjudicados\"
       WHERE TRIM($COD_EXPR) = :cod
    )
    SELECT
      \"Mes de Descarga\"          AS mes_descarga,
      \"INSTITUCION\"              AS institucion,
      \"NOMBRE_PROVEEDOR\"         AS proveedor,
      \"DESCR_BIEN_SERVICIO\"      AS descripcion,
      \"CANTIDAD\"                 AS cantidad,
      \"MONTO_ADJU_LINEA_CRC\"     AS monto_crc,
      \"MONTO_ADJU_LINEA_USD\"     AS monto_usd,
      \"PROD_ID\"                  AS prod_id
    FROM base
    ORDER BY COALESCE(\"FECHA_ADJUD_FIRME\",\"fecha_rev\") DESC NULLS LAST, \"Mes de Descarga\" DESC
    LIMIT 40";
  $st = $pdo->prepare($sql); $st->execute([':cod'=>$codigo]); $recent = $st->fetchAll();

  j([
    'ok'=>true,
    'codigo'=>$codigo,
    'summary'=>$summary,
    'top_proveedores'=>$topProv,
    'top_instituciones'=>$topInst,
    'serie'=>$serie,
    'recientes'=>$recent
  ]);
}

/* fallback */
http_response_code(404);
j(['ok'=>false,'error'=>'Acción no encontrada']);
