/*
 * realtime.js — EncuestaLive, Fase 5b
 *
 * Tiempo real (Reverb) + presencia + borrador del participante.
 *
 * ================= LA REGLA DURA DE D-02, EN EL CLIENTE =================
 * Este fichero NO PINTA NI UN DATO. No lee `stats`, no actualiza contadores y
 * no toca el DOM de resultados.
 *
 * Lo unico que hace con un evento entrante es avisar a Livewire de que algo
 * cambio; el componente reconsulta la base de datos via StatsService y
 * re-renderiza. El WebSocket indica CUANDO refrescar, nunca QUE mostrar.
 *
 * Consecuencia: si este fichero no carga, o Reverb esta caido, el dashboard
 * sigue funcionando con `wire:poll` y muestra EXACTAMENTE los mismos numeros.
 * ========================================================================
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/* ------------------------------------------------------------------ utils */

const setState = (state, detail = '') => {
    // Indicador visual de frontend-engineer (§6.14), que hasta la Fase 5b
    // nadie llamaba. Esta es la llamada que lo conecta.
    window.EncuestaLive?.setConnectionState?.(
        { connected: 'online', reconnecting: 'reconnecting', degraded: 'stale' }[state] ?? 'stale',
        detail,
    );

    // Y el componente Livewire, para que ajuste su intervalo de wire:poll.
    document.querySelectorAll('[data-dashboard]').forEach((el) => {
        const id = el.closest('[wire\\:id]')?.getAttribute('wire:id');
        if (!id) return;
        window.Livewire?.find(id)?.call('setConnectionState', state);
    });
};

/* ==========================================================================
   1. Echo / Reverb
   ========================================================================== */
function initEcho() {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    // Sin clave configurada no hay WebSocket posible. Se declara `degraded`
    // con honestidad en lugar de dejar el indicador en verde mintiendo.
    if (!key) {
        setState('degraded', 'tiempo real no configurado');
        return null;
    }

    let echo;
    try {
        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    } catch (e) {
        setState('degraded', 'tiempo real no disponible');
        return null;
    }

    window.Echo = echo;

    const connection = echo.connector?.pusher?.connection;
    if (!connection) {
        setState('degraded', 'tiempo real no disponible');
        return echo;
    }

    /*
     * Maquina de estados de conexion de ARCHITECTURE.md §8.2:
     *   conectando -> conectado -> (corte) -> reconectando -> conectado
     *                                  |
     *                                  +-- 5 intentos --> degradado
     *
     * `degraded` NO es un estado de error que haya que ocultar: es el modo de
     * respaldo funcionando. Por eso el texto que acompana al indicador dice lo
     * que de verdad ocurre ("actualizacion cada 5 s"), en lugar de un generico
     * "sin conexion" que haria pensar que el dashboard esta roto.
     */
    let failures = 0;

    connection.bind('connected', () => {
        failures = 0;
        setState('connected', 'en vivo');
    });

    connection.bind('connecting', () => setState('reconnecting', 'conectando…'));

    connection.bind('unavailable', () => {
        failures += 1;
        setState(failures >= 5 ? 'degraded' : 'reconnecting', `intento ${failures}`);
    });

    connection.bind('disconnected', () => {
        failures += 1;
        setState(failures >= 5 ? 'degraded' : 'reconnecting', `intento ${failures}`);
    });

    connection.bind('failed', () => setState('degraded', 'tiempo real no disponible'));
    connection.bind('error', () => {
        failures += 1;
        if (failures >= 5) setState('degraded', 'tiempo real no disponible');
    });

    return echo;
}

/* ==========================================================================
   2. Canal publico del participante
   Solo recibe el ciclo de vida de la encuesta: tres claves, nada mas.
   ========================================================================== */
function initParticipantChannel(echo) {
    const form = document.querySelector('[data-survey-form]');
    const publicId = form?.dataset.publicId;
    if (!echo || !publicId) return;

    echo.channel(`survey.${publicId}`).listen('.status.changed', (e) => {
        if (e?.status === 'active') return;

        // Aviso NO DESTRUCTIVO: el borrador se conserva y lo marcado sigue
        // visible. Vaciar el formulario de alguien porque el organizador
        // pauso la encuesta seria perder su trabajo sin preguntar.
        showClosedNotice(e?.status);
    });
}

function showClosedNotice(status) {
    if (document.querySelector('[data-closed-notice]')) return;

    const notice = document.createElement('div');
    notice.setAttribute('data-closed-notice', '');
    notice.setAttribute('role', 'alert');
    notice.className =
        'mt-4 rounded-md border-2 border-warning-800 bg-warning-50 px-4 py-3 text-sm text-warning-800';
    notice.textContent =
        status === 'paused'
            ? 'La encuesta se ha pausado. Espera un momento antes de enviar.'
            : 'La encuesta se ha cerrado. Puede que tu envio ya no se acepte.';

    document.querySelector('[data-survey-form]')?.prepend(notice);
}

/* ==========================================================================
   3. Heartbeat de presencia (D-12: cada 20 s)
   Se PAUSA con la pestana oculta: un movil en el bolsillo no esta "presente",
   y latir en segundo plano gastaria bateria y datos para falsear la metrica.
   ========================================================================== */
function initHeartbeat() {
    const form = document.querySelector('[data-survey-form]');
    const url = form?.dataset.heartbeatUrl;
    if (!url) return;

    const seconds = Number(form.dataset.heartbeatSeconds || 20);
    const token = document.querySelector('meta[name="csrf-token"]')?.content
        || form.querySelector('input[name="_token"]')?.value;

    let stopped = false;

    const beat = async () => {
        if (stopped || document.hidden) return;

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                credentials: 'same-origin',
            });

            // 410: la encuesta dejo de aceptar respuestas. El cliente DEBE
            // detener los heartbeats (API.md §2.3); seguir latiendo contra
            // una encuesta cerrada solo gasta bateria y llena el limitador.
            if (res.status === 410) {
                stopped = true;
                showClosedNotice('closed');
            }
        } catch (e) {
            // Un heartbeat perdido no es un error que mostrar: la sesion
            // simplemente expirara si no vuelve. Reintentar en silencio.
        }
    };

    beat();
    setInterval(beat, seconds * 1000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) beat();
    });
}

/* ==========================================================================
   4. Borrador en localStorage (brief §3.5)
   Conserva lo marcado ante una recarga o una reconexion. NO participa en el
   anti-duplicado (ADR-0005) y no se envia al servidor: es solo comodidad.
   ========================================================================== */
function initDraft() {
    const form = document.querySelector('[data-survey-form]');
    if (!form) return;

    const key = `el_draft_${form.dataset.publicId}`;

    try {
        const saved = JSON.parse(localStorage.getItem(key) || '{}');

        form.querySelectorAll('input, textarea').forEach((field) => {
            if (!field.name || field.type === 'hidden') return;
            const value = saved[field.name];
            if (value === undefined) return;

            if (field.type === 'checkbox' || field.type === 'radio') {
                if (Array.isArray(value)) field.checked = value.includes(field.value);
                else field.checked = value === field.value;
            } else {
                field.value = value;
            }
        });
    } catch (e) {
        /* Un borrador corrupto no debe impedir responder: se ignora. */
    }

    const save = () => {
        const data = {};
        form.querySelectorAll('input, textarea').forEach((field) => {
            if (!field.name || field.type === 'hidden') return;

            if (field.type === 'checkbox') {
                data[field.name] = data[field.name] || [];
                if (field.checked) data[field.name].push(field.value);
            } else if (field.type === 'radio') {
                if (field.checked) data[field.name] = field.value;
            } else {
                data[field.name] = field.value;
            }
        });

        try {
            localStorage.setItem(key, JSON.stringify(data));
        } catch (e) {
            /* Almacenamiento lleno o en modo privado: no es critico. */
        }
    };

    form.addEventListener('input', save);
    form.addEventListener('change', save);

    // Enviado con exito, el borrador se borra: dejarlo haria que la pantalla
    // de "ya respondiste" conservase datos que ya no sirven.
    form.addEventListener('submit', () => {
        try {
            localStorage.removeItem(key);
        } catch (e) { /* ignorado */ }
    });
}

/* ==========================================================================
   Arranque
   ========================================================================== */
function boot() {
    const echo = initEcho();
    initParticipantChannel(echo);
    initHeartbeat();
    initDraft();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
