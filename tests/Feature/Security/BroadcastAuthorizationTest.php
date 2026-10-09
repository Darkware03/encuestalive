<?php

namespace Tests\Feature\Security;

use App\Models\Survey;
use App\Models\User;
use App\Support\BroadcastGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * F-07 (ALTO, Fase 7) — EL DIFUSOR CONFIGURADO ANULABA LA AUTORIZACION.
 *
 * ======================================================================
 *  EL FALLO Y POR QUE NINGUNA PRUEBA LO VIO
 * ======================================================================
 * `routes/channels.php` esta bien escrito: resuelve la encuesta por public_id
 * y aplica SurveyPolicy::view (CA-12, R-09). El problema es que con los
 * difusores `log` y `null` **ese callback no se ejecuta jamas**, porque
 * `LogBroadcaster::auth()` y `NullBroadcaster::auth()` tienen el cuerpo vacio.
 * No comprueban nada y no lanzan: el llamante entiende "autorizado".
 *
 * `.env` y `.env.example` entregaban `BROADCAST_CONNECTION=log`, y el respaldo
 * del framework (no habia `config/broadcasting.php` propio) era `null`. Las
 * dos rutas llevaban al mismo sitio: autorizacion apagada.
 *
 * El auditor lo verifico contra los tres difusores. Bajo `log` y `null`, los
 * cuatro sujetos (anonimo incluido) salian AUTORIZADOS; bajo `reverb`, los
 * cuatro casos se resolvian exactamente como el diseno pretende.
 *
 * ======================================================================
 *  QUE HACE ESTE FICHERO QUE NO HACIA LA SUITE ANTERIOR
 * ======================================================================
 * `AuthorizationTest` ya probaba la autorizacion de canal, pero forzando
 * `reverb` a mano dentro de la prueba. Eso demuestra que el CALLBACK es
 * correcto, y es valioso, pero **no dice nada sobre el difusor que la
 * aplicacion usa de verdad**: la suite seguia en verde con `log` entregado.
 *
 * Aqui se invierte el enfoque. El difusor bajo prueba NO se escribe a mano:
 * se DEDUCE de lo que se entrega (`.env.example` y el respaldo de
 * `config/broadcasting.php`). Si alguien vuelve a poner `log` en cualquiera
 * de los dos sitios, estas pruebas se ponen rojas.
 */
class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El difusor que recibe quien despliega esto, deducido de lo entregado.
     *
     * NO se usa `config('broadcasting.default')`: en la suite vale lo que fija
     * `phpunit.xml` (`null`), que es justo el difusor inseguro. Leer de ahi
     * convertiria la prueba en una tautologia.
     *
     * Orden de deduccion, de mas concreto a mas general:
     *   1. `BROADCAST_CONNECTION` de `.env.example` (lo que copia un humano).
     *   2. El valor de respaldo de `config/broadcasting.php`, evaluado con la
     *      variable de entorno RETIRADA (lo que ocurre si se olvida).
     */
    private function shippedBroadcaster(): string
    {
        $fromExample = $this->envExampleValue('BROADCAST_CONNECTION');

        if ($fromExample !== null && $fromExample !== '') {
            return $fromExample;
        }

        return $this->configFallbackBroadcaster();
    }

    /** Lee una clave de `.env.example` tal como la leeria una persona. */
    private function envExampleValue(string $key): ?string
    {
        $path = base_path('.env.example');
        $this->assertFileExists($path, '.env.example es la plantilla de despliegue y debe existir.');

        $found = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m) === 1) {
                $found = trim($m[1], " \t\"'");
            }
        }

        return $found;
    }

    /** Cuantas veces se DECLARA (no se comenta) una clave en `.env.example`. */
    private function envExampleDeclarationCount(string $key): int
    {
        $count = 0;

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Evalua el respaldo de `config/broadcasting.php` con la variable de
     * entorno retirada: lo que la aplicacion usaria si nadie la define.
     */
    private function configFallbackBroadcaster(): string
    {
        $previous = $_ENV['BROADCAST_CONNECTION'] ?? null;

        unset($_ENV['BROADCAST_CONNECTION'], $_SERVER['BROADCAST_CONNECTION']);
        putenv('BROADCAST_CONNECTION');

        try {
            $config = require base_path('config/broadcasting.php');
        } finally {
            if ($previous !== null) {
                $_ENV['BROADCAST_CONNECTION'] = $previous;
                $_SERVER['BROADCAST_CONNECTION'] = $previous;
                putenv('BROADCAST_CONNECTION='.$previous);
            }
        }

        return (string) $config['default'];
    }

    /**
     * Activa el difusor indicado y REGISTRA LOS CANALES SOBRE EL.
     *
     * Sin volver a cargar routes/channels.php, el contenedor crea un difusor
     * nuevo y SIN NINGUN CANAL, de modo que todo devolveria 403 por no existir
     * el canal, no por la policy: un falso positivo perfecto. Por eso cada
     * prueba que deniega comprueba ademas el caso POSITIVO del propietario.
     */
    private function useBroadcaster(string $driver): void
    {
        config([
            'broadcasting.default' => $driver,
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

        Broadcast::purge($driver);
        require base_path('routes/channels.php');
    }

    /** Peticion de suscripcion a un canal, con o sin usuario. */
    private function subscriptionRequest(string $channel, ?User $user): Request
    {
        $request = Request::create('/broadcasting/auth', 'POST', [
            'socket_id' => '123.456',
            'channel_name' => $channel,
        ]);

        $request->setUserResolver(fn () => $user);

        return $request;
    }

    // =================================================================
    //  1. LO ENTREGADO
    // =================================================================

    /**
     * `.env.example` no puede entregar un difusor sin autorizacion.
     *
     * FALLA antes: entregaba `log`.
     */
    public function test_the_env_example_does_not_ship_an_unauthorizing_broadcaster(): void
    {
        $shipped = $this->envExampleValue('BROADCAST_CONNECTION');

        $this->assertNotNull($shipped, '.env.example debe declarar BROADCAST_CONNECTION.');

        $this->assertFalse(
            BroadcastGuard::disablesChannelAuthorization($shipped),
            sprintf(
                '.env.example entrega BROADCAST_CONNECTION=%s. Ese difusor tiene auth() VACIO: '
                .'el callback de routes/channels.php no se ejecuta y se autoriza a cualquiera, '
                .'anonimo incluido (F-07). Usa "%s".',
                $shipped,
                BroadcastGuard::APPLICATION_DEFAULT,
            ),
        );
    }

    /**
     * Declarada UNA sola vez.
     *
     * FALLA antes: aparecia en la linea 36 y otra vez en la 78. Con dos
     * declaraciones, corregir una y no la otra deja el fallo vivo y la lectura
     * del fichero enganya.
     */
    public function test_the_broadcast_connection_is_declared_exactly_once_in_env_example(): void
    {
        $this->assertSame(
            1,
            $this->envExampleDeclarationCount('BROADCAST_CONNECTION'),
            'BROADCAST_CONNECTION debe declararse UNA sola vez en .env.example. '
            .'Duplicada, gana la ultima y la primera miente.',
        );
    }

    /**
     * El respaldo de configuracion tampoco puede ser inseguro.
     *
     * FALLA antes: no habia `config/broadcasting.php` propio, de modo que
     * regia el del framework, cuyo respaldo es `null`. Olvidar la variable de
     * entorno apagaba la autorizacion.
     */
    public function test_the_config_fallback_broadcaster_authorizes_channels(): void
    {
        $fallback = $this->configFallbackBroadcaster();

        $this->assertFalse(
            BroadcastGuard::disablesChannelAuthorization($fallback),
            sprintf(
                'El respaldo de config/broadcasting.php es "%s", que no autoriza canales. '
                .'La peor configuracion posible (olvidar la variable) debe dejar la '
                .'autorizacion ACTIVA, no apagada (F-07).',
                $fallback,
            ),
        );
    }

    // =================================================================
    //  2. EL CONTROL, CON EL DIFUSOR QUE SE ENTREGA
    // =================================================================

    /**
     * ===== LA PRUEBA QUE EXIGE EL HALLAZGO =====
     *
     * Un ANONIMO recibe 403 al intentar suscribirse a un canal
     * administrativo, con el difusor que la aplicacion usa por defecto.
     *
     * FALLA antes: con `log`/`null`, `auth()` devolvia `null` sin lanzar nada;
     * para el llamante eso es AUTORIZADO. No habia 403 que valiera.
     */
    public function test_an_anonymous_visitor_gets_403_on_an_admin_channel_with_the_default_broadcaster(): void
    {
        $this->useBroadcaster($this->shippedBroadcaster());

        $survey = Survey::factory()->published()->create();
        $channel = 'private-survey.'.$survey->public_id.'.admin';

        try {
            $result = Broadcast::driver()->auth($this->subscriptionRequest($channel, null));
        } catch (AccessDeniedHttpException $e) {
            $this->assertSame(403, $e->getStatusCode());

            return;
        }

        $this->fail(sprintf(
            'Un ANONIMO fue AUTORIZADO en %s con el difusor "%s" (respuesta: %s). '
            .'Eso es F-07: el difusor configurado anula la autorizacion de canal y el '
            .'callback de routes/channels.php nunca llega a ejecutarse.',
            $channel,
            config('broadcasting.default'),
            var_export($result, true),
        ));
    }

    /**
     * La otra mitad, sin la cual lo anterior no demuestra nada: el
     * PROPIETARIO si es autorizado.
     *
     * Una denegacion universal (por ejemplo, por no haber registrado el canal)
     * se leeria como seguridad y seria un falso positivo.
     */
    public function test_the_owner_is_still_authorized_with_the_default_broadcaster(): void
    {
        $this->useBroadcaster($this->shippedBroadcaster());

        $owner = User::factory()->create();
        $survey = Survey::factory()->published()->create(['created_by_user_id' => $owner->id]);

        $payload = Broadcast::driver()->auth(
            $this->subscriptionRequest('private-survey.'.$survey->public_id.'.admin', $owner),
        );

        $this->assertNotNull($payload, 'El propietario legitimo debe poder escuchar su dashboard.');
    }

    /**
     * R-09: un administrador autenticado NO propietario recibe 403 con el
     * difusor por defecto.
     *
     * Es el escenario que de verdad importa el dia que haya un segundo
     * administrador (hoy D-05 PA-02 fija uno solo, asi que el fallo estaba
     * latente, no explotado).
     *
     * FALLA antes: con `log`/`null` el intruso quedaba autorizado.
     */
    public function test_a_non_owner_admin_gets_403_with_the_default_broadcaster(): void
    {
        $this->useBroadcaster($this->shippedBroadcaster());

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $survey = Survey::factory()->published()->create(['created_by_user_id' => $owner->id]);

        $this->expectException(AccessDeniedHttpException::class);

        Broadcast::driver()->auth(
            $this->subscriptionRequest('private-survey.'.$survey->public_id.'.admin', $intruder),
        );
    }

    // =================================================================
    //  3. EL MECANISMO, DOCUMENTADO POR PRUEBA
    // =================================================================

    /**
     * Caracterizacion del fallo del framework.
     *
     * Esta prueba NO verifica una correccion: verifica que el peligro sigue
     * siendo real, y por tanto que la proteccion de los apartados 1 y 2 hace
     * falta. Si un dia Laravel implementa `auth()` en LogBroadcaster, esta
     * prueba se pondra roja y sabremos que el riesgo desaparecio.
     *
     * Se comprueba sobre `log` y `null` explicitamente, nunca sobre el
     * difusor por defecto.
     */
    public function test_the_log_and_null_broadcasters_really_do_authorize_anyone(): void
    {
        $survey = Survey::factory()->published()->create();
        $channel = 'private-survey.'.$survey->public_id.'.admin';

        foreach (BroadcastGuard::UNAUTHORIZING_DRIVERS as $driver) {
            $this->useBroadcaster($driver);

            $result = Broadcast::driver()->auth($this->subscriptionRequest($channel, null));

            $this->assertNull($result, sprintf(
                'Se esperaba que el difusor "%s" siguiera sin autorizar nada (auth() vacio). '
                .'Si esto cambia, revisa BroadcastGuard::UNAUTHORIZING_DRIVERS.',
                $driver,
            ));
        }
    }

    // =================================================================
    //  4. LA RED DE SEGURIDAD DE ARRANQUE
    // =================================================================

    /** En produccion, un difusor sin autorizacion impide arrancar. */
    public function test_the_boot_guard_refuses_an_unauthorizing_broadcaster_in_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/F-07/');

        BroadcastGuard::assertSafeForEnvironment('production', 'log');
    }

    /** Fuera de produccion solo avisa: `log` sigue siendo util para depurar. */
    public function test_the_boot_guard_only_warns_outside_production(): void
    {
        $warning = BroadcastGuard::assertSafeForEnvironment('local', 'log');

        $this->assertIsString($warning);
        $this->assertStringContainsString('F-07', $warning);

        $this->assertNull(
            BroadcastGuard::assertSafeForEnvironment('production', BroadcastGuard::APPLICATION_DEFAULT),
            'El difusor por defecto de la aplicacion debe poder arrancar en produccion.',
        );
    }
}
