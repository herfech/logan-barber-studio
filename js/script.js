/* =========================================================
   Logan Barber Studio · Lógica de la web pública
   ---------------------------------------------------------
   Los servicios, precios, equipo, horario y disponibilidad
   vienen de la base de datos a través de la API:
     GET  api/catalogo.php        → categorías, servicios, equipo, horario
     GET  api/disponibilidad.php  → horas libres de un día
     POST api/reservas.php        → guarda una reserva

   1. Configuración y utilidades
   2. Carga de datos
   3. Header, menú móvil y barra inferior
   4. Servicios (pestañas)
   5. Equipo
   6. Galería (filtros + lightbox)
   7. Testimonios
   8. Horario y estado "Abierto ahora"
   9. Reservas (formulario por pasos)
   10. Animaciones de aparición
   ========================================================= */

/* ---------- 1. CONFIGURACIÓN Y UTILIDADES ---------- */
const SALON = {
  name: 'Logan Barber Studio',
  address: 'Candy Vista Puerto (antiguo Baby Claudia), Malabo, Guinea Ecuatorial',
  phoneDisplay: '+35 009 70 16',
};

const DAY_NAMES = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const DAY_SHORT = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

// Escapa texto antes de insertarlo como HTML (seguridad frente a datos de la base de datos)
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const toMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
const formatDuration = (min) => (min < 60 ? `${min} min` : `${Math.floor(min / 60)} h${min % 60 ? ` ${min % 60} min` : ''}`);
// Precio en francos CFA: 12000 → «12.000 FCFA»
const formatPrice = (n) => `${String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')} FCFA`;
const formatDateLong = (d) => `${DAY_NAMES[d.getDay()]} ${d.getDate()} de ${MONTHS[d.getMonth()]}`;
// Fecha local en formato YYYY-MM-DD (no usar toISOString: convierte a UTC)
const isoLocal = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fromISO = (s) => new Date(`${s}T12:00:00`);

function showToast(message, isError = false) {
  const toast = $('#appToast');
  $('#appToastBody').textContent = message;
  toast.classList.toggle('is-error', isError);
  bootstrap.Toast.getOrCreateInstance(toast, { delay: 4000 }).show();
}

async function api(url, options = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.error || 'No se ha podido conectar con el servidor.');
    err.status = res.status;
    throw err;
  }
  return data;
}

/* ---------- 2. CARGA DE DATOS ---------- */
let DATA = { categorias: [], servicios: [], profesionales: [], horarios: [], dias_reservables: 60 };
let HOURS = {}; // { diaSemana: { apertura, cierre } }

async function loadCatalog() {
  DATA = await api('api/catalogo.php');
  HOURS = Object.fromEntries(DATA.horarios.map((h) => [h.dia_semana, h]));
}

function showOffline() {
  const msg = `
    <div class="offline-msg">
      <i class="bi bi-plug"></i>
      <p class="mb-1 fw-semibold">No se han podido cargar los datos.</p>
      <p class="small mb-0">Revisa que la base de datos esté importada y que <code>includes/config.php</code> tenga los datos correctos. La web debe abrirse desde el servidor (Hostinger, XAMPP o Laragon), no con doble clic en el archivo.</p>
    </div>`;
  $('#serviceTabsContent').innerHTML = msg;
  $('#bookingServices').innerHTML = msg;
  $('#teamGrid').innerHTML = '';
  $('#hoursList').innerHTML = '<li><span>Horario no disponible</span><span></span></li>';
}

/* ---------- 3. HEADER, MENÚ Y BARRA MÓVIL ---------- */
function initHeader() {
  const header = $('#header');
  const navMenu = $('#navMenu');
  const collapse = bootstrap.Collapse.getOrCreateInstance(navMenu, { toggle: false });

  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 20);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  navMenu.addEventListener('show.bs.collapse', () => header.classList.add('menu-open'));
  navMenu.addEventListener('hidden.bs.collapse', () => header.classList.remove('menu-open'));
  $$('#navMenu .nav-link, #navMenu .btn').forEach((link) =>
    link.addEventListener('click', () => navMenu.classList.contains('show') && collapse.hide())
  );

  // Ocultar la barra móvil mientras se rellena la reserva
  const mobileBar = $('.mobile-bar');
  new IntersectionObserver(([entry]) => mobileBar.classList.toggle('is-hidden', entry.isIntersecting), { threshold: 0.25 })
    .observe($('#reservas'));

  $('#year').textContent = new Date().getFullYear();
}

/* ---------- 4. SERVICIOS ---------- */
function renderServices() {
  $('#serviceTabs').innerHTML = DATA.categorias.map((c, i) => `
    <li class="nav-item" role="presentation">
      <button class="nav-link ${i === 0 ? 'active' : ''}" id="tab-${esc(c.id)}" data-bs-toggle="pill" data-bs-target="#pane-${esc(c.id)}" type="button" role="tab" aria-controls="pane-${esc(c.id)}" aria-selected="${i === 0}">
        <i class="bi ${esc(c.icono)} me-2"></i>${esc(c.nombre)}
      </button>
    </li>`).join('');

  $('#serviceTabsContent').innerHTML = DATA.categorias.map((c, i) => {
    const items = DATA.servicios.filter((s) => s.cat === c.id).map((s) => `
      <div class="col-sm-6 col-lg-3">
        <article class="service-item">
          <h3>${esc(s.nombre)}</h3>
          <p>${esc(s.descripcion)}</p>
          <div class="service-meta">
            <span class="duration"><i class="bi bi-clock me-1"></i>${formatDuration(s.duracion)}</span>
            <span class="service-price"><small>Desde</small><strong>${formatPrice(s.precio).replace(' FCFA', '')}</strong><span class="currency">FCFA</span></span>
          </div>
          <a href="#reservas" class="service-book" data-book-service="${esc(s.id)}">Reservar este servicio <i class="bi bi-arrow-right"></i></a>
        </article>
      </div>`).join('');
    return `
      <div class="tab-pane fade ${i === 0 ? 'show active' : ''}" id="pane-${esc(c.id)}" role="tabpanel" aria-labelledby="tab-${esc(c.id)}" tabindex="0">
        <div class="row g-3">${items}</div>
      </div>`;
  }).join('');

  $('#serviceTabsContent').addEventListener('click', (e) => {
    const link = e.target.closest('[data-book-service]');
    if (link) Booking.preselectService(link.dataset.bookService);
  });
}

/* ---------- 5. EQUIPO ---------- */
function renderTeam() {
  $('#teamGrid').innerHTML = DATA.profesionales.map((p, i) => `
    <div class="col-4">
      <article class="team-card reveal ${i % 3 === 1 ? 'offset' : ''}">
        <div class="team-photo">
          ${p.foto
            ? `<img src="${esc(p.foto)}" alt="${esc(p.nombre)}, ${esc(p.rol)}" loading="lazy" />`
            : `<div class="ph" style="background: linear-gradient(160deg, ${esc(p.color)} 0%, #D9CBBB 120%)"></div>`}
        </div>
        <h3>${esc(p.nombre)}</h3>
        <p class="team-role">${esc(p.rol)}</p>
        <p class="team-bio d-none d-sm-block mb-0">${esc(p.bio)}</p>
        ${p.instagram
          ? `<a class="team-insta" href="https://www.instagram.com/${encodeURIComponent(p.instagram)}/" target="_blank" rel="noopener" aria-label="Instagram de ${esc(p.nombre)}"><i class="bi bi-instagram"></i><span>@${esc(p.instagram)}</span></a>`
          : ''}
      </article>
    </div>`).join('');
}

/* ---------- 6. GALERÍA ---------- */
function initGallery() {
  const items = $$('.gallery-item');

  $$('[data-filter]').forEach((btn) =>
    btn.addEventListener('click', () => {
      $$('[data-filter]').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      const filter = btn.dataset.filter;
      items.forEach((item) => {
        const show = filter === 'all' || item.dataset.cat === filter;
        item.classList.toggle('is-hidden', !show);
        item.classList.toggle('big', show && filter === 'all' && item === items[0]);
      });
    })
  );

  const modal = new bootstrap.Modal('#galleryModal');
  items.forEach((item) =>
    item.addEventListener('click', () => {
      const media = item.querySelector('img, .ph').cloneNode(true);
      media.style.transform = 'none';
      $('#lightboxMedia').replaceChildren(media);
      $('#lightboxTitle').textContent = item.dataset.title;
      $('#lightboxAuthor').textContent = `Trabajo realizado por ${item.dataset.author}`;
      modal.show();
    })
  );
}

/* ---------- 7. TESTIMONIOS ---------- */
function initTestimonials() {
  const track = $('#testimonials');
  $$('[data-scroll]').forEach((btn) =>
    btn.addEventListener('click', () => {
      const card = track.querySelector('.testimonial');
      track.scrollBy({ left: (card.offsetWidth + 16) * Number(btn.dataset.scroll), behavior: 'smooth' });
    })
  );
}

/* ---------- 8. HORARIO ---------- */
function renderHours() {
  const now = new Date();
  const today = now.getDay();
  const order = [1, 2, 3, 4, 5, 6, 0]; // lunes primero

  $('#hoursList').innerHTML = order.map((d) => {
    const h = HOURS[d];
    return `<li class="${d === today ? 'today' : ''}"><span>${DAY_NAMES[d]}</span><span>${h ? `${esc(h.apertura)} – ${esc(h.cierre)}` : 'Cerrado'}</span></li>`;
  }).join('');

  const h = HOURS[today];
  const current = now.getHours() * 60 + now.getMinutes();
  const open = !!h && current >= toMin(h.apertura) && current < toMin(h.cierre);
  const badge = $('#openBadge');
  badge.textContent = open ? '● Abierto ahora' : '● Cerrado ahora';
  badge.classList.add(open ? 'is-open' : 'is-closed');
}

/* ---------- 9. RESERVAS ---------- */
const emptyState = () => ({ cat: DATA.categorias[0]?.id, service: null, staff: null, date: null, time: null });

const Booking = {
  step: 1,
  state: {},
  result: null,
  slotsRequest: 0,

  init() {
    this.state = emptyState();
    this.form = $('#bookingForm');
    this.prev = $('#prevStep');
    this.next = $('#nextStep');

    this.renderCats();
    this.renderServices();
    this.renderDates();

    this.prev.addEventListener('click', () => this.go(this.step - 1));
    this.next.addEventListener('click', () => this.handleNext());
    $('#newBooking').addEventListener('click', () => this.reset());
    $('#addCalendar').addEventListener('click', () => this.downloadICS());

    this.form.addEventListener('change', (e) => {
      if (e.target.name === 'service') {
        this.state.service = DATA.servicios.find((s) => s.id === e.target.value);
        this.state.staff = null;
        this.state.time = null;
      }
      if (e.target.name === 'staff') {
        this.state.staff = e.target.value;
        this.state.time = null;
      }
      this.update();
    });

    this.update();
  },

  /* --- Paso 1: categoría y servicio --- */
  renderCats() {
    $('#bookingCats').innerHTML = DATA.categorias.map((c) => `
      <button type="button" class="btn btn-chip ${c.id === this.state.cat ? 'active' : ''}" data-cat="${esc(c.id)}">
        <i class="bi ${esc(c.icono)} me-1"></i>${esc(c.nombre)}
      </button>`).join('');

    $$('#bookingCats [data-cat]').forEach((btn) =>
      btn.addEventListener('click', () => {
        this.state.cat = btn.dataset.cat;
        $$('#bookingCats [data-cat]').forEach((b) => b.classList.toggle('active', b === btn));
        this.renderServices();
      })
    );
  },

  renderServices() {
    $('#bookingServices').innerHTML = DATA.servicios.filter((s) => s.cat === this.state.cat).map((s) => `
      <label class="option">
        <input type="radio" name="service" value="${esc(s.id)}" ${this.state.service?.id === s.id ? 'checked' : ''} />
        <span class="check"><i class="bi bi-check-lg"></i></span>
        <span class="option-body">
          <span class="option-title">${esc(s.nombre)}</span>
          <span class="option-sub">${formatDuration(s.duracion)} · ${esc(s.descripcion)}</span>
        </span>
        <span class="option-price">${formatPrice(s.precio)}</span>
      </label>`).join('');
  },

  /* --- Paso 2: profesional --- */
  renderStaff() {
    const available = DATA.profesionales.filter((p) => p.categorias.includes(this.state.service?.cat));

    const anyOption = `
      <div class="col-6 col-md-3">
        <label class="option staff-option">
          <input type="radio" name="staff" value="any" ${this.state.staff === 'any' ? 'checked' : ''} />
          <span class="check"><i class="bi bi-check-lg"></i></span>
          <span class="avatar" style="background: var(--terra)"><i class="bi bi-shuffle fs-4"></i></span>
          <span class="option-title mt-2">Sin preferencia</span>
          <span class="option-sub">Más huecos libres</span>
        </label>
      </div>`;

    $('#bookingStaff').innerHTML = anyOption + available.map((p) => `
      <div class="col-6 col-md-3">
        <label class="option staff-option">
          <input type="radio" name="staff" value="${esc(p.id)}" ${this.state.staff === p.id ? 'checked' : ''} />
          <span class="check"><i class="bi bi-check-lg"></i></span>
          <span class="avatar" style="background: ${esc(p.color)}">${esc(p.nombre[0])}</span>
          <span class="option-title mt-2">${esc(p.nombre)}</span>
          <span class="option-sub">${esc(p.rol)}</span>
        </label>
      </div>`).join('');
  },

  /* --- Paso 3: fecha y hora --- */
  renderDates() {
    const strip = $('#dateStrip');
    const today = new Date();
    today.setHours(12, 0, 0, 0);
    const total = Math.min(28, DATA.dias_reservables);
    const days = Array.from({ length: total }, (_, i) => {
      const d = new Date(today);
      d.setDate(today.getDate() + i);
      return d;
    });

    strip.innerHTML = days.map((d, i) => {
      const closed = !HOURS[d.getDay()];
      return `
        <button type="button" class="date-btn" data-date="${isoLocal(d)}" ${closed ? 'disabled aria-label="Cerrado"' : ''}>
          <small>${i === 0 ? 'Hoy' : DAY_SHORT[d.getDay()]}</small>
          <strong>${d.getDate()}</strong>
          <small>${MONTHS[d.getMonth()].slice(0, 3)}</small>
        </button>`;
    }).join('');

    strip.addEventListener('click', (e) => {
      const btn = e.target.closest('.date-btn');
      if (!btn || btn.disabled) return;
      $$('.date-btn', strip).forEach((b) => b.classList.toggle('active', b === btn));
      this.state.date = btn.dataset.date;
      this.state.time = null;
      this.loadSlots();
      this.update();
    });

    $('#timeSlots').innerHTML = '<p class="slots-empty">Elige un día para ver las horas libres.</p>';
  },

  async loadSlots() {
    const { date, service, staff } = this.state;
    const box = $('#timeSlots');
    const requestId = ++this.slotsRequest; // ignora respuestas antiguas si el usuario cambia rápido de día
    $('#slotsDay').textContent = `· ${formatDateLong(fromISO(date))}`;
    box.innerHTML = '<p class="slots-empty"><span class="spinner-border spinner-border-sm me-2"></span>Buscando huecos…</p>';

    try {
      const params = new URLSearchParams({ fecha: date, servicio: service.id, profesional: staff });
      const slots = await api(`api/disponibilidad.php?${params}`);
      if (requestId !== this.slotsRequest) return;

      const free = slots.filter((s) => s.libre).length;
      box.innerHTML = free
        ? slots.map((s) => `<button type="button" class="slot ${this.state.time === s.hora ? 'active' : ''}" data-time="${s.hora}" ${s.libre ? '' : 'disabled'}>${s.hora}</button>`).join('')
        : '<p class="slots-empty">No quedan huecos este día. Prueba con otra fecha o escríbenos por WhatsApp.</p>';

      $$('.slot', box).forEach((btn) =>
        btn.addEventListener('click', () => {
          $$('.slot', box).forEach((b) => b.classList.toggle('active', b === btn));
          this.state.time = btn.dataset.time;
          this.update();
        })
      );
    } catch (err) {
      if (requestId !== this.slotsRequest) return;
      box.innerHTML = `<p class="slots-empty text-danger">${esc(err.message)}</p>`;
    }
  },

  /* --- Navegación entre pasos --- */
  canContinue() {
    const s = this.state;
    switch (this.step) {
      case 1: return !!s.service;
      case 2: return !!s.staff;
      case 3: return !!s.date && !!s.time;
      case 4: return true;
      default: return false;
    }
  },

  handleNext() {
    if (this.step < 4) return this.go(this.step + 1);
    this.form.classList.add('was-validated');
    if (!this.form.checkValidity()) {
      showToast('Revisa los campos marcados para terminar la reserva.', true);
      return;
    }
    this.submit();
  },

  go(step) {
    if (step < 1 || step > 4) return;
    this.step = step;
    if (step === 2) this.renderStaff();
    if (step === 3 && this.state.date) this.loadSlots();

    $$('.booking-pane').forEach((p) => p.classList.toggle('active', Number(p.dataset.pane) === step));
    $$('#bookingSteps li').forEach((li) => {
      const n = Number(li.dataset.step);
      li.classList.toggle('active', n === step);
      li.classList.toggle('done', n < step);
    });
    this.update();
    $('.booking-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
  },

  update() {
    const { service, staff, date, time } = this.state;
    const staffName = staff === 'any' ? 'Sin preferencia' : DATA.profesionales.find((p) => p.id === staff)?.nombre;

    $('#sumService').textContent = service?.nombre ?? '—';
    $('#sumStaff').textContent = staffName ?? '—';
    $('#sumDate').textContent = date ? formatDateLong(fromISO(date)) : '—';
    $('#sumTime').textContent = time ?? '—';
    $('#sumDuration').textContent = service ? formatDuration(service.duracion) : '—';
    $('#sumPrice').textContent = service ? formatPrice(service.precio) : '—';

    this.prev.disabled = this.step === 1;
    this.next.disabled = !this.canContinue();
    this.next.innerHTML = this.step === 4
      ? 'Confirmar reserva <i class="bi bi-check-lg ms-1"></i>'
      : 'Continuar <i class="bi bi-arrow-right ms-1"></i>';
  },

  preselectService(id) {
    const service = DATA.servicios.find((s) => s.id === id);
    if (!service) return;
    Object.assign(this.state, { cat: service.cat, service, staff: null, time: null });
    $$('#bookingCats [data-cat]').forEach((b) => b.classList.toggle('active', b.dataset.cat === service.cat));
    this.renderServices();
    this.go(1);
    showToast(`Has elegido: ${service.nombre}. Pulsa “Continuar”.`);
  },

  /* --- Envío a la base de datos --- */
  async submit() {
    const { service, staff, date, time } = this.state;
    this.next.disabled = true;
    this.next.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';

    try {
      this.result = await api('api/reservas.php', {
        method: 'POST',
        body: JSON.stringify({
          servicio: service.id,
          profesional: staff,
          fecha: date,
          hora: time,
          nombre: $('#fName').value,
          telefono: $('#fPhone').value,
          email: $('#fEmail').value,
          notas: $('#fNotes').value,
          privacidad: $('#fPrivacy').checked,
          web: $('#fWebsite').value, // trampa anti-bots (debe ir vacío)
        }),
      });
      this.showSuccess();
    } catch (err) {
      showToast(err.message, true);
      if (err.status === 409) { // la hora se ha ocupado mientras tanto
        this.state.time = null;
        this.go(3);
      } else {
        this.update();
      }
    }
  },

  showSuccess() {
    const r = this.result;
    const name = $('#fName').value.trim().split(' ')[0];
    $('#successText').innerHTML = `Gracias, <strong>${esc(name)}</strong>. Te esperamos el <strong>${formatDateLong(fromISO(r.fecha))}</strong> a las <strong>${esc(r.hora_inicio)}</strong> para tu <strong>${esc(r.servicio.toLowerCase())}</strong> con <strong>${esc(r.profesional)}</strong>.`;
    $('#bookingCode').textContent = r.codigo;
    bootstrap.Toast.getInstance($('#appToast'))?.hide();
    this.form.classList.add('d-none');
    $('#bookingSteps').classList.add('d-none');
    $('#bookingSuccess').classList.remove('d-none');
    $('.booking-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
  },

  downloadICS() {
    const r = this.result;
    const toICS = (fecha, hora) => `${fecha.replace(/-/g, '')}T${hora.replace(':', '')}00`;
    const ics = [
      'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Logan Barber Studio//Reservas//ES',
      'BEGIN:VEVENT',
      `UID:${r.codigo}@loganbarberstudio.com`,
      `DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').split('.')[0]}Z`,
      `DTSTART:${toICS(r.fecha, r.hora_inicio)}`,
      `DTEND:${toICS(r.fecha, r.hora_fin)}`,
      `SUMMARY:${r.servicio} · ${SALON.name}`,
      `LOCATION:${SALON.address.replace(/,/g, '\\,')}`,
      `DESCRIPTION:Código ${r.codigo}. Con ${r.profesional}. Para cambios llama al ${SALON.phoneDisplay}.`,
      'END:VEVENT', 'END:VCALENDAR',
    ].join('\r\n');

    const url = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: `cita-logan-${r.codigo}.ics` });
    a.click();
    URL.revokeObjectURL(url);
  },

  reset() {
    this.state = emptyState();
    this.result = null;
    this.form.reset();
    this.form.classList.remove('was-validated', 'd-none');
    $('#bookingSteps').classList.remove('d-none');
    $('#bookingSuccess').classList.add('d-none');
    $('#slotsDay').textContent = '';
    $$('#bookingCats [data-cat]').forEach((b, i) => b.classList.toggle('active', i === 0));
    $$('.date-btn').forEach((b) => b.classList.remove('active'));
    $('#timeSlots').innerHTML = '<p class="slots-empty">Elige un día para ver las horas libres.</p>';
    this.renderServices();
    this.go(1);
  },
};

/* ---------- 10. ANIMACIONES DE APARICIÓN ---------- */
function initReveal() {
  const els = $$('.reveal');
  if (!('IntersectionObserver' in window)) return els.forEach((el) => el.classList.add('visible'));
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });
  els.forEach((el) => io.observe(el));
}

/* ---------- ARRANQUE ---------- */
document.addEventListener('DOMContentLoaded', async () => {
  initHeader();
  initGallery();
  initTestimonials();

  try {
    await loadCatalog();
    renderServices();
    renderTeam();
    renderHours();
    Booking.init();
  } catch (err) {
    console.error(err);
    showOffline();
  }

  initReveal(); // al final, para incluir los elementos generados con los datos
});
