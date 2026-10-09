<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\QrController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Public\ParticipationController;
use App\Livewire\Admin\LiveDashboard;
use App\Livewire\Admin\SurveyEditor;
use App\Livewire\Admin\SurveyList;
use App\Models\Survey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de EncuestaLive
|--------------------------------------------------------------------------
|
| Tres superficies, con pilas de middleware deliberadamente distintas
| (ARCHITECTURE.md §5.3):
|
|   · PUBLICA del participante  — sin auth, cookie opaca, limites por token
|   · ADMINISTRATIVA            — sesion, policies por recurso
|   · DESARROLLO                — solo en entorno local
|
| El patron de {publicId} se restringe al alfabeto Base32 Crockford de
| ADR-0006: una ruta que no encaja ni llega al controlador.
|
*/

Route::get('/health', HealthController::class)
    ->middleware('throttle:health')
    ->name('health');

/*
|--------------------------------------------------------------------------
| Participante (anonimo)
|--------------------------------------------------------------------------
|
| `survey.public` resuelve el publicId (404 indistinguible para borrador,
| archivada e inexistente) y `survey.participant` emite o reanuda la cookie
| opaca con Path=/s/{publicId}.
|
| Las rutas de ESCRITURA anaden `survey.accepts`, que es la puerta unica de
| CA-13: una encuesta cerrada o pausada se rechaza AQUI, en el servidor, con
| 410, aunque la peticion llegue con curl y sin pasar por la interfaz.
|
*/
Route::prefix('s/{publicId}')
    ->middleware(['survey.public', 'survey.participant'])
    ->group(function (): void {
        Route::get('/', [ParticipationController::class, 'show'])
            ->middleware('throttle:public-open')
            ->name('public.survey.show');

        Route::post('/session', [ParticipationController::class, 'session'])
            ->middleware('throttle:session-issue')
            ->name('public.survey.session');

        Route::get('/state', [ParticipationController::class, 'state'])
            ->middleware('throttle:public-state')
            ->name('public.survey.state');

        Route::post('/heartbeat', [ParticipationController::class, 'heartbeat'])
            ->middleware(['throttle:heartbeat', 'survey.accepts'])
            ->name('public.survey.heartbeat');

        Route::post('/responses', [ParticipationController::class, 'store'])
            ->middleware(['throttle:response-submit', 'survey.accepts'])
            ->name('public.survey.responses');

        // Envio sin JavaScript (progresion, wireframes-states 16.d).
        Route::post('/enviar', [ParticipationController::class, 'storeForm'])
            ->middleware(['throttle:response-submit', 'survey.accepts'])
            ->name('public.survey.submit');
    })
    ->where(['publicId' => Survey::PUBLIC_ID_PATTERN]);

/*
|--------------------------------------------------------------------------
| Autenticacion administrativa
|--------------------------------------------------------------------------
|
| SIN ruta `register` y SIN recuperacion por correo, a proposito
| (ARCHITECTURE.md §5.1). Las cuentas se crean con
| `php artisan encuestalive:crear-admin`.
|
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');

    Route::post('/login', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::post('/logout', [AuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Zona administrativa
|--------------------------------------------------------------------------
|
| Un anonimo recibe 302 a /login desde `auth` y NUNCA datos parciales
| (RF-59, CA-12). La autorizacion fina por encuesta la aplica SurveyPolicy
| dentro de cada componente y controlador, no esta pila.
|
*/
Route::middleware(['auth', 'throttle:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', SurveyList::class)->name('dashboard');
        Route::get('/encuestas', SurveyList::class)->name('surveys.index');
        Route::get('/historial', SurveyList::class)->defaults('archived', true)->name('surveys.history');

        Route::get('/encuestas/{survey}/editar', SurveyEditor::class)->name('surveys.edit');
        Route::get('/encuestas/{survey}/resultados', LiveDashboard::class)->name('surveys.results');

        Route::get('/encuestas/{survey}/qr.{format}', QrController::class)
            ->middleware('throttle:qr')
            ->name('surveys.qr');

        Route::get('/encuestas/{survey}/export.csv', ExportController::class)
            ->middleware('throttle:export')
            ->name('surveys.export');
    });

/*
|--------------------------------------------------------------------------
| Galeria de componentes — SOLO EN LOCAL
|--------------------------------------------------------------------------
|
| Peticion explicita de `frontend-engineer` en la Fase 5a: la galeria existia
| (resources/views/components/gallery.blade.php) pero `routes/` estaba fuera
| de su ambito, asi que habia que renderizarla a HTML estatico con
| `php tools/render-gallery.php` para poder verla.
|
| `App::environment('local')` y no una variable suelta: el banco de pruebas
| del sistema de diseno no debe ser alcanzable en produccion. No contiene
| datos reales, pero es superficie que no aporta nada a quien participa.
|
*/
if (app()->environment('local')) {
    Route::get('/dev/galeria', fn () => view('layouts.gallery', [
        'slot' => new Illuminate\Support\HtmlString(
            Illuminate\Support\Facades\Blade::render('<x-gallery />')
        ),
    ]))->name('dev.gallery');
}

Route::get('/privacidad', fn () => view('public.privacy'))->name('privacy');

Route::get('/', fn () => redirect()->route(auth()->check() ? 'admin.dashboard' : 'login'));
