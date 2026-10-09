<?php

use App\Support\BroadcastGuard;

/*
|--------------------------------------------------------------------------
| CONFIGURACION DE DIFUSION — PUBLICADA A PROPOSITO (F-07, Fase 7)
|--------------------------------------------------------------------------
|
| Este fichero NO existia en el proyecto: la aplicacion usaba el de
| `vendor/laravel/framework/config/broadcasting.php`, cuyo respaldo es
|
|     'default' => env('BROADCAST_CONNECTION', 'null'),
|
| es decir, el framework cae por defecto en un difusor **sin autorizacion de
| canal**. Combinado con `BROADCAST_CONNECTION=log` en la plantilla `.env`,
| el resultado era que la autorizacion de canales privados estaba desactivada
| de principio a fin, en el valor entregado y tambien en su respaldo.
|
| `LogBroadcaster::auth()` y `NullBroadcaster::auth()` tienen el cuerpo vacio:
| no ejecutan el callback de routes/channels.php y no lanzan excepcion, de
| modo que el llamante interpreta "autorizado". Verificado por el auditor
| contra los tres difusores: bajo `log`/`null` se autoriza a cualquiera,
| incluido un anonimo; bajo `reverb` la denegacion funciona perfectamente.
|
| Se publica el fichero con un UNICO cambio sustantivo respecto al del
| framework: el respaldo pasa a ser `reverb` (D-02, difusor primario). Asi, la
| peor configuracion posible —olvidar la variable de entorno— deja la
| autorizacion ACTIVA en lugar de apagada. Elegir `log` sigue siendo posible,
| pero ahora hay que escribirlo a mano y AppServiceProvider lo avisa (y en
| produccion se niega a arrancar).
|
| Ver app/Support/BroadcastGuard.php y
| tests/Feature/Security/BroadcastAuthorizationTest.php.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Difusor por defecto
    |--------------------------------------------------------------------------
    |
    | Soportados: "reverb", "pusher", "ably", "mercure", "redis", "log", "null".
    |
    | AVISO: "log" y "null" NO AUTORIZAN CANALES PRIVADOS. No son "tiempo real
    | desactivado": son "autorizacion desactivada". Para desarrollar sin Reverb
    | el respaldo correcto es el sondeo `wire:poll` de 5 s (ADR-0002), que
    | funciona con independencia del difusor porque el dashboard lee de la base
    | de datos, nunca del evento (D-02).
    |
    */

    'default' => env('BROADCAST_CONNECTION', BroadcastGuard::APPLICATION_DEFAULT),

    /*
    |--------------------------------------------------------------------------
    | Conexiones
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Si Reverb no esta arrancado, SafeBroadcaster captura el fallo
                // y el dashboard degrada a sondeo (D-02). Un tiempo de espera
                // corto evita que una difusion cuelgue la peticion del
                // participante mientras tanto.
                'timeout' => 3,
                'connect_timeout' => 2,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        /*
         * PELIGRO (F-07): los dos difusores siguientes NO AUTORIZAN NADA.
         * Se conservan porque el framework los ofrece y porque la suite los
         * usa para demostrar precisamente eso, pero no deben configurarse como
         * `default` en ningun entorno servido.
         */
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
