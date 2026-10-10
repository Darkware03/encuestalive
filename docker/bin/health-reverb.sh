#!/bin/sh
# =============================================================================
# Health check de Reverb  (NO VERIFICADO: nunca ejecutado en Docker)
# =============================================================================
#
# HONESTIDAD SOBRE EL ALCANCE DE ESTA COMPROBACIÓN
# ------------------------------------------------
# Esto verifica que el proceso ACEPTA CONEXIONES TCP en su puerto. NO verifica
# que complete el apretón de manos de WebSocket ni que emita el frame
# `pusher:connection_established`. Para eso haría falta un cliente WebSocket
# dentro de la imagen, y añadirlo por un health check no compensa.
#
# No se usa una petición HTTP contra la API de Reverb porque sus rutas
# (`/apps/{id}/events`) exigen firma con `REVERB_APP_SECRET`, y meter un
# secreto en un health check que se ejecuta cada 15 s y se registra en los
# logs del demonio sería un intercambio pésimo.
#
# Un fallo de Reverb NO es una caída del servicio: D-02 y DEF-01 garantizan que
# el dashboard sigue sondeando la base de datos cada 15 s. Por eso este
# contenedor se marca `restart: unless-stopped` pero NADIE depende de su salud
# para arrancar.
set -eu

php -r '
    $puerto = (int) (getenv("REVERB_SERVER_PORT") ?: getenv("REVERB_PORT") ?: 8080);
    $sock = @fsockopen("127.0.0.1", $puerto, $errno, $errstr, 3);
    if (! $sock) {
        fwrite(STDERR, "Reverb no acepta TCP en 127.0.0.1:$puerto ($errstr)\n");
        exit(1);
    }
    fclose($sock);
    exit(0);
'
