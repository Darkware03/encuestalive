<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * F-06 (MEDIO, Fase 7) y F-12 (BAJO) — CLAVES DE LIMITACION DE FRECUENCIA.
 *
 * ======================================================================
 *  EL REQUISITO QUE MANDA (brief §6.1)
 * ======================================================================
 * Una sala de 200-300 personas con un unico wifi COMPARTE UNA SOLA IP. Un
 * limite por IP ingenuo cerraria la encuesta a los demas en cuanto respondiera
 * el primero, y ese es el caso de uso principal del producto. Por eso la clave
 * primaria es el TOKEN y no la IP, y eso NO se toca aqui.
 *
 * ======================================================================
 *  QUE SE CORRIGIO Y QUE SE REFUTO
 * ======================================================================
 * El informe sostiene dos cosas bajo F-06. Una es cierta y se ha corregido;
 * la otra no se sostiene y se demuestra aqui en lugar de descartarla:
 *
 *  (a) CIERTA. `public-open`, `heartbeat` y `public-state` no tenian NINGUNA
 *      red de seguridad por IP. Solo el cubo por token. Un cliente que fuera
 *      acumulando tokens legitimos crecia sin techo.
 *      -> Corregido: segundo cubo por IP en los tres, con valores muy por
 *         encima del uso humano para no castigar la IP compartida.
 *
 *  (b) NO SE SOSTIENE. "Un cliente que envie una cookie `el_pt_xxx` con un
 *      valor aleatorio distinto en cada peticion obtiene un cubo nuevo cada
 *      vez, es decir, limitacion nula."
 *      -> La cookie de participante NO esta exenta de cifrado.
 *         `EncryptCookies` corre ANTES que `ThrottleRequests`, y un valor que
 *         no descifra se convierte en `null` antes de llegar al limitador.
 *         Un valor aleatorio no crea cubo: cae al cubo de su IP.
 *         Se demuestra en test_a_forged_cookie_never_reaches_the_limiter().
 *
 * Se ha anadido ademas una comprobacion de forma del token por defensa en
 * profundidad: hoy es redundante con el cifrado, y deja de serlo el dia que
 * alguien exima esta cookie de `EncryptCookies`.
 */
class RateLimitKeyTest extends TestCase
{
    use RefreshDatabase;

    /** Limitadores de participante que DEBEN tener red de seguridad por IP. */
    public static function limitersRequiringIpSafetyNet(): array
    {
        return [
            'public-open' => ['public-open'],
            'response-submit' => ['response-submit'],
            'heartbeat' => ['heartbeat'],
            'public-state' => ['public-state'],
        ];
    }

    /**
     * Ejecuta el limitador registrado y devuelve sus cubos.
     *
     * Se invoca el limitador REAL del contenedor, el que registra
     * RateLimitServiceProvider, no una copia.
     *
     * @return array<int, Limit>
     */
    private function limitsFor(string $limiter, ?Request $request = null): array
    {
        $callback = RateLimiter::limiter($limiter);

        $this->assertNotNull($callback, "El limitador {$limiter} no esta registrado.");

        $result = $callback($request ?? $this->participantRequest());

        return is_array($result) ? $result : [$result];
    }

    private function participantRequest(?string $cookieValue = null, string $ip = '203.0.113.10'): Request
    {
        // 203.0.113.0/24 es TEST-NET-3 (RFC 5737): no pertenece a nadie.
        $request = Request::create('/s/DEM0K7M2QX9RTB4W', 'GET', [], [], [], [
            'REMOTE_ADDR' => $ip,
        ]);

        if ($cookieValue !== null) {
            $request->cookies->set(
                config('encuestalive.participant.cookie_prefix').'abcd1234',
                $cookieValue,
            );
        }

        return $request;
    }

    /** Un token con la forma real de ADR-0005: Base64 URL-safe, 43 caracteres. */
    private function wellFormedToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    // =================================================================
    //  (a) LA RED DE SEGURIDAD POR IP QUE NO EXISTIA
    // =================================================================

    /**
     * ===== LA CORRECCION CENTRAL DE F-06 =====
     *
     * Cada limitador de participante expone DOS cubos: uno por token y otro
     * por IP.
     *
     * FALLA antes: `public-open`, `heartbeat` y `public-state` devolvian un
     * unico `Limit`, sin ninguna red por IP.
     */
    #[DataProvider('limitersRequiringIpSafetyNet')]
    public function test_each_participant_limiter_has_an_ip_safety_net(string $limiter): void
    {
        $token = $this->wellFormedToken();
        $limits = $this->limitsFor($limiter, $this->participantRequest($token));

        $this->assertCount(2, $limits, sprintf(
            'El limitador "%s" debe exponer DOS cubos: uno por token y una red de '
            .'seguridad por IP. Sin la red por IP, un cliente que acumule tokens '
            .'crece sin techo contra una ruta publica (F-06).',
            $limiter,
        ));

        $keys = array_map(fn (Limit $l) => (string) $l->key, $limits);

        $this->assertNotEmpty(
            array_filter($keys, fn (string $k) => str_contains($k, ':t:')),
            "El limitador {$limiter} perdio el cubo por TOKEN, que es el que protege "
            .'la IP compartida del brief §6.1. Claves: '.implode(' | ', $keys),
        );

        $this->assertNotEmpty(
            array_filter($keys, fn (string $k) => str_contains($k, ':ip:')),
            "El limitador {$limiter} no tiene cubo por IP. Claves: ".implode(' | ', $keys),
        );
    }

    /**
     * La red por IP es GENEROSA: muy por encima del uso humano legitimo.
     *
     * Un techo por IP ajustado seria peor que no tenerlo: castigaria a la sala
     * de 300 personas que el producto existe para servir (brief §6.1,
     * D-05 PA-01). Se exige que el cubo por IP sea bastante mayor que el de
     * token y que soporte el aforo objetivo.
     */
    #[DataProvider('limitersRequiringIpSafetyNet')]
    public function test_the_ip_safety_net_does_not_punish_a_shared_ip(string $limiter): void
    {
        $limits = $this->limitsFor($limiter, $this->participantRequest($this->wellFormedToken()));

        $perToken = null;
        $perIp = null;

        foreach ($limits as $limit) {
            if (str_contains((string) $limit->key, ':t:')) {
                $perToken = $limit->maxAttempts;
            }

            if (str_contains((string) $limit->key, ':ip:')) {
                $perIp = $limit->maxAttempts;
            }
        }

        $this->assertNotNull($perToken);
        $this->assertNotNull($perIp);

        $this->assertGreaterThan($perToken, $perIp, sprintf(
            'En "%s" el cubo por IP (%d) no supera al cubo por token (%d). Una red de '
            .'seguridad mas estrecha que el limite individual convierte la IP '
            .'compartida en el cuello de botella, que es justo lo que el brief §6.1 '
            .'prohibe.',
            $limiter,
            $perIp,
            $perToken,
        ));
    }

    /**
     * Dos participantes distintos DETRAS DE LA MISMA IP reciben cubos
     * distintos. Es el requisito del brief §6.1, y la correccion no puede
     * haberlo roto.
     */
    public function test_two_participants_behind_the_same_ip_get_independent_buckets(): void
    {
        $sharedIp = '203.0.113.77';

        $first = $this->limitsFor(
            'public-open',
            $this->participantRequest($this->wellFormedToken(), $sharedIp),
        );
        $second = $this->limitsFor(
            'public-open',
            $this->participantRequest($this->wellFormedToken(), $sharedIp),
        );

        $tokenKey = fn (array $limits) => collect($limits)
            ->map(fn (Limit $l) => (string) $l->key)
            ->first(fn (string $k) => str_contains($k, ':t:'));

        $this->assertNotSame(
            $tokenKey($first),
            $tokenKey($second),
            '200 telefonos tras un mismo wifi deben ser 200 cubos independientes '
            .'(brief §6.1). Si comparten clave, el primero que responda cierra la '
            .'encuesta a los demas.',
        );
    }

    // =================================================================
    //  (b) LA PARTE DEL HALLAZGO QUE NO SE SOSTIENE
    // =================================================================

    /**
     * ===== REFUTACION, CON EVIDENCIA =====
     *
     * Una cookie con valor aleatorio NO llega al limitador: `EncryptCookies`
     * la convierte en `null` por fallo de descifrado, y la peticion cae en la
     * rama de IP.
     *
     * Esta prueba no verifica una correccion mia: verifica que la premisa del
     * hallazgo es falsa. Si manana alguien exime la cookie de participante del
     * cifrado, esta prueba se pone roja y el falso positivo se convertira en
     * un hallazgo real.
     */
    public function test_a_forged_cookie_never_reaches_the_limiter(): void
    {
        $prefix = (string) config('encuestalive.participant.cookie_prefix');
        $encrypt = app(EncryptCookies::class);

        foreach (['xxxxxxxxxxxxxxxxxxxx', bin2hex(random_bytes(16)), $this->wellFormedToken()] as $forged) {
            $request = $this->participantRequest($forged);

            $encrypt->handle($request, function (Request $decrypted) use ($prefix, $forged) {
                $value = $decrypted->cookies->get($prefix.'abcd1234');

                $this->assertNull($value, sprintf(
                    'Un valor de cookie forjado (%s) sobrevivio al descifrado. Si esto '
                    .'ocurre, la clave de limitacion SI es falsificable rotando la '
                    .'cookie y F-06(b) deja de ser un falso positivo.',
                    substr($forged, 0, 12).'...',
                ));

                return new Response('');
            });
        }
    }

    /**
     * Y, en consecuencia, una cookie no descifrable cae en el cubo de la IP,
     * no en uno nuevo.
     */
    public function test_an_unusable_cookie_falls_back_to_the_ip_bucket(): void
    {
        $request = $this->participantRequest();
        // Lo que el limitador ve de verdad tras EncryptCookies: la cookie en null.
        $request->cookies->set(config('encuestalive.participant.cookie_prefix').'abcd1234', null);

        $keys = array_map(fn (Limit $l) => (string) $l->key, $this->limitsFor('public-open', $request));

        foreach ($keys as $key) {
            $this->assertStringNotContainsString(':t:', $key,
                'Una cookie inutilizable no puede producir un cubo por token.');
        }
    }

    /**
     * Defensa en profundidad: un valor con forma incorrecta se ignora aunque
     * llegara en claro.
     *
     * Hoy es redundante con el cifrado de cookies. Deja de serlo si alguien
     * anade esta cookie a la lista de exenciones de EncryptCookies.
     */
    public function test_a_malformed_token_value_is_ignored_even_if_it_arrives_in_clear(): void
    {
        foreach (['corto', str_repeat('A', 200), 'tiene espacios aqui', '!!!!'] as $bogus) {
            $keys = array_map(
                fn (Limit $l) => (string) $l->key,
                $this->limitsFor('public-open', $this->participantRequest($bogus)),
            );

            foreach ($keys as $key) {
                $this->assertStringNotContainsString(':t:', $key, sprintf(
                    'El valor %s no tiene la forma del token de ADR-0005 y no debe crear '
                    .'un cubo propio.',
                    json_encode($bogus),
                ));
            }
        }
    }

    /** Un token BIEN formado si produce su propio cubo: no se rompio nada. */
    public function test_a_well_formed_token_still_gets_its_own_bucket(): void
    {
        $keys = array_map(
            fn (Limit $l) => (string) $l->key,
            $this->limitsFor('public-open', $this->participantRequest($this->wellFormedToken())),
        );

        $this->assertNotEmpty(array_filter($keys, fn (string $k) => str_contains($k, ':t:')));
    }

    // =================================================================
    //  ORDEN REAL DE LA PILA (sostiene la refutacion)
    // =================================================================

    /**
     * `EncryptCookies` corre ANTES que `ThrottleRequests`.
     *
     * Es el hecho del que depende la refutacion de F-06(b). Tambien demuestra
     * por que la recomendacion del informe —derivar la clave de
     * `el.participant_session`— NO es aplicable: `AttachParticipantSession`
     * corre DESPUES del limitador, y adelantarlo pondria una escritura en base
     * de datos por delante del control de frecuencia, que seria peor.
     */
    public function test_cookie_decryption_runs_before_throttling_and_session_attach(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 's/{publicId}' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, 'No se encontro la ruta publica de la encuesta.');

        $request = Request::create('/s/DEM0K7M2QX9RTB4W', 'GET');
        $request->setRouteResolver(fn () => $route);

        $method = new \ReflectionMethod(app(Kernel::class), 'gatherRouteMiddleware');
        $method->setAccessible(true);

        $stack = array_values(array_map(
            fn ($m) => is_string($m) ? $m : get_debug_type($m),
            $method->invoke(app(Kernel::class), $request),
        ));

        $positionOf = function (string $needle) use ($stack): int {
            foreach ($stack as $i => $name) {
                if (str_starts_with($name, $needle)) {
                    return $i;
                }
            }

            return -1;
        };

        $encrypt = $positionOf(EncryptCookies::class);
        $throttle = $positionOf(ThrottleRequests::class);
        $attach = $positionOf(\App\Http\Middleware\AttachParticipantSession::class);

        $this->assertGreaterThanOrEqual(0, $encrypt, 'EncryptCookies no esta en la pila: '.implode(', ', $stack));
        $this->assertGreaterThanOrEqual(0, $throttle, 'ThrottleRequests no esta en la pila.');

        $this->assertLessThan($throttle, $encrypt,
            'EncryptCookies debe correr ANTES que ThrottleRequests. Si deja de hacerlo, '
            .'una cookie forjada llegaria cruda al limitador y F-06(b) seria real.');

        $this->assertGreaterThan($throttle, $attach,
            'AttachParticipantSession corre despues del limitador. Si se adelantara, una '
            .'escritura en base de datos quedaria por delante del control de frecuencia.');
    }

    // =================================================================
    //  F-12 — la IP no viaja legible en la clave de cache
    // =================================================================

    /**
     * Ninguna clave de limitacion contiene la IP en claro.
     *
     * FALLA antes: las claves eran `submit-ip:203.0.113.199`,
     * `session-issue:<IP>`, `health:<IP>` y `open:ip:<IP>`. `ThrottleRequests`
     * les aplica `md5()` antes de persistirlas, de modo que la IP no se
     * guardaba en claro —el propio informe refuto su hipotesis inicial—, pero
     * un MD5 sin sal sobre un dato de entropia tan baja se invierte con una
     * tabla precalculada: el auditor recupero la IP en 0,0001 s acotando el
     * ultimo octeto.
     */
    public function test_no_limiter_key_contains_the_raw_ip(): void
    {
        $ip = '203.0.113.199';
        $limiters = ['public-open', 'response-submit', 'heartbeat', 'public-state', 'session-issue', 'health'];

        foreach ($limiters as $limiter) {
            foreach ($this->limitsFor($limiter, $this->participantRequest(null, $ip)) as $limit) {
                $this->assertStringNotContainsString($ip, (string) $limit->key, sprintf(
                    'El limitador "%s" pone la IP EN CLARO en su clave ("%s"). Esa clave '
                    .'acaba en el almacen de cache tras un md5() sin sal, que es '
                    .'reversible de forma trivial (F-12). Derivala con app.key.',
                    $limiter,
                    $limit->key,
                ));
            }
        }
    }

    /** La clave por IP es ESTABLE: el limitador necesita eso, no legibilidad. */
    public function test_the_salted_ip_key_is_stable_for_the_same_ip(): void
    {
        $keyFor = fn (string $ip) => collect($this->limitsFor('public-open', $this->participantRequest(null, $ip)))
            ->map(fn (Limit $l) => (string) $l->key)
            ->first(fn (string $k) => str_contains($k, ':ip:'));

        $this->assertSame($keyFor('203.0.113.5'), $keyFor('203.0.113.5'), 'La clave debe ser estable.');
        $this->assertNotSame($keyFor('203.0.113.5'), $keyFor('203.0.113.6'), 'Dos IP distintas, dos cubos.');
    }

    /** El limite de login tampoco expone el email ni la IP en claro. */
    public function test_the_login_limiter_exposes_neither_email_nor_ip(): void
    {
        $request = Request::create('/login', 'POST', ['email' => 'admin@ejemplo.test'], [], [], [
            'REMOTE_ADDR' => '203.0.113.199',
        ]);

        foreach ($this->limitsFor('login', $request) as $limit) {
            $this->assertStringNotContainsString('admin@ejemplo.test', (string) $limit->key);
            $this->assertStringNotContainsString('203.0.113.199', (string) $limit->key);
        }
    }
}
