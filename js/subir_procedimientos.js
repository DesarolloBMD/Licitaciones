const ENDPOINT = "api/subir_procedimientos.php";
const frm = document.getElementById("frm");
const barWrap = document.getElementById("barWrap");
const bar = document.getElementById("bar");
const log = document.getElementById("log");
const tbl = document.querySelector("#tblHistorial tbody");
const tblLogCompleto = document.querySelector("#tblLogCompleto tbody");
const btnSubir = document.getElementById("btnSubir");
const btnCancelar = document.getElementById("btnCancelar");
const btnLimpiar = document.getElementById("btnLimpiar");
const cronometro = document.getElementById("cronometro");
let controller = null, timer = null, segundos = 0;

frm.addEventListener("submit", async e => {
  e.preventDefault();
  const fd = new FormData(frm);
  controller = new AbortController();
  btnSubir.disabled = true;
  btnCancelar.disabled = false;
  barWrap.style.display = "block";
  bar.style.width = "5%";
  log.textContent = "⏳ Subiendo...";
  segundos = 0;
  cronometro.textContent = "0s";
  timer = setInterval(()=>{ segundos++; cronometro.textContent = segundos+"s"; },1000);

  try {
    const res = await fetch(ENDPOINT, { method:'POST', body:fd, signal:controller.signal });
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); } catch { throw new Error("⚠ Respuesta no válida del servidor:\n" + txt.slice(0,300)); }

    if (!data.ok) throw new Error(data.error || "Error desconocido en la importación");

    bar.style.width = "100%";
    bar.textContent = "100%";
    const errores = (data.errores || []).slice(0,10).map(e => "- "+e).join("\n");
    log.textContent = `✅ Importación completada\nInsertados: ${data.insertados}\nSaltados: ${data.saltados}\nTotal: ${data.total}\n${errores ? '\nErrores:\n'+errores : ''}`;
    cargarHistorial();
    cargarLogCompleto();
  } catch(err){
    log.textContent = err.name==='AbortError' ? '⛔ Importación cancelada.' : '⚠ '+err.message;
  } finally {
    btnSubir.disabled = false;
    btnCancelar.disabled = true;
    clearInterval(timer);
    controller = null;
    setTimeout(()=>{ barWrap.style.display='none'; bar.style.width='0%'; }, 1000);
  }
});

btnCancelar.addEventListener("click",()=>{ if(controller) controller.abort(); });
btnLimpiar.addEventListener("click",()=>{ frm.reset(); log.textContent=''; bar.style.width='0%'; barWrap.style.display='none'; cronometro.textContent=''; });

async function cargarHistorial(){
  try{
    const r = await fetch(ENDPOINT+'?action=historial');
    const data = await r.json();
    if(!data.ok) throw new Error(data.error || "Error al cargar historial");
    if(!data.rows.length){
      tbl.innerHTML='<tr><td colspan="9" class="text-center text-muted">Sin registros</td></tr>';
      return;
    }
    tbl.innerHTML = data.rows.map(x=>`
      <tr>
        <td><code>${x.import_id}</code></td>
        <td>${x.filename}</td>
        <td>${x.mes_descarga}</td>
        <td>${x.anio_descarga}</td>
        <td>${x.inserted}</td>
        <td>${x.skipped}</td>
        <td>${x.total_rows}</td>
        <td>${x.finished_at ? new Date(x.finished_at).toLocaleString() : '-'}</td>
        <td>
          <button class="btn btn-sm btn-outline-danger" onclick="anularImportacion('${x.import_id}')">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`).join('');
  }catch(err){
    tbl.innerHTML=`<tr><td colspan="9" class="text-danger text-center">Error al cargar historial.<br>${err.message}</td></tr>`;
  }
}

async function cargarLogCompleto(){
  try{
    const r = await fetch("api/listar_import_log.php");
    const data = await r.json();
    if(!data.ok) throw new Error(data.error || "Error al cargar log");
    if(!data.rows.length){
      tblLogCompleto.innerHTML='<tr><td colspan="13" class="text-center text-muted">Sin registros</td></tr>';
      return;
    }
    tblLogCompleto.innerHTML = data.rows.map(r => `
      <tr>
        <td><code>${r.import_id}</code></td>
        <td class="text-truncate" style="max-width:160px">${r.filename || "-"}</td>
        <td>${r.mes_descarga || "-"}</td>
        <td>${r.anio_descarga || "-"}</td>
        <td>${r.inserted ?? 0}</td>
        <td>${r.skipped ?? 0}</td>
        <td>${r.total_rows ?? 0}</td>
        <td>${r.started_at ? new Date(r.started_at).toLocaleString() : "-"}</td>
        <td>${r.finished_at ? new Date(r.finished_at).toLocaleString() : "-"}</td>
        <td>${r.source_ip ?? "-"}</td>
        <td><code>${(r.file_hash || "").slice(0,12)}...</code></td>
        <td>${r.anulado_at ? '<span class="badge bg-danger">Sí</span>' : '<span class="badge bg-success">No</span>'}</td>
        <td>${r.anulado_at ? '' : `<button class="btn btn-sm btn-outline-danger" onclick="anularImportacion('${r.import_id}')"><i class="bi bi-trash"></i></button>`}</td>
      </tr>`).join("");
  }catch(e){
    tblLogCompleto.innerHTML=`<tr><td colspan="13" class="text-danger text-center">${e.message}</td></tr>`;
  }
}

async function anularImportacion(id){
  if(!confirm("¿Seguro desea anular esta importación?")) return;
  try{
    const r = await fetch("api/anular_importacion_log.php?id="+id);
    const d = await r.json();
    if(!d.ok) throw new Error(d.error || "Error al anular");
    alert("Importación anulada correctamente");
    cargarHistorial();
    cargarLogCompleto();
  }catch(e){ alert("Error: "+e.message); }
}

cargarHistorial();
cargarLogCompleto();
