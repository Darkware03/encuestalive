<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSurveys;
use Tests\TestCase;

/**
 * F-08 y F-11 (BAJO, Fase 7) — CABECERAS DE SEGURIDAD.
 *
 * F-08: `connect-src 'self' ws: wss:` autorizaba el WebSocket hacia CUALQUIER
 *       servidor. Los esquemas sueltos, sin host, dejaban la CSP sin valor
 *       como barrera de exfiltracion por esa via.
 * F-11: faltaban `Permissions-Policy` y `Strict-Transport-Security`, y
 *       `X-Powered-By` divulgaba la version exacta del interprete.
 *
 * Lo que NO se corrige, y se declara: `style-src 'unsafe-inline'`. Ver
 * test_the_known_style_src_concession_is_still_documented().
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase, BuildsSurveys;

    private function publicResponse()
    {
        $built = $this->publishedSurveyWithAllTypes();

        return $this->get('/s/'.$built['survey']->public_id);
    }

    private function csp(): string
    {
        return (string) $this->publicResponse()->headers->get('Content-Security-Policy');
    }

    // ---------------------------------------------------------- F-08

    /**
     * El WebSocket solo puede ir al host de Reverb.
     *
     * FALLA antes: la politica contenia `ws:` y `wss:` a secas.
     */
    public function test_connect_src_does_not_allow_websockets_to_any_host(): void
    {
        $csp = $this->csp();

        $this->assertMatchesRegularExpression('/connect-src [^;]*/', $csp);

        preg_match('/connect-src ([^;]*)/', $csp, $m);
        $sources = preg_split('/\s+/', trim($m[1] ?? ''));

        $this->assertNotContains('ws:', $sources,
            "connect-src contiene `ws:` a secas: autoriza el WebSocket hacia CUALQUIER "
            .'servidor y anula la CSP como barrera de exfiltracion (F-08). CSP: '.$csp);

        $this->assertNotContains('wss:', $sources,
            'connect-src contiene `wss:` a secas (F-08). CSP: '.$csp);
    }

    /** Y el host autorizado se deriva de la configuracion, no esta escrito a mano. */
    public function test_connect_src_authorises_exactly_the_configured_reverb_host(): void
    {
        config([
            'broadcasting.connections.reverb.options.host' => 'reverb.ejemplo.test',
            'broadcasting.connections.reverb.options.port' => 9999,
        ]);

        $csp = $this->csp();

        $this->assertStringContainsString('ws://reverb.ejemplo.test:9999', $csp);
        $this->assertStringContainsString('wss://reverb.ejemplo.test:9999', $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }

    /** El tiempo real debe seguir siendo posible: no vale cerrar por cerrar. */
    public function test_the_default_reverb_host_is_still_reachable_under_the_policy(): void
    {
        $host = (string) config('broadcasting.connections.reverb.options.host');

        $this->assertNotSame('', $host, 'Reverb debe tener host configurado (D-02).');
        $this->assertStringContainsString($host, $this->csp(),
            'La CSP bloquearia el WebSocket hacia el propio Reverb: el tiempo real '
            .'degradaria a sondeo sin que nadie entendiera por que.');
    }

    // ---------------------------------------------------------- F-11

    /**
     * `Permissions-Policy` deniega lo que la aplicacion no usa.
     *
     * FALLA antes: la cabecera no existia.
     */
    public function test_permissions_policy_denies_unused_capabilities(): void
    {
        $header = (string) $this->publicResponse()->headers->get('Permissions-Policy');

        $this->assertNotSame('', $header, 'Falta Permissions-Policy (F-11).');

        foreach (['camera=()', 'microphone=()', 'geolocation=()'] as $directive) {
            $this->assertStringContainsString($directive, $header);
        }
    }

    /**
     * `X-Powered-By` no se devuelve.
     *
     * ALCANCE DECLARADO, PARA NO SOBREVENDER ESTA PRUEBA: en PHPUnit no hay
     * SAPI que anada la cabecera, asi que por si sola esta asercion demuestra
     * poco. La comprobacion que vale es la que se hizo con `curl` contra
     * `php artisan serve`, donde la cabecera SI aparecia y tras la correccion
     * ya no aparece en /s/{publicId}, /admin ni /broadcasting/auth.
     *
     * El control definitivo es `expose_php = Off` en php.ini o en el servidor
     * web; `header_remove()` en el middleware es la red de seguridad para
     * cuando ese ajuste no se controla. Queda recogido en la guia de
     * despliegue de la Fase 8.
     */
    public function test_the_php_version_is_not_disclosed(): void
    {
        $this->assertNull(
            $this->publicResponse()->headers->get('X-Powered-By'),
            'X-Powered-By divulga la version exacta del interprete (F-11).',
        );
    }

    /**
     * HSTS solo sobre HTTPS.
     *
     * Las dos mitades importan. Emitirla en HTTP local haria que el navegador
     * recordase 127.0.0.1 como HTTPS-only durante un ano y la aplicacion
     * dejaria de abrirse en desarrollo, sin mensaje que lo explicase.
     */
    public function test_hsts_is_sent_over_https_and_not_over_plain_http(): void
    {
        $built = $this->publishedSurveyWithAllTypes();

        $this->assertNull(
            $this->get('/s/'.$built['survey']->public_id)->headers->get('Strict-Transport-Security'),
            'HSTS no debe emitirse sobre HTTP: envenenaria el navegador en desarrollo.',
        );

        $secure = $this->get('https://localhost/s/'.$built['survey']->public_id);

        $this->assertStringContainsString(
            'max-age=',
            (string) $secure->headers->get('Strict-Transport-Security'),
            'Sobre HTTPS, HSTS es imprescindible (F-11).',
        );
    }

    // ------------------------------- cabeceras en respuestas de ERROR

    /**
     * DEFECTO ENCONTRADO AL VERIFICAR MI PROPIA CORRECCION (Fase 7b).
     *
     * Las respuestas nacidas de una EXCEPCION no pasaban por SecurityHeaders.
     * Cuando `auth` rechaza a un anonimo lanza AuthenticationException, que
     * Laravel captura FUERA de la tuberia de middleware, de modo que el
     * codigo posterior a `$next($request)` no se ejecuta nunca.
     *
     * Medido con `curl` contra el servidor real ANTES del arreglo:
     *
     *     GET /s/{publicId}       -> Permissions-Policy SI, X-Powered-By NO
     *     GET /admin              -> Permissions-Policy NO, X-Powered-By SI
     *     POST /broadcasting/auth -> Permissions-Policy NO, X-Powered-By SI
     *
     * Es decir, justo la superficie administrativa salia sin CSP. La suite no
     * lo veia porque solo miraba la pagina publica: un falso verde. Corregido
     * aplicando las mismas cabeceras desde `$exceptions->respond()`.
     */
    public function test_security_headers_are_present_on_authentication_redirects(): void
    {
        foreach (['/admin', '/admin/encuestas', '/admin/historial'] as $uri) {
            $response = $this->get($uri);

            $response->assertRedirect(route('login'));

            $this->assertNotNull(
                $response->headers->get('Content-Security-Policy'),
                sprintf('La redireccion de %s sale SIN CSP: las respuestas de error no '
                    .'pasan por el middleware (Fase 7b).', $uri),
            );

            $this->assertNotNull(
                $response->headers->get('Permissions-Policy'),
                sprintf('La redireccion de %s sale SIN Permissions-Policy.', $uri),
            );

            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        }
    }

    /** Y tampoco faltan en un 404, que es la respuesta de error mas comun. */
    public function test_security_headers_are_present_on_a_404(): void
    {
        $response = $this->get('/s/ZZZZZZZZZZZZZZZZ');

        $response->assertNotFound();

        $this->assertNotNull($response->headers->get('Content-Security-Policy'),
            'Un 404 tambien debe llevar CSP.');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ------------------------------------------------- sin regresiones

    /** Las cuatro cabeceras de API.md §0.3 siguen presentes. */
    public function test_the_original_headers_are_still_present(): void
    {
        $response = $this->publicResponse();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertNotNull($response->headers->get('X-Correlation-Id'));
    }

    /** Donde de verdad importa, `script-src` sigue sin 'unsafe-inline'. */
    public function test_script_src_still_forbids_inline_script(): void
    {
        preg_match('/script-src ([^;]*)/', $this->csp(), $m);

        $this->assertStringNotContainsString("'unsafe-inline'", $m[1] ?? '',
            "script-src con 'unsafe-inline' seria el agujero de XSS que todo lo demas evita.");
    }

    /**
     * RIESGO ACEPTADO, NO CORREGIDO — `style-src 'unsafe-inline'`.
     *
     * Se deja constancia por prueba, no solo por comentario, para que la
     * concesion sea deliberada y visible. Eliminarla exige reescribir con
     * nonces los componentes del sistema de diseno (`style="z-index: ..."`),
     * que son de `frontend-engineer` y estan fuera de esta correccion.
     *
     * Permite inyeccion de ESTILOS (alteracion visual, exfiltracion por
     * selectores de atributo), nunca ejecucion de script.
     *
     * Si alguien la elimina, esta prueba se pondra roja y habra que borrarla:
     * sera una buena noticia.
     */
    public function test_the_known_style_src_concession_is_still_documented(): void
    {
        preg_match('/style-src ([^;]*)/', $this->csp(), $m);

        $this->assertStringContainsString("'unsafe-inline'", $m[1] ?? '',
            'style-src ya no necesita unsafe-inline: elimina esta prueba y actualiza '
            .'el informe, porque el riesgo aceptado dejo de existir.');
    }
}
