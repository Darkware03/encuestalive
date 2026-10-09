<?php

namespace App\Domain\Realtime;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emision de eventos a prueba de fallos de difusion.
 *
 * ============== LA REGLA QUE JUSTIFICA ESTA CLASE ==============
 * D-02, innegociable: "Un fallo de broadcasting JAMAS puede impedir que se
 * guarde una respuesta."
 *
 * Si Reverb no esta arrancado, el puerto esta cerrado, el proceso murio o la
 * llamada HTTP interna del protocolo Pusher agota su tiempo, `event()` lanza.
 * Sin esta envoltura, esa excepcion subiria por ParticipationService y haria
 * fracasar un envio que YA ESTABA PERSISTIDO en la base de datos: el
 * participante veria un error 500 por una respuesta que si se guardo.
 *
 * Aqui el fallo se captura, se registra como `broadcast_failed` y se sigue.
 * Es exactamente la razon por la que el sistema degrada con elegancia: el
 * respaldo `wire:poll` de 5 s recogera el dato en el siguiente ciclo, porque
 * el dashboard lee de la base de datos y no del evento.
 * ===============================================================
 *
 * Lo que NO hace: tragarse errores de persistencia. Esta clase solo envuelve
 * la difusion. Un fallo al escribir en la base de datos debe propagarse y
 * abortar la transaccion.
 */
class SafeBroadcaster
{
    private bool $lastAttemptFailed = false;

    /**
     * Emite el evento. Devuelve true si la difusion no lanzo.
     *
     * @param  object  $event  evento de broadcasting ya construido
     */
    public function dispatch(object $event): bool
    {
        try {
            event($event);
            $this->lastAttemptFailed = false;

            return true;
        } catch (Throwable $e) {
            $this->lastAttemptFailed = true;

            /*
             * Se registra la CLASE del evento y el mensaje del fallo de
             * transporte, nunca la carga util: un `stats.updated` lleva
             * agregados y un `text.received` lleva texto de un participante,
             * que ARCHITECTURE.md §9.2 prohibe expresamente escribir en
             * cualquier log, "ni siquiera truncado".
             */
            Log::warning('broadcast_failed', [
                'event' => $event::class,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Para que /health declare `reverb` o `fallback` con honestidad. */
    public function lastAttemptFailed(): bool
    {
        return $this->lastAttemptFailed;
    }
}
