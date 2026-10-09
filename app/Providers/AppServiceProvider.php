<?php

namespace App\Providers;

use App\Models\Survey;
use App\Policies\SurveyPolicy;
use App\Support\BroadcastGuard;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Survey::class, SurveyPolicy::class);

        $this->guardAgainstUnauthorizingBroadcaster();
        $this->guardAgainstDatabaseSessionStore();

        /*
         * Los tres layouts de `frontend-engineer` viven en views/layouts/ y
         * declaran @props, es decir, son componentes anonimos. Livewire los
         * resuelve por nombre de vista con #[Layout], pero una vista Blade
         * normal necesita este registro para poder escribir
         * <x-layouts.participant>.
         *
         * Se registra la ruta existente en lugar de mover los ficheros:
         * mi ambito dice consumir esos layouts, no reubicarlos.
         */
        Blade::anonymousComponentPath(resource_path('views/layouts'), 'layouts');

        /*
         * NO se usa URL::forceRootUrl(config('app.url')).
         *
         * Lo tuve puesto y la verificacion visual con navegador lo destapo:
         * forzaba TODAS las URLs generadas al host de APP_URL, asi que al
         * servir en 127.0.0.1:8000 con APP_URL=localhost:8000 el formulario
         * publico recibia action="http://localhost:8000/...", que para el
         * navegador es OTRO ORIGEN. La propia CSP lo bloqueaba:
         *
         *   · `form-action 'self'`  -> el envio no salia (clic sin efecto)
         *   · `default-src 'self'`  -> ni el CSS ni el JS cargaban
         *
         * La pagina se veia "bien" en una captura rapida y estaba rota. La
         * suite no podia verlo: las pruebas HTTP no aplican CSP.
         *
         * Sin forzar la raiz, Laravel genera las URLs con el host de la
         * peticion y todo queda en el mismo origen. El QR NO depende de esto:
         * QrCodeService construye la URL publica leyendo config('app.url')
         * de forma explicita, que es donde ese control debe vivir.
         */
    }

    /**
     * F-07 (ALTO, Fase 7): fallar RUIDOSAMENTE si el difusor configurado no
     * autoriza canales privados.
     *
     * El hallazgo no fue que la autorizacion estuviera mal escrita —esta
     * bien—, sino que con `log`/`null` NI SIQUIERA SE EJECUTA, y nada lo
     * delataba: ni un aviso en arranque, ni una linea en el registro. El
     * sistema parecia funcionar con normalidad mientras autorizaba a
     * cualquiera.
     *
     * En produccion se lanza y la aplicacion no arranca. Desplegar con la
     * autorizacion de canal apagada es peor que no desplegar.
     *
     * Fuera de produccion solo se avisa por el registro: `log` sigue siendo
     * una eleccion legitima para depurar la carga util de los eventos, pero
     * quien la toma debe ver por escrito lo que apaga. En `testing` se calla,
     * porque phpunit.xml fija el difusor a proposito y el aviso solo seria
     * ruido en cada ejecucion de la suite.
     */
    /**
     * F-H1 (Fase 7): impedir que vuelva a existir un SEGUNDO almacen de IP.
     *
     * Con `SESSION_DRIVER=database`, la tabla `sessions` DEL FRAMEWORK guarda
     * `ip_address` y `user_agent` de todo visitante, participantes anonimos
     * incluidos. D-11 lo resolvio fijando `file`, y la Fase 7b ha eliminado
     * ademas esas columnas del esquema para que la propiedad sea estructural.
     *
     * Sin este aviso, cambiar el driver producia un error SQL incomprensible
     * ("no such column: ip_address") en lugar de explicar la regla. Aqui se
     * dice en voz alta por que no se puede, que es lo que permite tomar una
     * decision informada en vez de pelearse con el sintoma.
     *
     * Se avisa y no se lanza fuera de produccion para no bloquear a quien
     * experimente; en produccion seria desplegar el almacen de IP que la
     * arquitectura entera existe para evitar.
     */
    private function guardAgainstDatabaseSessionStore(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        if (config('session.driver') !== 'database') {
            return;
        }

        $message = 'SESSION_DRIVER=database reintroduce un SEGUNDO almacen de direcciones IP '
            .'(sessions.ip_address / user_agent) para TODO visitante, participantes anonimos '
            .'incluidos. Lo prohibe D-11 y contradice el brief §5.2. Usa SESSION_DRIVER=file. '
            .'Las columnas ya no existen en el esquema (hallazgo F-H1).';

        if ($this->app->environment('production')) {
            throw new \RuntimeException($message);
        }

        Log::warning('session_driver_reintroduces_ip_store', ['reason' => $message]);
    }

    private function guardAgainstUnauthorizingBroadcaster(): void
    {
        $environment = (string) $this->app->environment();

        if ($environment === 'testing') {
            return;
        }

        $warning = BroadcastGuard::assertSafeForEnvironment($environment);

        if ($warning !== null) {
            Log::warning('broadcast_authorization_disabled', ['reason' => $warning]);
        }
    }
}
