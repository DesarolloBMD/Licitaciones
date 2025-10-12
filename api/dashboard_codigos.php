<?php
// api/dashboard_codigos.php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ============ CORS + fatal JSON-safe ============ */
if ($method === 'OPTIONS') { 
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  http_response_code(204);
  exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

/* ============ Conexión a Postgres ============ */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p = parse_url($DATABASE_URL);
  if (!$p || !isset($p['host'],$p['user'],$p['pass'],$p['path'])) throw new RuntimeException('DATABASE_URL inválida');
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $p['host'], $p['port']??5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
  ]);
} catch (Throwable $e) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============ Utilidad: expresión de código madre ============ */
function getCodigoMadreExpr(PDO $pdo): string {
  try {
    $q = $pdo->prepare("SELECT 1
                          FROM information_schema.columns
                         WHERE table_schema='public'
                           AND table_name='Procedimientos Adjudicados'
                           AND column_name='codigo_madre'
                         LIMIT 1");
    $q->execute();
    if ($q->fetch()) {
      return 'p."codigo_madre"';
    }
  } catch(Throwable $e) { /* ignore */ }
  // Fallback seguro (evita LEFT(bigint,…)):
  return "LEFT(CAST(p.\"PROD_ID\" AS TEXT), 8)";
}

/* ============ Parámetros ============ */
$accion = $_GET['accion'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

try {
  if ($accion === 'list') {
    // tipos: "Asesoría", "Imprenta" (puede venir uno o varios separados por coma)
    $tipos = $_GET['tipos'] ?? '';
    $tiposArr = array_values(array_filter(array_map('trim', explode(',', $tipos)), fn($s)=>$s!==''));
    if (!$tiposArr) {
      // Por compatibilidad, por defecto mostramos ambos
      $tiposArr = ['Asesoría','Imprenta'];
    }

    $limit  = isset($_GET['limit'])  ? max(1, min(5000, (int)$_GET['limit']))  : 2000;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset'])            : 0;

    $cmExpr = getCodigoMadreExpr($pdo);

    // Construimos placeholders dinámicos para IN (...)
    $ph = [];
    $bind = [];
    foreach ($tiposArr as $i => $t) {
      $k = ':t'.$i;
      $ph[] = $k;
      $bind[$k] = $t;
    }

    $sql = '
      SELECT
        c."Tipo de Clasificación",
        c."Codigo de Clasificación",
        COALESCE(c."Descripción de Clasificación", \'\') AS "Descripción de Clasificación",
        COUNT(p.*)::int AS usage_count
      FROM public."Catalogo Codigos" c
      LEFT JOIN public."Procedimientos Adjudicados" p
        ON '.$cmExpr.' = c."Codigo de Clasificación"
      WHERE c."Tipo de Clasificación" IN ('.implode(',', $ph).')
      GROUP BY 1,2,3
      ORDER BY c."Codigo de Clasificación" ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    foreach ($bind as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    $debug = ob_get_contents(); ob_end_clean();
    echo json_encode(['ok'=>true,'items'=>$items,'debug'=>$debug?trim(strip_tags($debug)):null], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($accion === 'insprov') {
    $codigo = trim((string)($_GET['codigo'] ?? ''));
    if ($codigo === '') {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'Parámetro "codigo" requerido'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $cmExpr = getCodigoMadreExpr($pdo);

    // Instituciones
    $sqlIns = '
      SELECT "INSTITUCION" AS institucion, COUNT(*)::int AS total
      FROM public."Procedimientos Adjudicados" p
      WHERE '.$cmExpr.' = :codigo
      GROUP BY 1
      ORDER BY total DESC, 1 ASC
      LIMIT 500
    ';
    $stIns = $pdo->prepare($sqlIns);
    $stIns->execute([':codigo'=>$codigo]);
    $instituciones = $stIns->fetchAll();

    // Proveedores
    $sqlProv = '
      SELECT "NOMBRE_PROVEEDOR" AS proveedor, "CEDULA_PROVEEDOR" AS cedula, COUNT(*)::int AS total
      FROM public."Procedimientos Adjudicados" p
      WHERE '.$cmExpr.' = :codigo
      GROUP BY 1,2
      ORDER BY total DESC, 1 ASC
      LIMIT 500
    ';
    $stProv = $pdo->prepare($sqlProv);
    $stProv->execute([':codigo'=>$codigo]);
    $proveedores = $stProv->fetchAll();

    $debug = ob_get_contents(); ob_end_clean();
    echo json_encode([
      'ok'=>true,
      'codigo'=>$codigo,
      'instituciones'=>$instituciones,
      'proveedores'=>$proveedores,
      'debug'=>$debug?trim(strip_tags($debug)):null
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Acción no soportada
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Acción no soportada'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  $debug = ob_get_contents(); ob_end_clean();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'debug'=>$debug?trim(strip_tags($debug)):null], JSON_UNESCAPED_UNICODE);
}
