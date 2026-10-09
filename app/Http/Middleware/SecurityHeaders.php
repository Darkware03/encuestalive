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
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp());
        }

        return $response;
    }

    /**
     * CSP del MVP.
     *
     * `connect-src` incluye ws: y wss: porque Echo abre el WebSocket hacia
     * Reverb; sin eso el tiempo real quedaria bloqueado por la propia politica
     * y el sistema degradaria a `wire:poll` sin que nadie entendiera por que.
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
            "connect-src 'self' ws: wss:",
        ]);
    }
}
