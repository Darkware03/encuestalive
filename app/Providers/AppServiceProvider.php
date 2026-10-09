<?php

namespace App\Providers;

use App\Models\Survey;
use App\Policies\SurveyPolicy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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
}
