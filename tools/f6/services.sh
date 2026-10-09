#!/usr/bin/env bash
# tools/f6/services.sh — arranque/parada de los servicios reales para los E2E.
#
# Dos servicios, dos procesos, como en produccion:
#   · servidor de aplicacion  (php artisan serve)   -> 127.0.0.1:8000
#   · difusor de tiempo real  (php artisan reverb:start) -> 127.0.0.1:8080
#
# BROADCAST_CONNECTION=reverb se exporta en el ENTORNO, no se edita .env:
# `.env` esta fuera del ambito de escritura de QA y Laravel carga dotenv en
# modo inmutable, asi que la variable de entorno GANA sobre el fichero.
# Verificado antes de usarlo.
#
# ======================= POR QUE NO SE USA `artisan serve` =======================
# `php artisan serve` NO propaga el entorno a su proceso hijo. ServeCommand tiene
# una lista blanca estatica `$passthroughVariables` (APP_ENV, PATH, XDEBUG_*, ...)
# y DESCARTA todo lo demas. Con `artisan serve`, DB_DATABASE y
# BROADCAST_CONNECTION se perdian: el servidor leia la base de datos de
# desarrollo (todas las encuestas del fixture daban 404) y /health declaraba
# `broadcasting: fallback` aun con Reverb arrancado.
#
# Diagnosticado leyendo ServeCommand.php:82-97. Se arranca por tanto el servidor
# embebido de PHP con el MISMO script de enrutado que usa el framework, que si
# hereda el entorno completo.
#
# PHP_CLI_SERVER_WORKERS: sin esto el servidor embebido atiende UNA peticion a la
# vez y las pruebas de concurrencia medirian la serializacion del servidor de
# pruebas, no el comportamiento de la aplicacion.
# =================================================================================
#
# CA-15 usa `stop` y `start` sin `--fresh` para demostrar que los datos
# sobreviven al reinicio de AMBOS servicios.
#
# Uso: tools/f6/services.sh start|stop|status|restart

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUN="$ROOT/storage/app/f6"
mkdir -p "$RUN"

export DB_DATABASE="${DB_DATABASE:-$RUN/e2e.sqlite}"
export BROADCAST_CONNECTION=reverb
export APP_URL="${APP_URL:-http://127.0.0.1:8000}"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-10}"

# Enrutador propio con ruta publica absoluta. El del framework usa getcwd() y
# fallaba de forma intermitente con varios workers (ver tools/f6/router.php).
ROUTER="$ROOT/tools/f6/router.php"

# shellcheck disable=SC1091
source /software-factory/.toolchain/env.sh

start_one() {
  local name="$1"; shift
  local pidfile="$RUN/$name.pid"
  if [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
    echo "[$name] ya en ejecucion (pid $(cat "$pidfile"))"; return 0
  fi
  ( cd "$ROOT" && nohup "$@" >"$RUN/$name.log" 2>&1 & echo $! >"$pidfile" )
  sleep 1
  echo "[$name] arrancado (pid $(cat "$pidfile"))"
}

stop_one() {
  local name="$1"
  local pidfile="$RUN/$name.pid"
  if [ ! -f "$pidfile" ]; then echo "[$name] no estaba arrancado"; return 0; fi
  local pid; pid="$(cat "$pidfile")"
  # El servidor embebido de PHP crea hijos: se mata el grupo entero.
  pkill -P "$pid" 2>/dev/null
  kill "$pid" 2>/dev/null
  sleep 1
  kill -9 "$pid" 2>/dev/null
  rm -f "$pidfile"
  echo "[$name] detenido (pid $pid)"
}

case "${1:-}" in
  start)
    start_one reverb php artisan reverb:start --host=127.0.0.1 --port=8080
    start_one serve  php -S 127.0.0.1:8000 -t "$ROOT/public" "$ROUTER"
    sleep 3
    echo "--- comprobacion ---"
    curl -s -o /dev/null -w "serve  /health -> HTTP %{http_code}\n" http://127.0.0.1:8000/health
    curl -s -o /dev/null -w "reverb /app     -> HTTP %{http_code}\n" http://127.0.0.1:8080/app/test
    ;;
  stop)
    stop_one serve
    stop_one reverb
    # Red de seguridad: nada escuchando en los dos puertos.
    pkill -f "php -S 127.0.0.1:8000" 2>/dev/null
    pkill -f "artisan reverb:start" 2>/dev/null
    sleep 1
    echo "--- puertos tras parar ---"
    (curl -s -m 2 -o /dev/null -w "8000 -> %{http_code}\n" http://127.0.0.1:8000/health || echo "8000 -> sin respuesta (correcto)")
    (curl -s -m 2 -o /dev/null -w "8080 -> %{http_code}\n" http://127.0.0.1:8080/app/test || echo "8080 -> sin respuesta (correcto)")
    ;;
  status)
    for n in serve reverb; do
      p="$RUN/$n.pid"
      if [ -f "$p" ] && kill -0 "$(cat "$p")" 2>/dev/null; then echo "[$n] vivo pid $(cat "$p")"; else echo "[$n] parado"; fi
    done
    ;;
  restart) "$0" stop; sleep 1; "$0" start ;;
  *) echo "uso: $0 start|stop|status|restart"; exit 2 ;;
esac
