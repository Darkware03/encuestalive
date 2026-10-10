#!/bin/sh
# =============================================================================
# EncuestaLive — EL SERVICIO QUE FALTABA: invocación de `schedule:run`
# =============================================================================
#
#   *** NO VERIFICADO EN DOCKER. Sí verificada la lógica equivalente a mano
#       en el host de la fábrica: ver OPERATIONS.md §7. ***
#
# POR QUÉ EXISTE ESTE FICHERO
# ---------------------------
# El hallazgo F-13 (ALTO) del informe de seguridad decía que la retención de
# 30 días no se ejecutaba nunca. La Fase 7b corrigió la mitad del problema:
# `routes/console.php` ya REGISTRA las dos purgas y `php artisan schedule:list`
# ya no está vacío.
#
# Pero registrar una tarea no la ejecuta. El planificador de Laravel es un
# despachador que alguien tiene que invocar CADA MINUTO. Sin eso, el único
# efecto de la Fase 7b es que la tarea aparece en una lista.
#
# Y esto no es higiene técnica. `resources/views/public/privacy.blade.php` le
# dice al participante, en la página pública de privacidad, que sus registros
# se borran a los N días. Si nadie llama a `schedule:run`, esa frase es
# FALSA y la tabla `security_events` —el ÚNICO almacén de direcciones IP del
# sistema— crece sin límite temporal.
#
#   Una promesa de privacidad incumplida es peor que no haberla hecho.
#
# Este bucle es, literalmente, el eslabón que convierte esa promesa en verdad.
#
# POR QUÉ UN BUCLE Y NO CRON
# --------------------------
# Meter un demonio de cron dentro de un contenedor obliga a cargar un
# supervisor, a redirigir su salida a stdout a mano y a lidiar con que crond
# se traga los códigos de salida. Un bucle `sleep` en PID 1 hace lo mismo con
# una cuarta parte de las piezas, escribe en stdout sin trucos y muere limpio
# con SIGTERM. La alternativa SIN Docker (una entrada de crontab real) está
# documentada en OPERATIONS.md §7.2 y DEPLOYMENT.md §5.
#
set -eu

HEARTBEAT="${ENCUESTALIVE_SCHEDULER_HEARTBEAT:-/tmp/scheduler-heartbeat}"

log() { printf '[scheduler] %s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }

terminar() {
    log 'SIGTERM recibido; saliendo tras la iteración actual.'
    exit 0
}
trap terminar TERM INT

log 'Planificador en marcha. Invocando `schedule:run` cada 60 s.'
log 'Tareas esperadas (routes/console.php):'
log '  · 03:15 UTC  encuestalive:prune-security-events  (D-12, F-13)'
log '  · 03:30 UTC  encuestalive:prune-sessions         (RN-09.5)'

# Aviso temprano y ruidoso: si el planificador está vacío, este contenedor
# estaría girando en balde y la retención seguiría sin ejecutarse. Preferible
# gritarlo en el primer segundo que descubrirlo dentro de ocho meses.
if ! php artisan schedule:list 2>/dev/null | grep -q 'encuestalive:prune-security-events'; then
    log 'AVISO GRAVE: `schedule:list` no contiene encuestalive:prune-security-events.'
    log '             La retención de 30 días NO se ejecutará y la página'
    log '             pública de privacidad estaría prometiendo algo falso.'
    log '             Revisa routes/console.php y la caché de configuración.'
fi

while true; do
    inicio=$(date +%s)

    # `schedule:run` decide por sí mismo qué toca ejecutar en este minuto; casi
    # siempre no hace nada y sale en milisegundos. Su salida va a stdout para
    # que `docker logs` la recoja.
    #
    # `|| true`: si una purga falla (por ejemplo, la base de datos está
    # reiniciándose), NO queremos que muera el contenedor y se pierda el
    # planificador entero. El fallo queda registrado y se reintenta al minuto.
    php artisan schedule:run || log 'schedule:run terminó con error (ver salida arriba).'

    # Latido para el health check: si este fichero deja de actualizarse, el
    # bucle está colgado aunque el proceso siga vivo.
    date -u '+%s' > "$HEARTBEAT"

    # Deriva: descontamos lo que tardó la iteración para no desplazarnos.
    transcurrido=$(( $(date +%s) - inicio ))
    espera=$(( 60 - transcurrido ))
    [ "$espera" -lt 1 ] && espera=1
    sleep "$espera" &
    wait $!
done
