<?php

namespace App\Providers;

use App\Models\Survey;
use App\Policies\SurveyPolicy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
         * El QR codifica `{APP_URL}/s/{publicId}` y debe apuntar a la misma
         * URL que la aplicacion sirve. Forzar la raiz a APP_URL evita el fallo
         * clasico: generar el QR detras de un proxy y acabar con `localhost`
         * impreso en un cartel, que no abre nada desde un movil ajeno.
         */
        if (is_string($appUrl = config('app.url')) && $appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }
    }
}
