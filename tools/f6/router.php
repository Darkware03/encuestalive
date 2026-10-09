<?php

/*
 * tools/f6/router.php — enrutador del servidor embebido de PHP para los E2E.
 *
 * Equivale al `server.php` del framework, con UNA diferencia que importa: la
 * ruta publica es ABSOLUTA y derivada de __DIR__, no de getcwd().
 *
 * El de Laravel hace `$publicPath = getcwd()`. Con `php -S -t public` lanzado
 * desde la raiz del proyecto, getcwd() no es `public/` de forma fiable (y con
 * PHP_CLI_SERVER_WORKERS cada worker puede diferir), asi que `/health` acababa
 * en `require '<raiz>/index.php'` y moria con un fatal, mientras que otras
 * rutas si funcionaban. Un servidor de pruebas que falla de forma intermitente
 * produce resultados de prueba que no valen nada.
 *
 * No se usa para nada mas que para servir la aplicacion en las pruebas.
 */

$publicPath = dirname(__DIR__, 2) . '/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// Ficheros reales (assets compilados, favicon) los sirve el servidor embebido.
if ($uri !== '/' && is_file($publicPath . $uri)) {
    return false;
}

require_once $publicPath . '/index.php';
