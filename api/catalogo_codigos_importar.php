<?php
// api/catalogo_codigos_importar.php
declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$CLIENT_IP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

/* ==============================
   CORS + JSON-safe fatal handler
   ============================== */
if ($method === 'OPTIONS') { http_response_code(204); exit; }

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

ini_set('display_errors', '0');
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

/* ==============================
   DB
   ============================== */
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
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error de conexión: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==============================
   Bootstrap de tablas
   ============================== */
$pdo->exec('CREATE TABLE IF NOT EXISTS public."Catalogo Codigos" (
  "Tipo de Clasificación"         TEXT NOT NULL,
  "Codigo de Clasificación"       TEXT NOT NULL,
  "Descripción de Clasificación"  TEXT,
  import_id UUID,
  created_at TIMESTAMPTZ DEFAULT now(),
  updated_at TIMESTAMPTZ DEFAULT now(),
  PRIMARY KEY ("Tipo de Clasificación","Codigo de Clasificación")
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS public.catalogo_codigos_import_log (
  import_id  UUID PRIMARY KEY,
  filename   TEXT,
  total_rows INTEGER,
  inserted   INTEGER,
  skipped    INTEGER,
  started_at TIMESTAMPTZ DEFAULT now(),
  finished_at TIMESTAMPTZ,
  anulado_at TIMESTAMPTZ,
  source_ip  TEXT
)');

$pdo->exec('ALTER TABLE public."Catalogo Codigos"
  ALTER COLUMN "Tipo de Clasificación"   TYPE TEXT,
  ALTER COLUMN "Codigo de Clasificación" TYPE TEXT');

$pdo->exec('ALTER TABLE public."Catalogo Codigos"
  ADD COLUMN IF NOT EXISTS import_id UUID,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ DEFAULT now(),
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT now()');

/* ==============================
   Helpers de texto/headers
   ============================== */
function to_utf8($s): string {
  $s = (string)($s ?? '');
  if ($s === '') return '';
  if (function_exists('mb_detect_encoding') && mb_detect_encoding($s, 'UTF-8', true)) return $s;
  foreach (['Windows-1252','ISO-8859-1','ISO-8859-15'] as $enc) {
    $conv = @mb_convert_encoding($s, 'UTF-8', $enc);
    if ($conv !== false && $conv !== '') return $conv;
  }
  return $s;
}
function u_upper(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s,'UTF-8') : strtoupper($s); }
function norm_header($s): string {
  $s = to_utf8($s);
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = str_replace(["\xC2\xA0","\xE2\x80\x8B","\xE2\x80\x8C","\xE2\x80\x8D"], ' ', $s);
  $s = trim($s);
  $tmp = preg_replace('/\s+/u', ' ', $s);
  if ($tmp === null) $tmp = preg_replace('/\s+/', ' ', $s);
  return $tmp ?? '';
}
function strip_accents($s): string {
  $s = to_utf8($s);
  $from='ÁáÉéÍíÓóÚúÜüÑñ'; $to='AaEeIiOoUuUuNn';
  return strtr($s,$from,$to);
}
function label_key($s): string {
  $s = norm_header($s);
  $s = strip_accents($s);
  $s = u_upper($s);
  $tmp = preg_replace('/[^A-Z0-9]+/u', ' ', $s); if ($tmp===null) $tmp=preg_replace('/[^A-Z0-9]+/', ' ', $s);
  $s = $tmp ?? '';
  $tmp = preg_replace('/\s+/u', ' ', trim($s)); if ($tmp===null) $tmp=preg_replace('/\s+/', ' ', trim($s));
  $s = $tmp ?? '';
  $tokens = array_values(array_filter(explode(' ', $s), function($t){
    static $stop=['DE','DEL','EL','LA','LOS','LAS','OF','THE']; return $t!=='' && !in_array($t,$stop,true);
  }));
  return implode(' ', $tokens);
}
function new_uuid(): string {
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/* ==============================
   Endpoints auxiliares (logs/anular)
   ============================== */
$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

if ($method === 'GET' && $accion === 'logs') {
  header('Content-Type: application/json; charset=utf-8');
  $rows = $pdo->query('SELECT import_id, filename, total_rows, inserted, skipped,
                              started_at, finished_at, anulado_at
                         FROM public.catalogo_codigos_import_log
                        ORDER BY started_at DESC
                        LIMIT 100')->fetchAll();
  echo json_encode(['ok'=>true,'logs'=>$rows], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method === 'POST' && $accion === 'anular') {
  header('Content-Type: application/json; charset=utf-8');
  $import_id = $_POST['import_id'] ?? '';
  if (!preg_match('/^[0-9a-fA-F-]{36}$/', $import_id)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'import_id inválido'], JSON_UNESCAPED_UNICODE); exit;
  }
  $pdo->beginTransaction();
  try {
    // borrar filas de esa importación
    $del = $pdo->prepare('DELETE FROM public."Catalogo Codigos" WHERE import_id = :id');
    $del->execute([':id'=>$import_id]);
    $deleted = $del->rowCount();

    // marcar log como anulado
    $up = $pdo->prepare('UPDATE public.catalogo_codigos_import_log
                            SET anulado_at = now()
                          WHERE import_id = :id AND anulado_at IS NULL');
    $up->execute([':id'=>$import_id]);

    $pdo->commit();
    echo json_encode(['ok'=>true,'deleted'=>$deleted], JSON_UNESCAPED_UNICODE);
  } catch(Throwable $e){
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* ==============================
   UI (GET)
   ============================== */
if ($method === 'GET') {
  header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Catálogo de Códigos — Importar</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#0b0f24;color:#eaf2ff}
  .card{background:#fff;border-radius:16px;color:#0e1b2a}
  .badge-soft{background:#eef4ff;color:#355}
</style>
</head>
<body class="p-3 p-lg-5">
<div class="container" style="max-width:980px">
  <div class="mb-4">
    <h1 class="h4 m-0">Importar “Catálogo de Códigos”</h1>
    <div class="text-white-50 small">CSV/TSV/TXT con encabezados: <code>Tipo de Clasificación, Codigo de Clasificación, Descripción de Clasificación</code></div>
  </div>

  <div class="card shadow-sm mb-4"><div class="card-body">
    <form id="frm" class="vstack gap-3" enctype="multipart/form-data">
      <div>
        <label class="form-label">Archivo (.csv, .tsv o .txt)</label>
        <input class="form-control" type="file" name="archivo" required accept=".csv,.tsv,.txt">
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-success" type="submit">Subir e importar</button>
        <button class="btn btn-outline-secondary" type="reset">Limpiar</button>
      </div>
      <div class="progress d-none" id="pgw" style="height:8px"><div class="progress-bar" id="bar" style="width:0%"></div></div>
      <div id="msg" class="small"></div>
      <pre id="raw" class="small d-none p-2 bg-light border rounded"></pre>
    </form>
  </div></div>

  <div class="card shadow-sm"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 m-0">Historial de importaciones</h2>
      <button class="btn btn-sm btn-outline-primary" id="btnReload">Actualizar</button>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr>
          <th>Fecha</th><th>Archivo</th><th class="text-end">Filas</th>
          <th class="text-end">Insert/Upd</th><th class="text-end">Saltadas</th>
          <th>Estado</th><th class="text-end">Acciones</th>
        </tr></thead>
        <tbody id="logs"><tr><td colspan="7" class="text-muted">Cargando…</td></tr></tbody>
      </table>
    </div>
  </div></div>
</div>

<script>
const ENDPOINT = location.pathname; // este mismo PHP
const $ = (s,d=document)=>d.querySelector(s);

function esc(s){ return (s??'').toString().replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])); }

async function loadLogs(){
  const r = await fetch(ENDPOINT+'?accion=logs');
  const j = await r.json();
  const tb = $('#logs');
  if(!j.ok){ tb.innerHTML = `<tr><td colspan="7" class="text-danger">Error: ${esc(j.error||'')}</td></tr>`; return; }
  if(!j.logs.length){ tb.innerHTML = `<tr><td colspan="7" class="text-muted">Sin importaciones aún.</td></tr>`; return; }
  tb.innerHTML = j.logs.map(row=>{
    const estado = row.anulado_at ? `<span class="badge bg-secondary">Anulado</span>` : `<span class="badge bg-success">Activo</span>`;
    const fecha  = new Date(row.started_at).toLocaleString();
    const btn = row.anulado_at
      ? `<button class="btn btn-sm btn-outline-secondary" disabled>Anulado</button>`
      : `<button class="btn btn-sm btn-outline-danger" data-id="${row.import_id}" onclick="anular(this)">Anular</button>`;
    return `<tr>
      <td>${esc(fecha)}</td>
      <td>${esc(row.filename||'')}</td>
      <td class="text-end">${row.total_rows ?? ''}</td>
      <td class="text-end">${row.inserted ?? ''}</td>
      <td class="text-end">${row.skipped ?? ''}</td>
      <td>${estado}</td>
      <td class="text-end">${btn}</td>
    </tr>`;
  }).join('');
}
async function anular(btn){
  const id = btn.getAttribute('data-id');
  if(!confirm('¿Anular esta importación? Se eliminarán sólo las filas asociadas a ese archivo.')) return;
  btn.disabled = true;
  const fd = new FormData(); fd.append('accion','anular'); fd.append('import_id', id);
  const r = await fetch(ENDPOINT, {method:'POST', body:fd});
  const j = await r.json();
  if(!j.ok) alert('Error: '+(j.error||''));
  await loadLogs();
}
$('#btnReload').addEventListener('click', loadLogs);

let controller=null, fakeTimer=null;
function setP(p){ $('#pgw').classList.remove('d-none'); $('#bar').style.width=p+'%'; }
function fake(){ let p=5; setP(p); fakeTimer=setInterval(()=>{ p=Math.min(p+Math.random()*4+1,85); setP(Math.round(p)); if(p>=85){clearInterval(fakeTimer);fakeTimer=null;} },350); }
function fakeStop(){ if(fakeTimer){clearInterval(fakeTimer); fakeTimer=null;} }

$('#frm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const file = e.target.archivo.files[0];
  if(!file){ $('#msg').textContent='Seleccione un archivo.'; return; }
  const fd = new FormData(); fd.append('archivo', file);
  controller = new AbortController();
  $('#msg').textContent='Subiendo…'; $('#raw').classList.add('d-none'); fake();
  try{
    const r = await fetch(ENDPOINT, {method:'POST', body:fd, signal: controller.signal});
    fakeStop(); setP(100);
    const raw = await r.text();
    let j; try{ j=JSON.parse(raw); } catch{ j={ok:false,error:raw}; }
    if(!r.ok || !j.ok){
      $('#msg').innerHTML='⚠ '+esc(j.error||'Error'); $('#raw').classList.remove('d-none'); $('#raw').textContent=raw; return;
    }
    $('#msg').textContent = `✅ Listo. Insert/Upd: ${j.insertados} — Saltados: ${j.saltados}`;
    loadLogs();
  }catch(err){
    fakeStop(); $('#msg').textContent='⚠ '+(err.message||String(err));
  }finally{ controller=null; }
});

loadLogs();
</script>
</body></html>
<?php exit; }

/* ==============================
   A partir de aquí: POST import
   ============================== */
header('Content-Type: application/json; charset=utf-8');

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido (campo "archivo")'], JSON_UNESCAPED_UNICODE);
  exit;
}

$name = $_FILES['archivo']['name'] ?? '';
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','txt','tsv'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Formato no permitido. Use .csv, .tsv o .txt'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$fh  = fopen($tmp, 'r');
if (!$fh) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo abrir el archivo'], JSON_UNESCAPED_UNICODE); exit; }

/* Detectar delimitador */
$firstLineRaw = fgets($fh);
if ($firstLineRaw === false) { fclose($fh); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo vacío'], JSON_UNESCAPED_UNICODE); exit; }
rewind($fh);
$cands = [","=>substr_count($firstLineRaw,","), ";"=>substr_count($firstLineRaw,";"), "\t"=>substr_count($firstLineRaw,"\t"), "|"=>substr_count($firstLineRaw,"|")];
arsort($cands); $delimiter = array_key_first($cands) ?: ","; if (($cands[$delimiter] ?? 0) === 0) $delimiter = ",";

/* Leer encabezados */
$header = fgetcsv($fh, 0, $delimiter);
if ($header === false || $header === null) { fclose($fh); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo leer encabezados'], JSON_UNESCAPED_UNICODE); exit; }
$header = array_map('norm_header', (array)$header);
$header_norm_join = implode('|', array_map('label_key', $header));

/* Canon + mapeo */
$canon = ['Tipo de Clasificación','Codigo de Clasificación','Descripción de Clasificación'];
$ph = ['Tipo de Clasificación'=>'c_tipo', 'Codigo de Clasificación'=>'c_codigo', 'Descripción de Clasificación'=>'c_desc'];
$canonByKey = [
  label_key('Tipo de Clasificación')=>'Tipo de Clasificación',
  label_key('Codigo de Clasificación')=>'Codigo de Clasificación',
  label_key('Código de Clasificación')=>'Codigo de Clasificación',
  label_key('Descripción de Clasificación')=>'Descripción de Clasificación',
  label_key('Descripcion de Clasificacion')=>'Descripción de Clasificación'
];

$map = []; $canon_set = array_fill_keys($canon, false); $en_archivo=[];
for ($i=0; $i<count($header); $i++) {
  $h = $header[$i]; if ($h==='') continue;
  $key = label_key($h);
  $hCanon = $canonByKey[$key] ?? null;
  if ($hCanon !== null) { $map[$i]=$hCanon; $canon_set[$hCanon]=true; }
  $en_archivo[]=$h;
}
$faltan=[]; foreach ($canon_set as $c=>$ok) if(!$ok) $faltan[]=$c;
if ($faltan) {
  fclose($fh);
  echo json_encode(['ok'=>false,'error'=>'Encabezados faltantes','faltan'=>$faltan,'en_archivo'=>$en_archivo], JSON_UNESCAPED_UNICODE);
  exit;
}

/* Prep UPSERT */
$sql = 'INSERT INTO public."Catalogo Codigos"
        ("Tipo de Clasificación","Codigo de Clasificación","Descripción de Clasificación", import_id, created_at, updated_at)
        VALUES (:c_tipo,:c_codigo,:c_desc, :import_id, now(), now())
        ON CONFLICT ("Tipo de Clasificación","Codigo de Clasificación")
        DO UPDATE SET
          "Descripción de Clasificación" = EXCLUDED."Descripción de Clasificación",
          import_id = EXCLUDED.import_id,
          updated_at = now()';
$stmt = $pdo->prepare($sql);

/* Insertar LOG (inicio) */
$import_id = new_uuid();
$logIns = $pdo->prepare('INSERT INTO public.catalogo_codigos_import_log(import_id,filename,total_rows,inserted,skipped,started_at,source_ip)
                         VALUES(:id,:fn,0,0,0,now(),:ip)');
$logUpd = $pdo->prepare('UPDATE public.catalogo_codigos_import_log
                            SET total_rows=:t, inserted=:i, skipped=:s, finished_at=now()
                          WHERE import_id=:id');
$logIns->execute([':id'=>$import_id, ':fn'=>$name, ':ip'=>$CLIENT_IP]);

/* Import */
$insertados=0; $saltados=0; $total=0; $linea=1;
$seen=[];

$pdo->beginTransaction();
try {
  while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $linea++; $total++;

    // saltar vacías
    $allEmpty=true; foreach($row as $cell){ if(trim((string)$cell)!==''){ $allEmpty=false; break; } }
    if ($allEmpty){ $saltados++; continue; }

    // saltar si son encabezados repetidos
    $row_norm_join = implode('|', array_map('label_key', array_map('strval', $row)));
    if ($row_norm_join === $header_norm_join) { $saltados++; continue; }

    // obtener valores
    $vals = [];
    foreach($map as $idx=>$cname){ $vals[$cname] = $row[$idx] ?? null; }

    // normalizar (texto plano)
    $tipo = trim((string)($vals['Tipo de Clasificación'] ?? ''));
    $codigo = trim((string)($vals['Codigo de Clasificación'] ?? ''));
    $descr  = trim((string)($vals['Descripción de Clasificación'] ?? ''));

    // desduplicación dentro del mismo archivo
    $finger = sha1($tipo.'|'.$codigo.'|'.$descr);
    if (isset($seen[$finger])) { $saltados++; continue; }
    $seen[$finger]=true;

    if ($tipo==='' || $codigo===''){ $saltados++; continue; }

    // UPSERT
    $stmt->execute([
      ':c_tipo'=>$tipo,
      ':c_codigo'=>$codigo,
      ':c_desc'=>$descr===''?null:$descr,
      ':import_id'=>$import_id
    ]);
    $insertados += $stmt->rowCount(); // cuenta insert o update
  }
  $pdo->commit();
} catch(Throwable $e){
  $pdo->rollBack();
  fclose($fh);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
fclose($fh);

/* Actualizar log y responder */
$logUpd->execute([':t'=>$total, ':i'=>$insertados, ':s'=>$saltados, ':id'=>$import_id]);
$debug = ob_get_contents(); ob_end_clean();
echo json_encode([
  'ok'=>true,
  'import_id'=>$import_id,
  'insertados'=>$insertados,
  'saltados'=>$saltados,
  'total'=>$total,
  'debug'=>$debug?trim(strip_tags($debug)):null
], JSON_UNESCAPED_UNICODE);
