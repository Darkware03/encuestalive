<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Prueba del esqueleto, ACTUALIZADA en la Fase 5b.
 *
 * Antes afirmaba que `GET /` devolvia 200, porque el scaffold de Laravel
 * servia ahi la pagina `welcome`. En EncuestaLive la raiz ya no es una
 * pantalla: es un desvio.
 *
 * EncuestaLive no tiene portada publica a proposito. Un visitante anonimo no
 * debe poder descubrir que encuestas existen ni la zona administrativa
 * (UX_UI_SPEC §3.1), asi que `/` solo decide a donde mandar a quien llega:
 * al panel si hay sesion, y al login si no.
 *
 * La prueba se reescribe para afirmar ese contrato real en lugar de relajarse
 * hasta pasar. Comprobar el DESTINO del desvio, y no solo que haya un 302, es
 * lo que la mantiene util: un futuro cambio que enviara a un anonimo al panel
 * la pondria en rojo.
 */
class ExampleTest extends TestCase
{
    public function test_the_root_redirects_an_anonymous_visitor_to_the_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_the_health_endpoint_answers_without_authentication(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');

        // No debe exponer version, host, rutas ni recuentos (API.md §2.1).
        $response->assertJsonMissingPath('version');
        $response->assertJsonMissingPath('hostname');
    }
}
