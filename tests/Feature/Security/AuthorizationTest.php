<?php

namespace Tests\Feature\Security;

use App\Models\Survey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * CA-12 — un anonimo no accede a funcionalidades administrativas ni a canales
 * protegidos, y un administrador no accede a encuestas AJENAS.
 *
 * La segunda mitad importa tanto como la primera: "esta autenticado" nunca es
 * suficiente (ARCHITECTURE.md §5.2, riesgo R-09).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    /**
     * Fuerza un difusor REAL para las pruebas de canal.
     *
     * HALLAZGO, reportado en el informe: `phpunit.xml` fija
     * BROADCAST_CONNECTION=null, y el NullBroadcaster de Laravel tiene
     * `auth()` VACIO. El driver `log` tampoco autoriza. Con cualquiera de los
     * dos, `/broadcasting/auth` devuelve 200 a cualquier usuario autenticado
     * y el callback de routes/channels.php NI SIQUIERA SE EJECUTA.
     *
     * Una prueba de CA-12 sobre el driver `null` habria pasado sin demostrar
     * nada. Aqui se configura el driver `reverb` (que usa PusherBroadcaster),
     * que es el unico camino en el que la autorizacion de canal se aplica de
     * verdad, y es el que se usara en despliegue.
     *
     * SEGUNDA TRAMPA, detectada al depurar: `Broadcast::channel()` registra
     * el canal EN LA INSTANCIA del difusor activo en ese momento. Los canales
     * se registraron durante el arranque, sobre el NullBroadcaster; al
     * cambiar la configuracion despues, el contenedor crea un
     * PusherBroadcaster NUEVO y SIN NINGUN CANAL, de modo que todo devuelve
     * 403.
     *
     * Eso habria dado un falso positivo perfecto: la prueba del intruso
     * pasaba en verde, pero por no existir el canal, no por la policy. Por
     * eso se vuelven a cargar los canales sobre el difusor nuevo Y se
     * comprueba tambien el caso POSITIVO del propietario: sin esa segunda
     * mitad, una denegacion universal se leeria como seguridad.
     */
    private function withRealBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.driver' => 'reverb',
            'broadcasting.connections.reverb.key' => 'clave-de-prueba',
            'broadcasting.connections.reverb.secret' => 'secreto-de-prueba',
            'broadcasting.connections.reverb.app_id' => 'app-de-prueba',
            'broadcasting.connections.reverb.options' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);

        // Se descarta el difusor cacheado y se registran los canales reales
        // sobre el nuevo. Se carga routes/channels.php, no una copia.
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    // ------------------------------------------------- anonimo vs admin

    #[DataProvider('adminRoutes')]
    public function test_an_anonymous_visitor_cannot_reach_admin_routes(string $route): void
    {
        $survey = Survey::factory()->published()->create();
        $uri = str_replace('{survey}', (string) $survey->id, $route);

        $response = $this->get($uri);

        // 302 al login, y NUNCA datos parciales (RF-59).
        $response->assertRedirect(route('login'));
        $response->assertDontSee($survey->title);
    }

    public static function adminRoutes(): array
    {
        return [
            'panel' => ['/admin'],
            'listado' => ['/admin/encuestas'],
            'historial' => ['/admin/historial'],
            'editor' => ['/admin/encuestas/{survey}/editar'],
            'resultados' => ['/admin/encuestas/{survey}/resultados'],
            'qr png' => ['/admin/encuestas/{survey}/qr.png'],
            'exportacion' => ['/admin/encuestas/{survey}/export.csv'],
        ];
    }

    public function test_an_anonymous_visitor_cannot_authorize_a_private_channel(): void
    {
        $survey = Survey::factory()->published()->create();

        // /broadcasting/auth es EL control que impide que un anonimo escuche
        // el dashboard (API.md §3.3).
        $this->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-survey.' . $survey->public_id . '.admin',
        ])->assertRedirect(route('login'));
    }

    // ------------------------------------------ admin sobre encuesta ajena

    public function test_an_admin_cannot_view_another_owners_survey(): void
    {
        $intruder = User::factory()->create();
        $survey = Survey::factory()->published()->create();

        $this->actingAs($intruder)
            ->get('/admin/encuestas/' . $survey->id . '/editar')
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get('/admin/encuestas/' . $survey->id . '/resultados')
            ->assertForbidden();
    }

    public function test_an_admin_cannot_export_another_owners_survey(): void
    {
        $intruder = User::factory()->create();
        $built = $this->publishedSurveyWithAllTypes();

        $this->actingAs($intruder)
            ->get(route('admin.surveys.export', $built['survey']))
            ->assertForbidden();
    }

    public function test_an_admin_cannot_download_another_owners_qr(): void
    {
        $intruder = User::factory()->create();
        $survey = Survey::factory()->published()->create();

        $this->actingAs($intruder)
            ->get('/admin/encuestas/' . $survey->id . '/qr.png')
            ->assertForbidden();
    }

    /**
     * El canal privado debe comprobar LA ENCUESTA CONCRETA, no solo que haya
     * sesion. Si devolviera `true` por estar autenticado, un segundo admin
     * veria presencia y texto libre en vivo de una encuesta ajena (R-09).
     *
     * Se ataca el endpoint REAL `/broadcasting/auth`, no el callback aislado:
     * lo que protege al sistema es la ruta completa con su middleware, y es
     * eso lo que debe quedar demostrado.
     */
    public function test_the_private_channel_authorization_checks_survey_ownership(): void
    {
        $this->withRealBroadcaster();

        $owner = $this->owner();
        $intruder = User::factory()->create();

        $survey = Survey::factory()->published()->create([
            'created_by_user_id' => $owner->id,
        ]);

        $channel = 'private-survey.' . $survey->public_id . '.admin';

        $this->actingAs($owner)->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => $channel,
        ])->assertOk();

        // 403: autenticado, pero sin permiso sobre ESA encuesta (API.md §3.3).
        $this->actingAs($intruder)->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => $channel,
        ])->assertForbidden();
    }

    public function test_an_unknown_public_id_is_not_authorizable(): void
    {
        $this->withRealBroadcaster();

        $this->actingAs($this->owner())->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-survey.ZZZZZZZZZZZZZZZZ.admin',
        ])->assertForbidden();
    }

    // ------------------------------------------------- cabeceras y CSRF

    public function test_security_headers_are_present_on_public_pages(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $response = $this->get('/s/' . $built['survey']->public_id);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('X-Correlation-Id'));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('connect-src', $csp);
        // El JS inline es donde un XSS haria dano: no se permite.
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    /**
     * CSRF: NINGUNA ruta esta exenta, incluido el POST publico de respuestas
     * (ARCHITECTURE.md §5.3).
     *
     * LIMITACION HONESTA DE ESTA PRUEBA: Laravel desactiva la verificacion de
     * CSRF cuando detecta que esta ejecutando pruebas
     * (`VerifyCsrfToken::runningUnitTests()`), asi que NO es posible provocar
     * un 419 real desde aqui. Afirmar lo contrario seria afirmar algo que no
     * se ha comprobado.
     *
     * Lo que si se verifica, y es lo que de verdad podria romperse por un
     * descuido:
     *   1. que la lista de exclusiones de CSRF este VACIA, y
     *   2. que el formulario publico emita el campo `_token`.
     *
     * La proteccion en ejecucion real queda para `qa-engineer` en Fase 6, con
     * una peticion desde fuera del entorno de pruebas.
     */
    public function test_no_route_is_excluded_from_csrf_protection(): void
    {
        $middleware = new \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken(
            $this->app,
            $this->app['encrypter'],
        );

        $except = (new \ReflectionProperty($middleware, 'except'))->getValue($middleware);

        $this->assertSame(
            [],
            $except,
            'Ninguna ruta debe estar exenta de CSRF, ni siquiera el POST publico.',
        );
    }

    public function test_the_public_form_renders_a_csrf_token(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->get('/s/' . $built['survey']->public_id)
            ->assertOk()
            ->assertSee('name="_token"', false);
    }
}
