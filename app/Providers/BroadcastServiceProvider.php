<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del enrutado de canales y del endpoint `/broadcasting/auth`.
 *
 * `/broadcasting/auth` es EL control que impide que un anonimo escuche el
 * dashboard (CA-12). Va detras de `web` + `auth` + `throttle:broadcast-auth`,
 * y la autorizacion fina de cada canal vive en routes/channels.php.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::routes([
            'middleware' => ['web', 'auth', 'throttle:broadcast-auth'],
        ]);

        require base_path('routes/channels.php');
    }
}
