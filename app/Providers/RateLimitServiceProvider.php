<?php

namespace App\Providers;

use App\Support\SecurityLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Limitadores de frecuencia (ARCHITECTURE.md §6).
 *
 * ============ EL REQUISITO QUE MAS CONDICIONA ESTE FICHERO ============
 * Brief §6.1: una sala de 200 personas con un unico wifi COMPARTE UNA SOLA IP
 * PUBLICA. Un limite por IP ingenuo cerraria la encuesta a los 199 restantes
 * en cuanto respondiera el primero. Y ese es, literalmente, el caso de uso
 * principal del producto.
 *
 * Reglas aplicadas:
 *
 *  1. La clave primaria de limitacion para participantes es EL TOKEN DE SESION
 *     OPACO, no la IP. 200 telefonos tras una IP son 200 cubos independientes.
 *
 *  2. Los limites por IP existen solo como red de seguridad contra
 *     automatizacion, y se fijan MUY por encima del uso humano legitimo: 40
 *     envios/min por IP tolera una sala grande respondiendo a la vez, mientras
 *     que un humano envia 1.
 *
 *  3. Nunca se devuelve 429 en la PRIMERA carga de una encuesta a un visitante
 *     sin token: ese es exactamente el asistente que acaba de escanear el QR.
 *
 *  4. El login SI se limita estrictamente por email+IP. Ahi el riesgo de
 *     fuerza bruta supera al de molestar a un admin legitimo, y solo hay un
 *     punado de administradores.
 * ======================================================================
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loginLimiter();
        $this->participantLimiters();
        $this->adminLimiters();
    }

    /**
     * Login: 5/min y 20/hora por `email + IP` compuesto.
     *
     * La clave incluye el email para que un atacante que pruebe mil correos
     * distintos desde una IP no consuma el cubo del administrador legitimo que
     * comparte esa IP, y para que atacar una cuenta concreta sea lento con
     * independencia de desde donde se intente.
     */
    private function loginLimiter(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower((string) $request->input('email')) . '|' . $request->ip();

            return [
                Limit::perMinute(5)->by('login:min:' . $key)
                    ->response(fn () => $this->tooMany($request, 'login')),
                Limit::perHour(20)->by('login:hour:' . $key)
                    ->response(fn () => $this->tooMany($request, 'login')),
            ];
        });
    }

    private function participantLimiters(): void
    {
        /*
         * Apertura de la pagina publica. 60/min, por token si existe.
         *
         * Regla 3: un visitante SIN token todavia no ha recibido cookie, asi
         * que cae en la rama de IP. Por eso el limite es generoso y, sobre
         * todo, por eso no se castiga: `public-open` solo cuenta por IP hasta
         * que hay token, y 60/min deja entrar a una sala entera escaneando el
         * QR a la vez.
         */
        RateLimiter::for('public-open', function (Request $request) {
            return Limit::perMinute(60)
                ->by($this->participantKey($request, 'open'))
                ->response(fn () => $this->tooMany($request, 'public-open'));
        });

        /*
         * Envio de respuesta. DOS cubos simultaneos:
         *   · 5/min por TOKEN  -> el limite que de verdad protege.
         *   · 40/min por IP    -> red de seguridad anti-automatizacion, muy
         *                         por encima del uso humano (regla 2).
         */
        RateLimiter::for('response-submit', function (Request $request) {
            return [
                Limit::perMinute(5)
                    ->by($this->participantKey($request, 'submit'))
                    ->response(fn () => $this->tooMany($request, 'response-submit')),
                Limit::perMinute(40)
                    ->by('submit-ip:' . $request->ip())
                    ->response(fn () => $this->tooMany($request, 'response-submit-ip')),
            ];
        });

        // Heartbeat: el intervalo normal son 3/min (cada 20 s, D-12). 10/min
        // deja margen para reconexiones sin castigar una red inestable.
        RateLimiter::for('heartbeat', function (Request $request) {
            return Limit::perMinute(10)
                ->by($this->participantKey($request, 'hb'))
                ->response(fn () => response()->noContent(429));
        });

        RateLimiter::for('session-issue', function (Request $request) {
            return Limit::perMinute(10)
                ->by('session-issue:' . $request->ip())
                ->response(fn () => $this->tooMany($request, 'session-issue'));
        });

        RateLimiter::for('public-state', function (Request $request) {
            return Limit::perMinute(30)->by($this->participantKey($request, 'state'));
        });
    }

    private function adminLimiters(): void
    {
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(120)->by('admin:' . ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinute(10)
                ->by('export:' . ($request->user()?->id ?? $request->ip()))
                ->response(fn () => $this->tooMany($request, 'export'));
        });

        RateLimiter::for('qr', function (Request $request) {
            return Limit::perMinute(30)->by('qr:' . ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(60)->by('bauth:' . ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(60)->by('health:' . $request->ip());
        });
    }

    /**
     * Clave de limitacion de un participante.
     *
     * Prefiere SIEMPRE el token (cubo propio por telefono) y solo cae a la IP
     * cuando todavia no hay cookie. Se usa un hash del token y no el token:
     * la clave acaba en el almacen de cache, y meter ahi un secreto de sesion
     * seria otro sitio desde el que se podria filtrar (ADR-0005).
     */
    private function participantKey(Request $request, string $scope): string
    {
        foreach ($request->cookies->all() as $name => $value) {
            if (str_starts_with($name, (string) config('encuestalive.participant.cookie_prefix'))
                && is_string($value) && $value !== '') {
                return $scope . ':t:' . substr(hash('sha256', $value), 0, 32);
            }
        }

        return $scope . ':ip:' . $request->ip();
    }

    /**
     * Respuesta 429 estandar (API.md §0.4): `Retry-After`, mensaje comprensible
     * y `correlation_id`. Nunca detalles internos del limitador.
     */
    private function tooMany(Request $request, string $limiter)
    {
        SecurityLog::rateLimited($request, $limiter);

        $seconds = 60;

        return response()->json([
            'message' => sprintf('Demasiados intentos. Vuelve a probar en %d segundos.', $seconds),
            'correlation_id' => $request->attributes->get('el.correlation_id'),
        ], 429, ['Retry-After' => (string) $seconds]);
    }
}
