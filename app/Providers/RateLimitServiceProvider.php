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
            // F-12: el email tambien es un dato personal y acaba en la clave
            // de cache. Se deriva el par entero con sal en vez de en claro.
            $key = substr(hash('sha256', mb_strtolower((string) $request->input('email'))
                . '|' . $request->ip() . '|' . config('app.key')), 0, 32);

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
            return [
                Limit::perMinute($this->limit('public_open_per_token'))
                    ->by($this->participantKey($request, 'open'))
                    ->response(fn () => $this->tooMany($request, 'public-open')),
                // F-06: red de seguridad por IP. No existia.
                Limit::perMinute($this->limit('public_open_per_ip'))
                    ->by($this->ipKey($request, 'open-ip'))
                    ->response(fn () => $this->tooMany($request, 'public-open-ip')),
            ];
        });

        /*
         * Envio de respuesta. DOS cubos simultaneos:
         *   · 5/min por TOKEN  -> el limite que de verdad protege.
         *   · 40/min por IP    -> red de seguridad anti-automatizacion, muy
         *                         por encima del uso humano (regla 2).
         */
        RateLimiter::for('response-submit', function (Request $request) {
            return [
                Limit::perMinute($this->limit('submit_per_token'))
                    ->by($this->participantKey($request, 'submit'))
                    ->response(fn () => $this->tooMany($request, 'response-submit')),
                Limit::perMinute($this->limit('submit_per_ip'))
                    ->by($this->ipKey($request, 'submit-ip'))
                    ->response(fn () => $this->tooMany($request, 'response-submit-ip')),
            ];
        });

        // Heartbeat: el intervalo normal son 3/min (cada 20 s, D-12). 10/min
        // deja margen para reconexiones sin castigar una red inestable.
        RateLimiter::for('heartbeat', function (Request $request) {
            return [
                Limit::perMinute($this->limit('heartbeat_per_token'))
                    ->by($this->participantKey($request, 'hb'))
                    ->response(fn () => response()->noContent(429)),
                /*
                 * F-06: red de seguridad por IP. No existia, y esta es la ruta
                 * que mas duele: `heartbeat` ESCRIBE en participant_sessions y
                 * SQLite admite un unico escritor (riesgo R3).
                 */
                Limit::perMinute($this->limit('heartbeat_per_ip'))
                    ->by($this->ipKey($request, 'hb-ip'))
                    ->response(fn () => response()->noContent(429)),
            ];
        });

        RateLimiter::for('session-issue', function (Request $request) {
            return Limit::perMinute($this->limit('session_issue_per_ip'))
                ->by($this->ipKey($request, 'session-issue'))
                ->response(fn () => $this->tooMany($request, 'session-issue'));
        });

        RateLimiter::for('public-state', function (Request $request) {
            return [
                Limit::perMinute($this->limit('public_state_per_token'))
                    ->by($this->participantKey($request, 'state')),
                // F-06: red de seguridad por IP. No existia.
                Limit::perMinute($this->limit('public_state_per_ip'))
                    ->by($this->ipKey($request, 'state-ip')),
            ];
        });
    }

    private function adminLimiters(): void
    {
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(120)->by($this->actorKey($request, 'admin'));
        });

        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinute(10)
                ->by($this->actorKey($request, 'export'))
                ->response(fn () => $this->tooMany($request, 'export'));
        });

        RateLimiter::for('qr', function (Request $request) {
            return Limit::perMinute(30)->by($this->actorKey($request, 'qr'));
        });

        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(60)->by($this->actorKey($request, 'bauth'));
        });

        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(60)->by($this->ipKey($request, 'health'));
        });
    }

    /** Lectura de un limite configurado (brief §5.3: configurable). */
    private function limit(string $key): int
    {
        return max(1, (int) config('encuestalive.rate_limits.'.$key));
    }

    /**
     * Clave de limitacion de un participante.
     *
     * Prefiere SIEMPRE el token (cubo propio por telefono) y solo cae a la IP
     * cuando todavia no hay cookie utilizable. Se usa un hash del token y no
     * el token: la clave acaba en el almacen de cache, y meter ahi un secreto
     * de sesion seria otro sitio desde el que se podria filtrar (ADR-0005).
     *
     * =================================================================
     *  F-06 — QUE SE CORRIGIO Y QUE RESULTO SER FALSO POSITIVO
     * =================================================================
     * El informe afirma que "un cliente que envie en cada peticion una cookie
     * `el_pt_xxxxxxxx` con un valor aleatorio distinto obtiene un cubo nuevo
     * cada vez, es decir, limitacion nula".
     *
     * **Esa parte concreta NO se sostiene, y se comprobo antes de darla por
     * buena.** La cookie de participante NO esta exenta de cifrado, y
     * `EncryptCookies` corre en la posicion 0 de la pila mientras
     * `ThrottleRequests` corre en la 5 (verificado enumerando la pila real de
     * la ruta). Un valor que no descifre se convierte en `null` ANTES de
     * llegar al limitador, de modo que `is_string($value)` es falso y la
     * peticion cae en la rama de IP. Un valor aleatorio no crea cubo: cae al
     * cubo de su IP. Lo verifica
     * tests/Feature/Security/RateLimitKeyTest.php.
     *
     * **Lo que SI era real, y es lo que de verdad importaba del hallazgo:**
     * `public-open`, `heartbeat` y `public-state` no tenian NINGUNA red de
     * seguridad por IP. Un cliente que acumulase tokens legitimos (abrir la
     * encuesta sin cookie emite uno nuevo cada vez) sumaba un cubo por cada
     * token conservado, y su capacidad crecia sin techo con el tiempo. Eso se
     * corrige con el segundo cubo por IP de cada limitador.
     *
     * **Y por defensa en profundidad** se exige ademas que el valor tenga la
     * forma del token (43 caracteres Base64 URL-safe, ADR-0005). Hoy es
     * redundante con el cifrado de cookies; deja de serlo el dia que alguien
     * anada esta cookie a la lista de exenciones de `EncryptCookies`, que es
     * justo el cambio silencioso que convertiria el falso positivo en un
     * hallazgo real. No cuesta nada y cierra esa puerta por adelantado.
     *
     * No se consulta la base de datos: un limitador debe ser barato, y
     * ademas `AttachParticipantSession` corre DESPUES (posicion 9), asi que
     * la sesion resuelta todavia no existe aqui. Por eso la recomendacion del
     * informe de usar `el.participant_session` no es aplicable sin reordenar
     * la pila, y reordenarla seria PEOR: pondria una escritura en base de
     * datos por delante del limitador.
     */
    private function participantKey(Request $request, string $scope): string
    {
        $prefix = (string) config('encuestalive.participant.cookie_prefix');

        foreach ($request->cookies->all() as $name => $value) {
            if (str_starts_with($name, $prefix) && $this->looksLikeToken($value)) {
                return $scope . ':t:' . substr(hash('sha256', (string) $value), 0, 32);
            }
        }

        return $this->ipKey($request, $scope);
    }

    /** Forma del token opaco de ADR-0005: Base64 URL-safe sin relleno. */
    private function looksLikeToken(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        $expected = (int) ceil(((int) config('encuestalive.participant.token_bytes')) * 4 / 3);

        return preg_match('/^[A-Za-z0-9_-]{'.$expected.'}$/', $value) === 1;
    }

    /**
     * Clave de un actor administrativo: su id si esta autenticado, su IP
     * derivada con sal si no (F-12).
     *
     * Un id de usuario no es un dato de red y no necesita sal; la IP si.
     */
    private function actorKey(Request $request, string $scope): string
    {
        $user = $request->user();

        return $user !== null
            ? $scope . ':u:' . $user->id
            : $this->ipKey($request, $scope);
    }

    /**
     * Clave por IP, derivada con sal (F-12, BAJO).
     *
     * La clave logica llega al almacen de cache. `ThrottleRequests` ya le
     * aplica un `md5()` antes de persistirla, de modo que la IP NO se guarda
     * en claro —el informe lo comprobo y refuto su propia hipotesis inicial—,
     * pero un MD5 sin sal sobre un dato de entropia tan baja es reversible con
     * una tabla precalculada trivial: el auditor recupero la IP acotando el
     * ultimo octeto en 0,0001 s.
     *
     * Con `app.key` como sal, la derivacion deja de ser invertible con una
     * tabla generica. El limitador funciona igual: solo necesita una clave
     * ESTABLE, nunca una clave legible. Es el mismo tratamiento que este
     * fichero ya daba al token.
     */
    private function ipKey(Request $request, string $scope): string
    {
        $salted = hash('sha256', (string) $request->ip() . '|' . config('app.key'));

        return $scope . ':ip:' . substr($salted, 0, 32);
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
