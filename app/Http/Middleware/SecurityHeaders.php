<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad y `correlation_id` por peticion
 * (ARCHITECTURE.md §5.3, §9.1).
 *
 * El `correlation_id` es aleatorio por peticion y se devuelve en
 * `X-Correlation-Id`. Permite que alguien diga "me fallo, el codigo era
 * 0c0a9d5e" sin que ese codigo identifique a nadie ni se guarde en la isla A:
 * no hay columna donde ponerlo junto a una respuesta (ARCHITECTURE.md §7.2).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = (string) Str::uuid();
        $request->attributes->set('el.correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Correlation-Id', $correlationId);

        return $this->apply($request, $response);
    }

    /**
     * Aplica las cabeceras a CUALQUIER respuesta, venga del enrutador o del
     * manejador de excepciones.
     *
     * ==================================================================
     *  POR QUE ESTO ES UN METODO APARTE Y PUBLICO (Fase 7b)
     * ==================================================================
     * Detectado al verificar la correccion de F-08/F-11 con `curl` contra el
     * servidor real, no en la suite: las respuestas que nacen de una
     * EXCEPCION no pasaban por aqui.
     *
     * Cuando el middleware `auth` rechaza a un anonimo, lanza
     * AuthenticationException. Laravel la captura en el nucleo, FUERA de la
     * tuberia de middleware, asi que el codigo posterior a `$next($request)`
     * nunca se ejecuta. Resultado medido:
     *
     *     GET /s/{publicId}  -> Permissions-Policy SI, X-Powered-By NO
     *     GET /admin         -> Permissions-Policy NO, X-Powered-By SI
     *     POST /broadcasting/auth -> Permissions-Policy NO, X-Powered-By SI
     *
     * Es decir, justo las respuestas de la superficie administrativa salian
     * sin CSP y divulgando la version de PHP. La suite no podia verlo: en
     * PHPUnit no hay SAPI que anada `X-Powered-By`, de modo que la prueba
     * pasaba en verde mientras la realidad era otra. Un falso verde.
     *
     * `bootstrap/app.php` llama a este metodo desde `$exceptions->respond()`,
     * que es el unico punto por el que pasan TODAS las respuestas de error.
     */
    public function apply(Request $request, Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        /*
         * F-11 (BAJO, Fase 7): denegar explicitamente lo que la aplicacion no
         * usa. EncuestaLive no pide camara, microfono ni ubicacion en ningun
         * momento; declararlo impide que un script de terceros lo intente y
         * reduce lo que un iframe heredaria.
         */
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        );

        /*
         * F-11: HSTS SOLO sobre HTTPS.
         *
         * Enviarla por HTTP no tiene efecto segun la norma, pero sobre todo:
         * emitirla en desarrollo local seria un disparo en el pie. El
         * navegador recordaria que 127.0.0.1 es HTTPS-only durante meses y
         * dejaria de poder abrirse la aplicacion en claro, sin que el aviso
         * dijera por que. Se condiciona a la peticion segura real.
         */
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        /*
         * F-11: `X-Powered-By: PHP/8.4.23` divulga la version exacta del
         * interprete. Lo anade el propio PHP (`expose_php`), que es ajuste de
         * php.ini y no siempre se controla en el despliegue; se retira aqui
         * para no depender de eso.
         */
        $response->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp());
        }

        return $response;
    }

    /**
     * CSP del MVP.
     *
     * F-08 (BAJO, Fase 7): `connect-src` traia `ws: wss:` A SECAS, sin host.
     * Esos dos esquemas sueltos autorizan el WebSocket hacia CUALQUIER
     * servidor, lo que convertia la CSP en un control inutil como barrera de
     * exfiltracion por esa via: un script inyectado podia abrir un socket a
     * donde quisiera. Ahora se acota al host de Reverb, construido desde la
     * configuracion en lugar de escrito a mano, para que no se desincronice
     * con el despliegue.
     *
     * Echo necesita ese origen concreto; sin el, el tiempo real quedaria
     * bloqueado por la propia politica y el sistema degradaria a `wire:poll`
     * sin que nadie entendiera por que.
     *
     * `style-src` admite 'unsafe-inline': los componentes del sistema de
     * diseno usan `style="z-index: var(--z-modal)"` en varios sitios. Es una
     * concesion real y conviene nombrarla en lugar de esconderla; eliminarla
     * exigiria reescribir componentes que no son de mi ambito en esta fase.
     *
     * `script-src` NO lleva 'unsafe-inline': ahi es donde un XSS haria dano, y
     * todo el JavaScript del proyecto viene de ficheros servidos por Vite.
     */
    private function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'",
            'connect-src '.$this->connectSources(),
        ]);
    }

    /**
     * Origenes permitidos para WebSocket, derivados de la configuracion de
     * Reverb (F-08).
     *
     * Se declaran los dos esquemas del MISMO host: `REVERB_SCHEME` dice cual
     * se usa, pero un despliegue tras un proxy puede terminar TLS por fuera, y
     * una CSP que bloquee el socket rompe el tiempo real de forma silenciosa.
     * Acotar al host sin fijar el esquema conserva el control que importa
     * —no se puede conectar a un servidor ajeno— sin fragilidad operativa.
     */
    private function connectSources(): string
    {
        $sources = ["'self'"];

        $host = (string) config('broadcasting.connections.reverb.options.host', '');
        $port = (string) config('broadcasting.connections.reverb.options.port', '');

        if ($host !== '') {
            $authority = $host.($port !== '' ? ':'.$port : '');

            $sources[] = 'ws://'.$authority;
            $sources[] = 'wss://'.$authority;
        }

        return implode(' ', $sources);
    }
}
