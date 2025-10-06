<?php
// licitacion_actualizar.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Método no permitido (POST)'], JSON_UNESCAPED_UNICODE);
  exit;
}
function jexit($ok, $extra=[]){ http_response_code($ok?200:400); echo json_encode(array_merge(['ok'=>$ok],$extra), JSON_UNESCAPED_UNICODE); exit; }

// Body JSON o form-data
$raw = file_get_contents('php://input');
$in = $raw ? json_decode($raw, true) : null;
if (!is_array($in)) { $in = $_POST; }

$id = isset($in['id']) ? (int)$in['id'] : 0;
if ($id <= 0) jexit(false, ['error'=>'Falta id']);

$allowedEnums = [
  'estado'    => ['en proceso','finalizada'],
  'respuesta' => ['pendiente','perdida','ganada'],
  'tipo'      => ['cantidad definida','demanda'],
];

$updates = [];
$params  = [':id'=>$id];

foreach (['estado','respuesta','tipo'] as $k) {
  if (array_key_exists($k, $in) && $in[$k] !== null && $in[$k] !== '') {
    $v = trim((string)$in[$k]);
    if (!in_array($v, $allowedEnums[$k], true)) jexit(false, ['error'=>"Valor inválido para $k"]);
    $updates[] = "$k = :$k";
    $params[":$k"] = $v;
  }
}
if (array_key_exists('ultima_observacion', $in)) {
  $v = trim((string)$in['ultima_observacion']);
  // permitir vacío (NULL si vacío)
  if ($v === '') { $updates[] = "ultima_observacion = NULL"; }
  else { $updates[] = "ultima_observacion = :ultima"; $params[':ultima'] = $v; }
}

if (!$updates) jexit(false, ['error'=>'No hay campos para actualizar']);

$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL, 'postgres') === false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try{
  $p = parse_url($DATABASE_URL);
  $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require', $p['host'], $p['port'] ?? 5432, ltrim($p['path'],'/'));
  $pdo = new PDO($dsn, $p['user'], $p['pass'], [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION ]);

  $sql = "UPDATE public.licitaciones
          SET ".implode(', ',$updates).", actualizado_en = now()
          WHERE id = :id
          RETURNING id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rid = $stmt->fetchColumn();
  if (!$rid) jexit(false, ['error'=>'ID no encontrado']);
  jexit(true, ['id'=>(int)$rid]);

} catch (PDOException $e) {
  jexit(false, ['error'=>'DB: '.$e->getMessage()]);
} catch (Throwable $e) {
  jexit(false, ['error'=>'Error: '.$e->getMessage()]);
}
