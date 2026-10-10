<?php

namespace App\Support;

use RuntimeException;

/**
 * F-07 (ALTO, Fase 7) — DIFUSORES QUE DESACTIVAN LA AUTORIZACION DE CANAL.
 *
 * ======================================================================
 *  EL FALLO, EN UNA FRASE
 * ======================================================================
 * `routes/channels.php` implementa correctamente CA-12/R-09: resuelve la
 * encuesta por su public_id y aplica SurveyPolicy::view. Ese callback es
 * correcto. **Pero con los difusores `log` y `null` NUNCA SE EJECUTA.**
 *
 * La autorizacion de canal la delega BroadcastManager en el metodo `auth()`
 * del difusor activo, y en esos dos el cuerpo del metodo es literalmente `//`:
 *
 *   vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/LogBroadcaster.php
 *       public function auth($request) { / / }
 *   vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/NullBroadcaster.php
 *       public function auth($request) { / / }
 *
 * No consultan el callback, no comprueban nada y no lanzan excepcion. Para el
 * llamante, "no lanzo excepcion" significa AUTORIZADO. El auditor lo verifico
 * contra los tres difusores: bajo `log`/`null` se autoriza a todo el mundo,
 * **incluido un anonimo**; bajo `reverb` los cuatro casos se deniegan o
 * autorizan exactamente como el diseno pretende.
 *
 * Y `log` era el valor ENTREGADO en `.env` y `.env.example`.
 *
 * ======================================================================
 *  POR QUE ESTA CLASE Y NO UN `if` SUELTO
 * ======================================================================
 * El conocimiento "estos difusores no autorizan" estaba repartido entre un
 * comentario de una prueba y la cabeza del equipo. Aqui pasa a ser un dato
 * unico, consultable por el arranque de la aplicacion, por `/health` y por la
 * suite. Si manana Laravel anade otro difusor sin `auth()`, se anade a la
 * lista y todos los controles se enteran a la vez.
 *
 * NOTA DE ALCANCE HONESTA: esto NO es un parche al framework. `log` y `null`
 * siguen sin autorizar. Lo que se impide es que la aplicacion se CONFIGURE con
 * ellos sin que nadie se entere.
 */
final class BroadcastGuard
{
    /**
     * Difusores cuyo `auth()` esta vacio: autorizan a cualquiera.
     *
     * @var array<int, string>
     */
    public const UNAUTHORIZING_DRIVERS = ['log', 'null'];

    /**
     * Difusor que la aplicacion usa cuando el entorno no dice otra cosa.
     *
     * Es el valor de respaldo de `config/broadcasting.php` y el que trae
     * `.env.example`. D-02 lo fija: Reverb es el primario.
     */
    public const APPLICATION_DEFAULT = 'reverb';

    /** Un difusor sin autorizacion de canal. */
    public static function disablesChannelAuthorization(?string $driver): bool
    {
        // Normalizado antes de comparar (F-16, segunda pasada del retest).
        // `in_array(..., true)` distingue mayusculas, pero PHP resuelve los
        // nombres de clase sin distinguirlas: `log` bloqueaba el arranque
        // mientras que `LOG`, `Log` o `Null` lo permitian **en silencio** y
        // seguian cargando LogBroadcaster. La comparacion debe ser tan
        // indulgente como lo es el lenguaje que la rodea.
        return in_array(mb_strtolower(trim((string) $driver)), self::UNAUTHORIZING_DRIVERS, true);
    }

    /**
     * El DRIVER del difusor configurado ahora mismo.
     *
     * F-16 (regresion detectada en el retest de la Fase 7c). Esto devolvia
     * `config('broadcasting.default')`, que es el NOMBRE de la conexion, no su
     * driver. La comprobacion contrastaba ese nombre contra una lista de
     * DRIVERS, asi que bastaba con bautizar la conexion de otra forma para
     * burlar la guarda entera:
     *
     *     'realtime-off' => ['driver' => 'log']
     *
     * Con eso la aplicacion arrancaba en produccion sin una sola protesta y
     * cualquier anonimo quedaba AUTORIZADO en los canales privados: F-07
     * reabierto por una puerta lateral.
     *
     * Ahora se resuelve el driver real. Si la conexion no existe en
     * `broadcasting.connections`, se devuelve el propio nombre: asi una
     * configuracion rota se compara contra la lista en vez de colarse por el
     * hueco. Ante la duda, ruidoso antes que permisivo.
     */
    public static function current(): string
    {
        $connection = (string) config('broadcasting.default');

        return (string) config("broadcasting.connections.{$connection}.driver", $connection);
    }

    /**
     * Comprobacion de arranque.
     *
     * En produccion se NIEGA a arrancar: desplegar con `log` convierte una
     * autorizacion correctamente escrita en un "si" incondicional, y el
     * sistema parece funcionar con normalidad. Un fallo silencioso de
     * autorizacion es peor que una caida ruidosa.
     *
     * Fuera de produccion solo avisa: `log` es legitimo para desarrollar sin
     * levantar Reverb, siempre que quien lo elija sepa lo que apaga.
     *
     * @throws RuntimeException en produccion con un difusor sin autorizacion
     */
    public static function assertSafeForEnvironment(string $environment, ?string $driver = null): ?string
    {
        $driver ??= self::current();

        if (! self::disablesChannelAuthorization($driver)) {
            return null;
        }

        $message = sprintf(
            'BROADCAST_CONNECTION=%s desactiva POR COMPLETO la autorizacion de canales privados: '
            .'%sBroadcaster::auth() tiene el cuerpo vacio, de modo que el callback de '
            .'routes/channels.php nunca se ejecuta y CUALQUIERA queda autorizado (hallazgo F-07). '
            .'Usa "%s" (D-02).',
            $driver,
            ucfirst($driver),
            self::APPLICATION_DEFAULT,
        );

        if ($environment === 'production') {
            throw new RuntimeException($message);
        }

        return $message;
    }
}
