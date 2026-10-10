#!/bin/sh
# =============================================================================
# EncuestaLive — entrypoint común de los contenedores PHP
#                (app · reverb · scheduler)
# =============================================================================
#
#   *** NO VERIFICADO: este script nunca se ha ejecutado dentro de Docker. ***
#   Validado solo con `sh -n` (análisis sintáctico) en el host de la fábrica.
#
# Principio: el entrypoint PREPARA el entorno y se aparta. No migra la base de
# datos, no crea usuarios y no toca datos. Las migraciones son un paso
# explícito y auditable del despliegue (DEPLOYMENT.md §5), no un efecto
# colateral de arrancar un contenedor.
#
#   ¿Por qué? Porque con varias réplicas, N contenedores arrancando a la vez
#   lanzarían N `migrate` concurrentes sobre la misma base de datos. Y porque
#   una migración que se ejecuta sola, sin que nadie la mire, es exactamente
#   la clase de cosa que destruye datos en silencio un viernes por la tarde.
#
set -eu

log() { printf '[entrypoint] %s\n' "$*" >&2; }

# -----------------------------------------------------------------------------
# 1. Comprobaciones de configuración que fallan RUIDOSAMENTE
# -----------------------------------------------------------------------------
# Arrancar mal configurado y descubrirlo tres días después por un informe de
# privacidad es mucho peor que no arrancar.

if [ -z "${APP_KEY:-}" ]; then
    log 'ERROR: APP_KEY está vacía.'
    log '       Genérala UNA vez con:  php artisan key:generate --show'
    log '       y pásala como variable de entorno/secreto. No la generes en'
    log '       el arranque: cambiaría en cada reinicio e invalidaría todas'
    log '       las cookies firmadas, incluido el token opaco de participante'
    log '       (ADR-0005), que es lo que impide respuestas duplicadas.'
    exit 1
fi

if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEBUG:-false}" = "true" ]; then
    log 'ERROR: APP_DEBUG=true con APP_ENV=production. (Hallazgo F-09, informe de seguridad.)'
    log '       Un error 500 renderizaría Ignition con la traza, el código'
    log '       fuente y un VOLCADO DE VARIABLES DE ENTORNO: APP_KEY y'
    log '       REVERB_APP_SECRET incluidos. Me niego a arrancar.'
    exit 1
fi

case "${BROADCAST_CONNECTION:-reverb}" in
    log|null)
        log 'ERROR: BROADCAST_CONNECTION='"${BROADCAST_CONNECTION}"'. (Hallazgo F-07, ALTO.)'
        log '       `LogBroadcaster::auth()` y `NullBroadcaster::auth()` tienen'
        log '       el cuerpo VACÍO: el callback de routes/channels.php nunca se'
        log '       ejecuta y se autoriza a cualquiera, anónimo incluido, a'
        log '       escuchar el canal privado del dashboard. Usa `reverb`.'
        exit 1
        ;;
esac

# -----------------------------------------------------------------------------
# 2. Esqueleto de `storage/`
# -----------------------------------------------------------------------------
# El volumen persistente se monta SOBRE `storage/`, lo que oculta los
# subdirectorios que la imagen traía. Si no se recrean, Laravel muere con
# «Please provide a valid cache path» y el mensaje no dice nada del volumen.
for d in \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/private \
    storage/app/public \
    bootstrap/cache
do
    mkdir -p "/var/www/html/$d"
done

# -----------------------------------------------------------------------------
# 3. Caché de configuración
# -----------------------------------------------------------------------------
# Se construye en el arranque y NO en el build de la imagen, porque depende de
# las variables de entorno inyectadas en el despliegue. Si se horneara en la
# imagen, `config:cache` congelaría los valores del momento de construir y
# cambiar una variable de entorno dejaría de tener efecto: un fallo
# desconcertante y clásico.
#
# `config:clear` antes, por si quedó una caché de una versión anterior en el
# volumen.
if [ "${ENCUESTALIVE_SKIP_OPTIMIZE:-0}" != "1" ]; then
    log 'Regenerando caché de configuración, rutas y vistas…'
    php artisan config:clear  >/dev/null 2>&1 || true
    php artisan config:cache  >/dev/null
    php artisan route:cache   >/dev/null
    php artisan view:cache    >/dev/null
    php artisan event:cache   >/dev/null 2>&1 || true
fi

# -----------------------------------------------------------------------------
# 4. Espera activa de la base de datos
# -----------------------------------------------------------------------------
# El `depends_on: service_healthy` de Compose ya cubre el caso normal, pero no
# cubre que MySQL se reinicie con los contenedores vivos. Esta espera es corta
# y barata.
if [ "${ENCUESTALIVE_WAIT_FOR_DB:-1}" = "1" ] && [ "${DB_CONNECTION:-mysql}" != "sqlite" ]; then
    i=0
    until php -r '
        $h = getenv("DB_HOST") ?: "db";
        $p = (int) (getenv("DB_PORT") ?: 3306);
        $s = @fsockopen($h, $p, $e, $m, 2);
        exit($s ? 0 : 1);
    ' 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 30 ]; then
            log "ERROR: la base de datos (${DB_HOST:-db}:${DB_PORT:-3306}) no responde tras 60 s."
            exit 1
        fi
        log "Esperando a la base de datos… ($i/30)"
        sleep 2
    done
    log 'Base de datos alcanzable.'
fi

log "Arrancando: $*"
exec "$@"
