#!/usr/bin/env bash
# ============================================================================
# CA-15 — "Las respuestas persisten tras reiniciar servicios de aplicacion y
#          base de datos"
# ============================================================================
#
# Prueba FORMAL, con el guion que pedia la Fase 6:
#   1. Datos presentes      -> se cuentan y se congela una huella (SHA-256).
#   2. Se detienen AMBOS servicios (aplicacion y Reverb) y se comprueba que
#      los puertos dejan de responder. Sin esta comprobacion, "reiniciar"
#      podria no haber parado nada y la prueba pasaria sola.
#   3. Se arrancan de nuevo.
#   4. Las respuestas y los contadores siguen siendo EXACTAMENTE los mismos.
#
# SOBRE "REINICIAR LA BASE DE DATOS": aqui el motor es SQLite, que no es un
# servicio sino un fichero abierto por el proceso. No hay demonio que parar.
# Lo que SI se hace, y es el equivalente honesto, es:
#   · cerrar todos los procesos que mantienen abierto el fichero (y por tanto
#     su conexion WAL), y
#   · forzar un checkpoint WAL para comprobar que lo confirmado esta en el
#     fichero principal y no solo en el diario.
# La diferencia con MySQL 8 (destino declarado en D-04) queda escrita en
# TEST_RESULTS.md como limitacion, no como aprobado.
#
# Uso: tools/f6/ca15-restart.sh

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUN="$ROOT/storage/app/f6"
export DB_DATABASE="${DB_DATABASE:-$RUN/e2e.sqlite}"
export BROADCAST_CONNECTION=reverb

# shellcheck disable=SC1091
source /software-factory/.toolchain/env.sh
cd "$ROOT"

EVIDENCIA="$RUN/ca15-evidencia.txt"
: > "$EVIDENCIA"

registrar() { echo "$@" | tee -a "$EVIDENCIA"; }

# Instantanea de TODO lo que no puede cambiar por un reinicio.
instantanea() {
  php artisan tinker --execute='
    $s = App\Models\Survey::where("public_id", "E2E0ACTVA0000001")->firstOrFail();
    $stats = app(App\Domain\Stats\StatsService::class)->snapshot($s);
    $filas = App\Models\Response::where("survey_id", $s->id)->orderBy("id")
        ->get(["id","submission_uuid","completed_at"])
        ->map(fn($r) => $r->id."|".$r->submission_uuid."|".$r->completed_at)
        ->implode("\n");
    echo "respuestas=".App\Models\Response::where("survey_id",$s->id)->count().PHP_EOL;
    echo "respuestas_totales=".App\Models\Response::count().PHP_EOL;
    echo "sesiones=".App\Models\ParticipantSession::where("survey_id",$s->id)->count().PHP_EOL;
    echo "selecciones=".App\Models\ResponseSelection::count().PHP_EOL;
    echo "textos=".App\Models\ResponseAnswer::whereNotNull("text_answer")->count().PHP_EOL;
    echo "completadas_stats=".$stats->completedResponses.PHP_EOL;
    echo "participaron_stats=".$stats->participatedSessions.PHP_EOL;
    echo "iniciadas_stats=".$stats->sessionsStarted.PHP_EOL;
    echo "huella_respuestas=".hash("sha256",$filas).PHP_EOL;
  ' 2>/dev/null | grep -E '^[a-z_]+='
}

registrar "=============================================================="
registrar "CA-15 · persistencia tras reiniciar servicios"
registrar "fecha: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
registrar "base de datos: $DB_DATABASE"
registrar "=============================================================="

# ---------------------------------------------------------------- 1. ANTES
registrar ""
registrar "--- 1. ESTADO ANTES DEL REINICIO -----------------------------"
tools/f6/services.sh status 2>&1 | tee -a "$EVIDENCIA"
registrar "salud: $(curl -s -m 5 http://127.0.0.1:8000/health)"
ANTES="$(instantanea)"
registrar "$ANTES"

registrar ""
registrar "modo del diario SQLite y estado WAL:"
php artisan tinker --execute='
  echo "journal_mode=".DB::select("PRAGMA journal_mode")[0]->journal_mode.PHP_EOL;
  echo "foreign_keys=".DB::select("PRAGMA foreign_keys")[0]->foreign_keys.PHP_EOL;
' 2>/dev/null | grep -E '^[a-z_]+=' | tee -a "$EVIDENCIA"

# ------------------------------------------------------------- 2. PARADA
registrar ""
registrar "--- 2. PARADA DE AMBOS SERVICIOS -----------------------------"
tools/f6/services.sh stop 2>&1 | tee -a "$EVIDENCIA"

# Checkpoint WAL con los servicios caidos: lo confirmado debe estar en el
# fichero principal, no solo en el diario.
registrar ""
registrar "checkpoint WAL con los servicios parados:"
php artisan tinker --execute='
  $r = DB::select("PRAGMA wal_checkpoint(TRUNCATE)")[0];
  echo "wal_checkpoint_busy=".$r->busy." log=".$r->log." checkpointed=".$r->checkpointed.PHP_EOL;
' 2>/dev/null | grep -E '^wal' | tee -a "$EVIDENCIA"

registrar ""
registrar "comprobacion de que los puertos NO responden:"
if curl -s -m 3 -o /dev/null http://127.0.0.1:8000/health; then
  registrar "  8000 -> SIGUE RESPONDIENDO (la parada fallo)"; PARADA_OK=0
else
  registrar "  8000 -> sin respuesta (correcto)"; PARADA_OK=1
fi
if curl -s -m 3 -o /dev/null http://127.0.0.1:8080/app/x; then
  registrar "  8080 -> SIGUE RESPONDIENDO (la parada fallo)"; PARADA_OK=0
else
  registrar "  8080 -> sin respuesta (correcto)"
fi

# ------------------------------------------------------------ 3. ARRANQUE
registrar ""
registrar "--- 3. ARRANQUE DE NUEVO -------------------------------------"
tools/f6/services.sh start 2>&1 | tee -a "$EVIDENCIA"
sleep 3

# -------------------------------------------------------------- 4. DESPUES
registrar ""
registrar "--- 4. ESTADO DESPUES DEL REINICIO ---------------------------"
registrar "salud: $(curl -s -m 5 http://127.0.0.1:8000/health)"
DESPUES="$(instantanea)"
registrar "$DESPUES"

registrar ""
registrar "--- VEREDICTO ------------------------------------------------"
if [ "$ANTES" = "$DESPUES" ] && [ "${PARADA_OK:-1}" = "1" ]; then
  registrar "CA-15 APROBADO: contadores, respuestas y huella IDENTICOS"
  registrar "                tras parar y arrancar aplicacion y Reverb."
  RESULTADO=0
else
  registrar "CA-15 FALLIDO: hay diferencias antes/despues."
  diff <(echo "$ANTES") <(echo "$DESPUES") | tee -a "$EVIDENCIA"
  RESULTADO=1
fi

registrar ""
registrar "evidencia completa en: $EVIDENCIA"
exit $RESULTADO
