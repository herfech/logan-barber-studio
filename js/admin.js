/* =========================================================
   Logan Barber Studio · Panel de administración
   ---------------------------------------------------------
   Endpoints (PHP):
     GET  api/admin/estado.php       → ¿sesión abierta? ¿primera configuración?
     POST api/admin/configurar.php   → crea la primera cuenta
     POST api/admin/login.php        → inicia sesión (email + contraseña)
     POST api/admin/logout.php       → cierra sesión
     POST api/admin/cuenta.php       → cambia la contraseña
     GET  api/admin/resumen.php      → indicadores
     GET  api/admin/reservas.php     → agenda (desde, hasta, estado)
     POST api/admin/reservas.php     → cambia el estado de una cita
     GET  api/admin/servicios.php    → servicios
     POST api/admin/servicios.php    → actualiza precio, duración, visible

   Seguridad: la sesión va en una cookie HttpOnly (JavaScript no
   puede leerla) y cada acción envía el token CSRF.
   ========================================================= */

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const DAY_SHORT = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const ESTADOS = {
  confirmada: 'Confirmada',
  completada: 'Completada',
  no_asistio: 'No asistió',
  cancelada: 'Cancelada',
};

const isoLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
// Precio en francos CFA: 12000 → «12.000 FCFA»
const formatPrice = (n) => `${String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')} FCFA`;
const formatDate = (iso) => {
  const d = new Date(`${iso}T12:00:00`);
  return `${DAY_SHORT[d.getDay()]} ${d.getDate()}/${d.getMonth() + 1}`;
};

let csrf = null;
let agenda = [];

function showToast(message, isError = false) {
  const toast = $('#appToast');
  $('#appToastBody').textContent = message;
  toast.classList.toggle('is-error', isError);
  bootstrap.Toast.getOrCreateInstance(toast, { delay: 3500 }).show();
}

async function api(url, { method = 'GET', body } = {}) {
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      ...(csrf && method !== 'GET' ? { 'X-CSRF-Token': csrf } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && !url.includes('login.php')) {
    showView('login');
    throw new Error(data.error || 'Sesión caducada. Vuelve a entrar.');
  }
  if (!res.ok) throw new Error(data.error || 'Error de conexión con el servidor.');
  return data;
}

/* ---------- Vistas ---------- */
function showView(view) {
  $('#loadingView').classList.toggle('d-none', true);
  $('#loginView').classList.toggle('d-none', view === 'app');
  $('#appView').classList.toggle('d-none', view !== 'app');
  $('#loginForm').classList.toggle('d-none', view !== 'login');
  $('#setupForm').classList.toggle('d-none', view !== 'setup');
  if (view === 'login') setTimeout(() => $('#loginEmail').focus(), 50);
  if (view === 'setup') setTimeout(() => $('#setupKey').focus(), 50);
}

function showError(el, message) {
  el.textContent = message;
  el.classList.toggle('d-none', !message);
}

async function withButton(form, fn) {
  const btn = form.querySelector('[type=submit]');
  const label = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  try { await fn(); } finally { btn.disabled = false; btn.innerHTML = label; }
}

/* ---------- Sesión ---------- */
function enter(data) {
  csrf = data.csrf;
  $('#userName').textContent = data.usuario.nombre;
  showView('app');
  loadAll();
}

async function checkSession() {
  try {
    const estado = await api('api/admin/estado.php');
    if (!estado.configurado) return showView('setup');
    if (estado.usuario) return enter(estado);
    showView('login');
  } catch (err) {
    showView('login');
    showError($('#loginError'), err.message);
  }
}

function login(e) {
  e.preventDefault();
  const form = e.currentTarget;
  showError($('#loginError'), '');
  withButton(form, async () => {
    try {
      const data = await api('api/admin/login.php', {
        method: 'POST',
        body: { email: $('#loginEmail').value, password: $('#loginPassword').value },
      });
      $('#loginPassword').value = '';
      enter(data);
    } catch (err) {
      showError($('#loginError'), err.message);
    }
  });
}

function setup(e) {
  e.preventDefault();
  const form = e.currentTarget;
  showError($('#setupError'), '');
  withButton(form, async () => {
    try {
      const data = await api('api/admin/configurar.php', {
        method: 'POST',
        body: {
          clave_instalacion: $('#setupKey').value,
          nombre: $('#setupName').value,
          email: $('#setupEmail').value,
          password: $('#setupPassword').value,
        },
      });
      form.reset();
      showToast('Cuenta creada. ¡Bienvenido al panel!');
      enter(data);
    } catch (err) {
      showError($('#setupError'), err.message);
    }
  });
}

async function logout() {
  await api('api/admin/logout.php', { method: 'POST' }).catch(() => {});
  csrf = null;
  showView('login');
}

function changePassword(e) {
  e.preventDefault();
  const form = e.currentTarget;
  showError($('#pwError'), '');
  withButton(form, async () => {
    try {
      await api('api/admin/cuenta.php', { method: 'POST', body: { actual: $('#pwCurrent').value, nueva: $('#pwNew').value } });
      form.reset();
      bootstrap.Modal.getInstance($('#passwordModal')).hide();
      showToast('Contraseña actualizada.');
    } catch (err) {
      showError($('#pwError'), err.message);
    }
  });
}

/* ---------- Indicadores ---------- */
async function loadSummary() {
  const r = await api('api/admin/resumen.php');
  $('#kpiHoy').textContent = r.hoy;
  $('#kpiProximas').textContent = r.proximas;
  $('#kpiClientes').textContent = r.clientes;
  $('#kpiIngresos').textContent = formatPrice(r.ingresos_mes);
}

/* ---------- Agenda ---------- */
async function loadAgenda() {
  const params = new URLSearchParams({ desde: $('#fDesde').value, hasta: $('#fHasta').value, estado: $('#fEstado').value });
  agenda = await api(`api/admin/reservas.php?${params}`);
  renderAgenda();
}

function renderAgenda() {
  const today = isoLocal(new Date());
  const body = $('#agendaBody');

  if (!agenda.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="8"><i class="bi bi-calendar-x fs-3 d-block mb-2"></i>No hay citas con estos filtros.</td></tr>';
    return;
  }

  body.innerHTML = agenda.map((r) => {
    const acciones = Object.entries(ESTADOS)
      .filter(([k]) => k !== r.estado)
      .map(([k, label]) => `<li><button class="dropdown-item" data-id="${r.id}" data-estado="${k}">Marcar como ${label.toLowerCase()}</button></li>`)
      .join('');
    return `
      <tr class="${r.fecha === today ? 'is-today' : ''}">
        <td class="text-nowrap fw-semibold">${formatDate(r.fecha)}</td>
        <td class="text-nowrap">${esc(r.hora_inicio)}<span class="cell-sub">hasta ${esc(r.hora_fin)}</span></td>
        <td>
          ${esc(r.cliente)}
          <span class="cell-sub"><a href="tel:${esc(r.telefono)}">${esc(r.telefono)}</a>${r.notas ? ` · <i class="bi bi-chat-left-text" title="${esc(r.notas)}"></i>` : ''}</span>
        </td>
        <td>${esc(r.servicio)}<span class="cell-sub">${esc(r.codigo)}</span></td>
        <td>${esc(r.profesional)}</td>
        <td class="text-end text-nowrap">${formatPrice(r.precio)}</td>
        <td><span class="status status-${esc(r.estado)}">${ESTADOS[r.estado] ?? esc(r.estado)}</span></td>
        <td class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-outline-ink rounded-pill" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acciones"><i class="bi bi-three-dots"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">${acciones}</ul>
          </div>
        </td>
      </tr>`;
  }).join('');
}

async function changeStatus(id, estado) {
  try {
    await api('api/admin/reservas.php', { method: 'POST', body: { id: Number(id), estado } });
    showToast(`Cita marcada como ${ESTADOS[estado].toLowerCase()}.`);
    await Promise.all([loadAgenda(), loadSummary()]);
  } catch (err) {
    showToast(err.message, true);
  }
}

function exportCSV() {
  if (!agenda.length) return showToast('No hay citas que exportar.', true);
  const cols = ['codigo', 'fecha', 'hora_inicio', 'hora_fin', 'cliente', 'telefono', 'email', 'servicio', 'profesional', 'precio', 'estado', 'notas'];
  // Evita que Excel interprete fórmulas maliciosas (=, +, -, @)
  const cell = (v) => {
    let t = String(v ?? '');
    if (/^[=+\-@\t\r]/.test(t)) t = `'${t}`;
    return `"${t.replace(/"/g, '""')}"`;
  };
  const csv = [cols.join(';'), ...agenda.map((r) => cols.map((c) => cell(r[c])).join(';'))].join('\r\n');
  const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: `agenda-${$('#fDesde').value}_${$('#fHasta').value}.csv` });
  a.click();
  URL.revokeObjectURL(url);
}

/* ---------- Servicios ---------- */
async function loadServices() {
  const services = await api('api/admin/servicios.php');
  const durations = Array.from({ length: 32 }, (_, i) => (i + 1) * 15); // 15 min … 8 h (trenzas largas)

  $('#servicesBody').innerHTML = services.map((s) => `
    <tr data-id="${esc(s.id)}" class="${s.activo ? '' : 'is-off'}">
      <td class="fw-semibold">${esc(s.nombre)}</td>
      <td class="text-muted-ink">${esc(s.categoria)}</td>
      <td><input type="number" class="form-control" name="precio" min="0" max="1000000" step="500" value="${Number(s.precio)}" aria-label="Precio de ${esc(s.nombre)}" /></td>
      <td>
        <select class="form-select" name="duracion_min" aria-label="Duración de ${esc(s.nombre)}">
          ${durations.map((d) => `<option value="${d}" ${d === s.duracion_min ? 'selected' : ''}>${d < 60 ? `${d} min` : `${Math.floor(d / 60)} h${d % 60 ? ` ${d % 60}` : ''}`}</option>`).join('')}
        </select>
      </td>
      <td><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="activo" role="switch" ${s.activo ? 'checked' : ''} aria-label="Visible en la web" /></div></td>
      <td class="text-end"><button class="btn btn-sm btn-ink rounded-pill px-3" data-save>Guardar</button></td>
    </tr>`).join('');
}

async function saveService(row) {
  const btn = row.querySelector('[data-save]');
  const body = {
    id: row.dataset.id,
    precio: Number(row.querySelector('[name=precio]').value),
    duracion_min: Number(row.querySelector('[name=duracion_min]').value),
    activo: row.querySelector('[name=activo]').checked,
  };
  btn.disabled = true;
  try {
    await api('api/admin/servicios.php', { method: 'POST', body });
    row.classList.toggle('is-off', !body.activo);
    showToast('Servicio actualizado.');
  } catch (err) {
    showToast(err.message, true);
  } finally {
    btn.disabled = false;
  }
}

/* ---------- Arranque ---------- */
function setDefaultRange() {
  const to = new Date();
  to.setDate(to.getDate() + 14);
  $('#fDesde').value = isoLocal(new Date());
  $('#fHasta').value = isoLocal(to);
}

async function loadAll() {
  try {
    await Promise.all([loadSummary(), loadAgenda(), loadServices()]);
  } catch (err) {
    showToast(err.message, true);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  setDefaultRange();

  $('#loginForm').addEventListener('submit', login);
  $('#setupForm').addEventListener('submit', setup);
  $('#passwordForm').addEventListener('submit', changePassword);
  $('#logoutBtn').addEventListener('click', logout);

  $('#filters').addEventListener('change', () => loadAgenda().catch((e) => showToast(e.message, true)));
  $('#todayBtn').addEventListener('click', () => {
    const today = isoLocal(new Date());
    $('#fDesde').value = today;
    $('#fHasta').value = today;
    loadAgenda().catch((e) => showToast(e.message, true));
  });
  $('#csvBtn').addEventListener('click', exportCSV);

  $('#agendaBody').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-estado]');
    if (btn) changeStatus(btn.dataset.id, btn.dataset.estado);
  });
  $('#servicesBody').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-save]');
    if (btn) saveService(btn.closest('tr'));
  });

  checkSession();
});
