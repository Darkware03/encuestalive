#!/bin/sh
# =============================================================================
# Health check de PHP-FPM  (NO VERIFICADO: nunca ejecutado en Docker)
# =============================================================================
# Comprueba que FPM EJECUTA PHP, no solo que el puerto 9000 acepta conexiones.
# La diferencia importa: un FPM con todos los hijos atascados sigue aceptando
# TCP y un `nc -z` lo daría por sano mientras el sitio devuelve 502.
#
# `ping.path = /fpm-ping` y `ping.response = pong` están en docker/php/www.conf.
set -eu

SCRIPT_NAME=/fpm-ping \
SCRIPT_FILENAME=/fpm-ping \
REQUEST_METHOD=GET \
cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q 'pong'
