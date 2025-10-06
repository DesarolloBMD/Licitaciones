<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Usa POST']); exit; }

function jexit(bool $ok, array $extra=[]): void {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) jexit(false, ['error'=>'ID inválido']);

$estado    = isset($_POST['estado'])    ? trim((string)$_POST['estado'])    : '';
$respuesta = isset($_POST['respuesta']) ? trim((string)$_POST['respuesta']) : '';
$tipo      = isset($_POST['tipo'])      ? trim((string)$_POST['tipo'])      : '';
$ultima    = isset($_POST['ultima_observacion']) ? trim((string)$_POST['ultima_observacion']) : null;

$validEstado    = ['en proceso','finalizada'];
$validRespuesta = ['pendiente','perdida','ganada'];
$validTipo      = ['cantidad definida','demanda'];

if (!in_array($estado,$validEstado,true))         jexit(false,['error'=>'estado inválido']);
if (!in_array($respuesta,$validRespuesta,true))   jexit(false,['error'=>'respuesta inválida']);
if (!in_array($tipo,$validTipo,true))             jexit(false,['error'=>'tipo inválido']);

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $parts = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
    $parts['host'], isset($parts['port'])?(int)$parts['port']:5432, ltrim($parts['path'],'/')
  );
  $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);

  $sql = "UPDATE public.licitaciones
          SET estado=:estado, respuesta=:respuesta, tipo=:tipo,
              ultima_observacion=:ultima, actualizado_en=now()
          WHERE id=:id";
  // Si última es cadena vacía => NULL
  $ultimaParam = ($ultima === null) ? null : ( ($ultima==='') ? null : $ultima );

  $st = $pdo->prepare($sql);
  $st->execute([
    ':estado'=>$estado, ':respuesta'=>$respuesta, ':tipo'=>$tipo,
    ':ultima'=>$ultimaParam, ':id'=>$id
  ]);
  if ($st->rowCount() < 1) jexit(false, ['error'=>'No existe el ID']);
  jexit(true, ['id'=>$id]);
} catch(Throwable $e){
  jexit(false, ['error'=>'DB: '.$e->getMessage()]);
}
