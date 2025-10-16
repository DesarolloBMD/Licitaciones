/* ==========================================================
   JS - Subir Procedimientos Adjudicados
   ========================================================== */

const ENDPOINT = "api/subir_procedimientos.php";
const ANULAR_API = "api/anular_importacion_log.php";
const LOG_API = "api/listar_import_log.php";

const frm = document.getElementById("frm");
const barWrap = document.getElementById("barWrap");
const bar = document.getElementById("bar");
const log = document.getElementById("log");
const tblHistorial = document.querySelector("#tblHistorial tbody");
const tblLogCompleto = document.querySelector("#tblLogCompleto tbody");
const btnSubir = document.getElementById("btnSubir");
const btnCancelar = document.getElementById("btnCancelar");
const btnLimpiar = document.getElementById("btnLimpiar");
const cronometro = document.getElementById("cronometro");

let controller = null, timer = null, segundos = 0;

/* ==========================================================
   1. Subir archivo e importar
   ========================================================== */
frm.addEventListener("submit", async (e) => {
  e.preventDefault();

  const fd = new FormData(frm);
  controller = new AbortController();

  btnSubir.disabled = true;
  btnCancelar.disabled = false;
  barWrap.style.display = "block";
  bar.style.width = "5%";
  bar.textContent = "0%";
  log.textContent = "⏳ Subiendo...";
  segundos = 0;
  cronometro.textContent = "0s";
  timer = setInterval(() => {
    segundos++;
    cronometro.textContent = segundos + "s";
  }, 1000);

  try {
    const res = await fetch(ENDPOINT, {
      method: "POST",
      body: fd,
      signal: controller.signal,
    });

    const txt = await res.text();
    let data;
    try {
      data = JSON.parse(txt);
    } catch {
      throw new Error("⚠ Respuesta no válida del servidor:\n" + txt.slice(0, 300));
    }

    if (!data.ok) throw new Error(data.error || "Error desconocido en la importación");

    bar.style.width = "100%";
    bar.textContent = "100%";

    const errores = (data.errores || []).slice(0, 10).map(e => "- " + e).join("\n");
    log.textContent = `✅ Importación completada
Insertados: ${data.insertados}
Saltados: ${data.saltados}
Total: ${data.total}
${errores ? "\nErrores:\n" + errores : ""}`;

    await cargarHistorial();
    await cargarLogCompleto();

  } catch (err) {
    log.textContent = err.name === "AbortError"
      ? "⛔ Importación cancelada."
      : "⚠ " + err.message;
  } finally {
    btnSubir.disabled = false;
    btnCancelar.disabled = true;
    clearInterval(timer);
    controller = null;
    setTimeout(() => {
      barWrap.style.display = "none";
      bar.style.width = "0%";
      bar.textContent = "0%";
    }, 1000);
  }
});

/* ==========================================================
   2. Botones: cancelar y limpiar
   ========================================================== */
btnCancelar.addEventListener("click", () => {
  if (controller) controller.abort();
});
btnLimpiar.addEventListener("click", () => {
  frm.reset();
  log.textContent = "";
  bar.style.width = "0%";
  bar.textContent = "0%";
  barWrap.style.display = "none";
  cronometro.textContent = "";
});

/* ==========================================================
   3. Cargar historial reciente
   ========================================================== */
async function cargarHistorial() {
  try {
    const r = await fetch(ENDPOINT + "?action=historial");
    const data = await r.json();

    if (!data.ok) throw new Error(data.error || "Error al cargar historial");
    if (!Array.isArray(data.rows) || !data.rows.length) {
      tblHistorial.innerHTML = `<tr><td colspan="9" class="text-center text-muted">Sin registros</td></tr>`;
      return;
    }

    tblHistorial.innerHTML = data.rows.map(x => `
      <tr>
        <td><code>${x.import_id}</code></td>
        <td>${x.filename || "(sin nombre)"}</td>
        <td>${x.mes_descarga || "-"}</td>
        <td>${x.anio_descarga || "-"}</td>
        <td>${x.inserted ?? 0}</td>
        <td>${x.skipped ?? 0}</td>
        <td>${x.total_rows ?? (x.inserted + x.skipped ?? 0)}</td>
        <td>${x.finished_at ? new Date(x.finished_at).toLocaleString() : "-"}</td>
        <td>
          <button class="btn btn-sm btn-outline-danger" onclick="anularImportacion('${x.import_id}')">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`).join("");

  } catch (err) {
    tblHistorial.innerHTML = `<tr><td colspan="9" class="text-danger text-center">Error al cargar historial.<br>${err.message}</td></tr>`;
  }
}

/* ==========================================================
   4. Cargar log completo
   ========================================================== */
async function cargarLogCompleto() {
  try {
    const r = await fetch(LOG_API);
    const data = await r.json();

    if (!data.ok) throw new Error(data.error || "Error al cargar log completo");
    if (!Array.isArray(data.rows) || !data.rows.length) {
      tblLogCompleto.innerHTML = `<tr><td colspan="12" class="text-center text-muted">Sin registros</td></tr>`;
      return;
    }

    tblLogCompleto.innerHTML = data.rows.map(r => `
      <tr>
        <td><code>${r.import_id}</code></td>
        <td>${r.filename || "-"}</td>
        <td>${r.mes_descarga || "-"}</td>
        <td>${r.anio_descarga || "-"}</td>
        <td>${r.inserted ?? 0}</td>
        <td>${r.skipped ?? 0}</td>
        <td>${r.total_rows ?? 0}</td>
        <td>${r.started_at ? new Date(r.started_at).toLocaleString() : "-"}</td>
        <td>${r.finished_at ? new Date(r.finished_at).toLocaleString() : "-"}</td>
        <td>${r.source_ip ?? "-"}</td>
        <td><code>${(r.file_hash || "").slice(0, 12)}...</code></td>
        <td>${r.anulado_at
          ? `<span class="badge bg-danger">Sí</span>`
          : `<span class="badge bg-success">No</span>`}</td>
      </tr>`).join("");

  } catch (err) {
    tblLogCompleto.innerHTML = `<tr><td colspan="12" class="text-danger text-center">${err.message}</td></tr>`;
  }
}

/* ==========================================================
   5. Anular importación
   ========================================================== */
async function anularImportacion(id) {
  if (!confirm("¿Seguro que desea anular esta importación?")) return;

  try {
    const r = await fetch(`${ANULAR_API}?id=${id}`);
    const data = await r.json();

    if (!data.ok) throw new Error(data.error || "Error al anular importación");
    alert(data.msg || "Importación anulada correctamente ✅");
    await cargarHistorial();
    await cargarLogCompleto();

  } catch (e) {
    alert("⚠ " + e.message);
  }
}

/* ==========================================================
   6. Inicialización
   ========================================================== */
cargarHistorial();
cargarLogCompleto();
