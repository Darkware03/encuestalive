/*
 * app.js — EncuestaLive
 *
 * JavaScript de progresión, no de funcionamiento.
 *
 * Regla de ADR-0001 y de wireframes-states 16.d: el HTML llega RENDERIZADO
 * desde el servidor (Blade). El participante debe poder leer la pregunta y
 * marcar una opción **sin esperar a JavaScript**. Todo lo de este fichero es
 * mejora progresiva: si no carga, nada esencial se rompe.
 *
 * Sin Vue, sin React, sin Alpine (ADR-0001). Vanilla + API del DOM.
 * Livewire 4 se encarga de la reactividad con servidor en la Fase 5b.
 */

/*
 * CAMBIO MINIMO DE LA FASE 5b (backend-engineer), reportado en el informe.
 *
 * Unica linea anadida a este fichero. `realtime.js` es quien llama a
 * `setConnectionState()`, la API que este fichero ya exponia en la Fase 5a y
 * que hasta ahora nadie invocaba por no haber socket real que observar.
 *
 * Va al final del arranque y en un modulo aparte para que siga siendo cierto
 * lo que dice la cabecera: si el tiempo real falla, nada esencial se rompe.
 */
import './realtime.js';

/* ------------------------------------------------------------------ utils */

const onReady = (fn) =>
    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', fn, { once: true })
        : fn();

const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ==========================================================================
   1. Botón en carga — congelar el ancho (§6.1 · wireframes-states 16.c)
   El texto pasa a gerundio y el botón NO debe encogerse ni saltar.
   ========================================================================== */
function freezeButtonWidths(root = document) {
    root.querySelectorAll('.el-btn').forEach((btn) => {
        if (btn.dataset.widthFrozen === '1') return;
        const w = btn.getBoundingClientRect().width;
        if (w > 0) {
            btn.style.setProperty('--el-btn-frozen-width', `${Math.ceil(w)}px`);
            btn.dataset.widthFrozen = '1';
        }
    });
}

/* ==========================================================================
   2. Textarea con contador (§6.5)
   Umbrales 0-89 % / 90-99 % / 100 % con color y anuncio distintos.
   El anuncio al lector de pantalla se limita a 1/s como máximo (riesgo A6):
   un contador que habla en cada tecla hace inusable el lector.
   ========================================================================== */
function initCharacterCounters(root = document) {
    root.querySelectorAll('[data-counter-for]').forEach((counter) => {
        const field = document.getElementById(counter.dataset.counterFor);
        if (!field || counter.dataset.bound === '1') return;
        counter.dataset.bound = '1';

        const max = Number(field.getAttribute('maxlength')) || 0;
        const live = counter.querySelector('[data-counter-live]');
        const value = counter.querySelector('[data-counter-value]');
        let lastAnnounce = 0;
        let lastBucket = null;

        const update = () => {
            const len = field.value.length;
            if (value) value.textContent = `${len}/${max}`;

            const pct = max ? (len / max) * 100 : 0;
            const bucket = pct >= 100 ? 'full' : pct >= 90 ? 'near' : 'ok';

            counter.dataset.threshold = bucket;

            // Anuncio: solo al cruzar umbral y como máximo 1 vez por segundo.
            if (!live || bucket === 'ok' || bucket === lastBucket) {
                lastBucket = bucket;
                return;
            }
            const now = Date.now();
            if (now - lastAnnounce < 1000) return;
            lastAnnounce = now;
            lastBucket = bucket;
            live.textContent =
                bucket === 'full'
                    ? `Has alcanzado el límite de ${max} caracteres`
                    : `Quedan ${max - len} caracteres`;
        };

        field.addEventListener('input', update);
        update();
    });
}

/* ==========================================================================
   3. Toast (§6.10)
   · éxito 4 s · info 5 s · aviso 8 s · ERROR SIN AUTOCIERRE (WCAG 2.2.1)
   · el temporizador se PAUSA al pasar el ratón o al recibir foco
   · máximo 3 simultáneos, el resto en cola
   ========================================================================== */
const TOAST_DURATION = { success: 4000, info: 5000, warning: 8000, error: null };
const TOAST_MAX_VISIBLE = 3;

function initToasts(root = document) {
    root.querySelectorAll('[data-toast]').forEach((toast) => {
        if (toast.dataset.bound === '1') return;
        toast.dataset.bound = '1';

        const close = () => toast.remove();
        toast
            .querySelector('[data-toast-close]')
            ?.addEventListener('click', close);

        // La galería fija sus toasts para poder capturarlos (data-toast-persist).
        // En producción no se usa: los tiempos de abajo siguen vigentes.
        if (toast.hasAttribute('data-toast-persist')) return;

        const ms = TOAST_DURATION[toast.dataset.toast] ?? null;
        if (ms === null) return; // error: nunca desaparece solo

        let timer = null;
        const start = () => {
            timer = window.setTimeout(close, ms);
        };
        const pause = () => {
            window.clearTimeout(timer);
        };

        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('focusin', pause);
        toast.addEventListener('mouseleave', start);
        toast.addEventListener('focusout', start);
        start();
    });

    // Cola: solo 3 visibles a la vez.
    document.querySelectorAll('[data-toast-region]').forEach((region) => {
        const items = [...region.querySelectorAll('[data-toast]')];
        items.forEach((t, i) => {
            t.hidden = i >= TOAST_MAX_VISIBLE;
        });
    });
}

/* ==========================================================================
   4. Modal (§6.11 · §9.2)
   · foco atrapado · foco inicial en el primer control NO destructivo
   · Esc cierra · al cerrar el foco VUELVE al disparador (WCAG 2.4.3)
   · fondo con inert + aria-hidden · scroll del body bloqueado
   ========================================================================== */
const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
    'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

let modalOpener = null;

function openModal(dialog) {
    if (!dialog) return;
    modalOpener = document.activeElement;

    dialog.hidden = false;
    document.body.style.overflow = 'hidden';

    document.querySelectorAll('[data-modal-background]').forEach((bg) => {
        bg.setAttribute('aria-hidden', 'true');
        bg.inert = true;
    });

    const initial =
        dialog.querySelector('[data-modal-initial-focus]') ??
        dialog.querySelector(FOCUSABLE);
    initial?.focus();
}

function closeModal(dialog) {
    if (!dialog) return;
    dialog.hidden = true;
    document.body.style.overflow = '';

    document.querySelectorAll('[data-modal-background]').forEach((bg) => {
        bg.removeAttribute('aria-hidden');
        bg.inert = false;
    });

    modalOpener?.focus();
    modalOpener = null;
}

function initModals(root = document) {
    root.querySelectorAll('[data-modal-open]').forEach((trigger) => {
        if (trigger.dataset.bound === '1') return;
        trigger.dataset.bound = '1';
        trigger.addEventListener('click', () =>
            openModal(document.getElementById(trigger.dataset.modalOpen))
        );
    });

    root.querySelectorAll('[data-modal]').forEach((dialog) => {
        if (dialog.dataset.bound === '1') return;
        dialog.dataset.bound = '1';

        dialog
            .querySelectorAll('[data-modal-close]')
            .forEach((b) => b.addEventListener('click', () => closeModal(dialog)));

        dialog.addEventListener('keydown', (e) => {
            // Esc cierra, salvo flujo destructivo a medias (§6.11)
            if (e.key === 'Escape' && dialog.dataset.modalLocked !== 'true') {
                e.preventDefault();
                closeModal(dialog);
                return;
            }
            if (e.key !== 'Tab') return;

            // Trampa de foco
            const items = [...dialog.querySelectorAll(FOCUSABLE)].filter(
                (el) => el.offsetParent !== null
            );
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
    });

    /*
     * §6.11 — confirmación destructiva: el botón permanece disabled hasta
     * que el texto escrito coincide EXACTAMENTE con el título (P13).
     */
    root.querySelectorAll('[data-confirm-input]').forEach((input) => {
        if (input.dataset.bound === '1') return;
        input.dataset.bound = '1';
        const target = document.getElementById(input.dataset.confirmInput);
        const expected = input.dataset.confirmExpected ?? '';
        const check = () => {
            if (target) target.disabled = input.value !== expected;
        };
        input.addEventListener('input', check);
        check();
    });
}

/* ==========================================================================
   5. Límite máximo en selección múltiple (§6.4)
   Al alcanzar el máximo, las NO seleccionadas pasan a disabled.
   Prevenir el error es mejor que informarlo (WCAG 3.3.4).
   ========================================================================== */
function initCheckboxLimits(root = document) {
    root.querySelectorAll('[data-choice-group]').forEach((group) => {
        if (group.dataset.bound === '1') return;
        group.dataset.bound = '1';

        const max = Number(group.dataset.choiceMax) || 0;
        const min = Number(group.dataset.choiceMin) || 0;
        const live = group.querySelector('[data-choice-live]');
        const readout = group.querySelector('[data-choice-count]');
        const boxes = [...group.querySelectorAll('input[type="checkbox"]')];

        const sync = () => {
            const checked = boxes.filter((b) => b.checked);
            if (readout) readout.textContent = String(checked.length);

            if (!max) return;
            const atMax = checked.length >= max;
            boxes.forEach((b) => {
                if (b.checked) return;
                b.disabled = atMax;
                b.setAttribute('aria-disabled', atMax ? 'true' : 'false');
                b.closest('.el-option-row')?.toggleAttribute('data-disabled', atMax);
            });
            if (live) {
                live.textContent = atMax
                    ? `Has alcanzado el máximo de ${max}. Desmarca una para cambiar.`
                    : `Elige entre ${min} y ${max} · llevas ${checked.length}`;
            }
        };

        boxes.forEach((b) => b.addEventListener('change', sync));
        sync();
    });
}

/* ==========================================================================
   6. Cajón de navegación admin (§3.4)
   Superpuesto con scrim, Esc cierra, foco atrapado. Solo por debajo de lg.
   ========================================================================== */
function initNavDrawer(root = document) {
    const drawer = root.querySelector('[data-nav-drawer]');
    const toggle = root.querySelector('[data-nav-toggle]');
    if (!drawer || !toggle || toggle.dataset.bound === '1') return;
    toggle.dataset.bound = '1';

    const setOpen = (open) => {
        drawer.dataset.open = open ? 'true' : 'false';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) drawer.querySelector(FOCUSABLE)?.focus();
        else toggle.focus();
    };

    toggle.addEventListener('click', () =>
        setOpen(drawer.dataset.open !== 'true')
    );
    drawer
        .querySelectorAll('[data-nav-close]')
        .forEach((b) => b.addEventListener('click', () => setOpen(false)));
    drawer.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
    });
}

/* ==========================================================================
   7. Indicador de conexión (§6.14 · §7.4)
   Aquí solo se expone la API de cambio de estado. QUIÉN la llama (Echo/Reverb
   con respaldo wire:poll) es trabajo de la Fase 5b: sin backend no hay socket
   que observar, y simular una conexión sería mentir sobre el estado real.
   ========================================================================== */
const CONNECTION_STATES = ['online', 'reconnecting', 'offline', 'stale'];

function setConnectionState(state, detail = '') {
    if (!CONNECTION_STATES.includes(state)) return;
    document.querySelectorAll('[data-connection]').forEach((el) => {
        el.dataset.connection = state;
        // role alert solo en desconexión; el resto es status (§6.14)
        el.setAttribute('role', state === 'offline' ? 'alert' : 'status');
        const slot = el.querySelector('[data-connection-detail]');
        if (slot && detail) slot.textContent = detail;
    });
}

/* ==========================================================================
   Arranque
   ========================================================================== */
function boot(root = document) {
    freezeButtonWidths(root);
    initCharacterCounters(root);
    initToasts(root);
    initModals(root);
    initCheckboxLimits(root);
    initNavDrawer(root);
}

onReady(() => boot(document));

// Livewire 4 reemplaza fragmentos del DOM: re-enganchar tras cada actualización.
document.addEventListener('livewire:navigated', () => boot(document));
document.addEventListener('livewire:update', () => boot(document));

window.EncuestaLive = {
    boot,
    openModal,
    closeModal,
    setConnectionState,
    prefersReducedMotion,
};
