<?php
declare(strict_types=1);
@ini_set('memory_limit','1024M');
@set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ========= CONEXIÓN DB ========= */
$DATABASE_URL = getenv('DATABASE_URL');
if (!$DATABASE_URL || stripos($DATABASE_URL,'postgres')===false) {
  $DATABASE_URL = 'postgresql://licitaciones_bmd_user:vFgswY5U7MaSqqexdhjgAE5M9fBpT2OQ@dpg-d3g2v7j3fgac73c4eek0-a.oregon-postgres.render.com:5432/licitaciones_bmd?sslmode=require';
}
try {
  $p=parse_url($DATABASE_URL);
  $dsn=sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=require',$p['host'],$p['port']??5432,ltrim($p['path'],'/'));
  $pdo=new PDO($dsn,$p['user'],$p['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
} catch(Throwable $e){ http_response_code(500); die('Error conexión DB: '.$e->getMessage()); }

/* ========= FRONTEND HTML ========= */
if ($method==='GET' && !isset($_GET['accion'])):
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Importar Procedimientos Adjudicados</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f8fb;}
    .card{border-radius:16px;}
    .btn-brand{background:#0B4912;color:#fff;border:none;}
    .btn-brand:hover{filter:brightness(1.1);color:#fff;}
    pre{font-family:ui-monospace,monospace;font-size:.9rem;}
    .tbl-mini th,.tbl-mini td{vertical-align:middle;}
  </style>
</head>
<body class="p-3 p-lg-5">
<div class="container" style="max-width:960px">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h4 class="mb-3"><i class="bi bi-cloud-upload text-success me-2"></i>Importar “Procedimientos Adjudicados”</h4>
      <form id="frm" enctype="multipart/form-data" class="d-flex flex-column gap-3">
        <div>
          <label class="form-label">Archivo (.csv o .txt)</label>
          <input class="form-control" type="file" name="archivo" accept=".csv,.txt" required>
        </div>
        <div class="d-flex gap-2">
          <button id="btnSubir" class="btn btn-brand"><i class="bi bi-upload"></i> Subir e importar</button>
          <button id="btnCancelar" type="button" class="btn btn-outline-danger" disabled><i class="bi bi-x-circle"></i> Cancelar</button>
        </div>
        <div class="progress" style="height:8px;display:none" id="barWrap">
          <div class="progress-bar" id="bar" role="progressbar" style="width:0%"></div>
        </div>
        <pre id="log" class="p-2 bg-light border rounded" style="white-space:pre-wrap;max-height:200px;overflow:auto"></pre>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="mb-3"><i class="bi bi-clock-history me-2 text-secondary"></i>Historial de importaciones</h5>
      <div class="table-responsive">
        <table class="table table-sm align-middle tbl-mini" id="tblLogs">
          <thead>
            <tr><th>Archivo</th><th>Insertados</th><th>Saltados</th><th>Total</th><th>Fecha</th><th></th></tr>
          </thead>
          <tbody><tr><td colspan="6" class="text-center text-muted">Cargando...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const ENDPOINT=location.pathname;
const frm=document.getElementById('frm'),
      btnSubir=document.getElementById('btnSubir'),
      btnCancelar=document.getElementById('btnCancelar'),
      bar=document.getElementById('bar'),
      barWrap=document.getElementById('barWrap'),
      log=document.getElementById('log'),
      tbl=document.querySelector('#tblLogs tbody');
let controller=null;

frm.addEventListener('submit',async e=>{
  e.preventDefault();
  const fd=new FormData(frm);
  controller=new AbortController();
  btnSubir.disabled=true;btnCancelar.disabled=false;
  barWrap.style.display='block';bar.style.width='5%';
  log.textContent='Subiendo...';
  try{
    const r=await fetch(ENDPOINT,{method:'POST',body:fd,signal:controller.signal});
    bar.style.width='70%';
    const data=await r.json();
    if(!data.ok) throw new Error(data.error||'Error en importación');
    bar.style.width='100%';
    log.textContent=`✅ Importación completada
Insertados: ${data.insertados}
Saltados: ${data.saltados}
Total: ${data.total}`;
    cargarLogs();
  }catch(err){
    log.textContent='⚠ '+(err.name==='AbortError'?'Importación cancelada':err.message);
  }finally{
    btnSubir.disabled=false;btnCancelar.disabled=true;controller=null;
    setTimeout(()=>{barWrap.style.display='none';bar.style.width='0%';},600);
  }
});

btnCancelar.onclick=()=>{ if(controller) controller.abort(); };

async function cargarLogs(){
  tbl.innerHTML='<tr><td colspan="6" class="text-center text-muted">Cargando...</td></tr>';
  const r=await fetch(ENDPOINT+'?accion=logs');
  const data=await r.json();
  if(!data.ok){ tbl.innerHTML='<tr><td colspan="6" class="text-danger text-center">No se pudo cargar</td></tr>'; return; }
  if(!data.logs.length){ tbl.innerHTML='<tr><td colspan="6" class="text-center text-muted">Sin registros</td></tr>'; return; }
  tbl.innerHTML=data.logs.map(l=>`
    <tr>
      <td>${l.filename||'-'}</td>
      <td>${l.inserted||0}</td>
      <td>${l.skipped||0}</td>
      <td>${l.total_rows||0}</td>
      <td>${(l.started_at||'').replace('T',' ').split('.')[0]}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-outline-danger" onclick="anular('${l.import_id}')"><i class="bi bi-trash"></i></button>
      </td>
    </tr>`).join('');
}

async function anular(id){
  if(!confirm('¿Seguro que deseas eliminar esta importación?')) return;
  const r=await fetch(ENDPOINT+'?accion=anular&id='+encodeURIComponent(id));
  const data=await r.json();
  if(data.ok){ alert('Importación eliminada.'); cargarLogs(); }
  else alert('Error: '+data.error);
}

cargarLogs();
</script>
</body>
</html>
<?php exit; endif;

/* ========= API: HISTORIAL ========= */
if (isset($_GET['accion']) && $_GET['accion']==='logs') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $rows=$pdo->query('SELECT import_id,filename,total_rows,inserted,skipped,started_at,finished_at
                       FROM public.procedimientos_import_log ORDER BY started_at DESC LIMIT 100')->fetchAll();
    echo json_encode(['ok'=>true,'logs'=>$rows]);
  } catch(Throwable $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
  exit;
}

/* ========= API: ANULAR ========= */
if (isset($_GET['accion']) && $_GET['accion']==='anular' && isset($_GET['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  $id=$_GET['id'];
  try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM public."Procedimientos Adjudicados" WHERE import_id=:id')->execute([':id'=>$id]);
    $pdo->prepare('DELETE FROM public.procedimientos_import_log WHERE import_id=:id')->execute([':id'=>$id]);
    $pdo->commit();
    echo json_encode(['ok'=>true]);
  } catch(Throwable $e){ $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
  exit;
}

/* ========= IMPORTAR ========= */
if ($method!=='POST'){ echo json_encode(['ok'=>false,'error'=>'Método no permitido']); exit; }

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK){
  echo json_encode(['ok'=>false,'error'=>'Archivo no recibido']); exit;
}

$tmp=$_FILES['archivo']['tmp_name']; $name=$_FILES['archivo']['name'];
$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
if(!in_array($ext,['csv','txt'])){ echo json_encode(['ok'=>false,'error'=>'Formato no permitido']); exit; }

$fh=fopen($tmp,'r'); $first=fgets($fh); rewind($fh);
$delims=[","=>substr_count($first,","),";"=>substr_count($first,";"),"\t"=>substr_count($first,"\t")];
arsort($delims); $delim=array_key_first($delims) ?: ",";
$headers=fgetcsv($fh,0,$delim); if(!$headers){echo json_encode(['ok'=>false,'error'=>'Archivo vacío']);exit;}
$headers=array_map(fn($h)=>trim(preg_replace('/\s+/',' ',$h)),$headers);

$import_id=uniqid('imp_',true);
$ip=$_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??'';
$stmtLog=$pdo->prepare('INSERT INTO public.procedimientos_import_log(import_id,filename,total_rows,inserted,skipped,started_at,source_ip)
 VALUES(:id,:fn,0,0,0,now(),:ip)');
$stmtLog->execute([':id'=>$import_id,':fn'=>$name,':ip'=>$ip]);

$cols=array_map(fn($h)=>'"'.$h.'"',$headers);
$params=array_map(fn($h)=>':'.preg_replace('/\W+/','_',strtolower($h)),$headers);
$sql='INSERT INTO public."Procedimientos Adjudicados"('.implode(',',$cols).',import_id,created_at,updated_at)
 VALUES('.implode(',',$params).',:imp,now(),now())';
$stmt=$pdo->prepare($sql);

$total=0;$inserted=0;$skipped=0;
$pdo->beginTransaction();
while(($row=fgetcsv($fh,0,$delim))!==false){
  $total++;
  if(count(array_filter($row))==0){$skipped++;continue;}
  $bind=[];
  foreach($headers as $i=>$h){
    $ph=':'.preg_replace('/\W+/','_',strtolower($h));
    $val=trim((string)($row[$i]??''));
    $bind[$ph]=$val===''?null:$val;
  }
  $bind[':imp']=$import_id;
  try{$stmt->execute($bind);$inserted++;}catch(Throwable){$skipped++;}
}
$pdo->commit(); fclose($fh);

$pdo->prepare('UPDATE public.procedimientos_import_log
 SET total_rows=:t,inserted=:i,skipped=:s,finished_at=now() WHERE import_id=:id')
 ->execute([':t'=>$total,':i'=>$inserted,':s'=>$skipped,':id'=>$import_id]);

echo json_encode(['ok'=>true,'insertados'=>$inserted,'saltados'=>$skipped,'total'=>$total]);
