<?php

namespace App\Livewire\Admin;

use App\Domain\Stats\StatsService;
use App\Models\Survey;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dashboard de resultados en vivo (pantallas 10 y 11).
 *
 * ============ LA REGLA DURA DE D-02, IMPLEMENTADA AQUI ============
 * "El dashboard lee SIEMPRE de la base de datos via StatsService. El WebSocket
 * solo indica CUANDO refrescar, nunca QUE dato mostrar."
 *
 * Mirese `onStatsUpdated()`: recibe `$payload` y NO LO USA. Lo descarta
 * expresamente y llama a `refreshStats()`, que reconsulta la base de datos.
 *
 * Esto no es desconfianza decorativa. Es lo que hace que:
 *   · los contadores sean IDENTICOS con Reverb y con `wire:poll`;
 *   · un evento perdido, duplicado o entregado fuera de orden no produzca
 *     deriva, porque no hay estado incremental que corromper;
 *   · tras un reinicio de Reverb o de la aplicacion, la primera consulta ya
 *     devuelva el valor correcto sin reproducir ningun evento (CA-15).
 * ==================================================================
 *
 * MODELO DE SONDEO (ADR-0002 §8.2, CORREGIDO EN FASE 6b POR DEF-01):
 *   connected    -> wire:poll.15s  (sondeo ESPACIADO, nunca apagado)
 *   reconnecting -> wire:poll.10s, para que no se congele durante el corte
 *   degraded     -> wire:poll.5s PERMANENTE, el respaldo obligatorio de D-02
 *
 * ============ DEF-01: POR QUE `connected` YA NO ES `null` ============
 * La version anterior devolvia null en `connected`, lo que borraba
 * `wire:poll` del DOM. Como Livewire 4.4.7 NO trae puente con Echo, el
 * dashboard quedaba congelado mostrando "Conectado · en vivo" y solo se
 * actualizaba con Reverb CAIDO. Se violaba la regla dura de D-02.
 *
 * Ahora el sondeo es un SUELO que nunca se retira. Reverb ACELERA el
 * refresco (el puente de `realtime.js` llama a `onStatsUpdated` en cuanto
 * llega el frame), pero si la difusion falla en silencio lo peor que ocurre
 * es 15 s de latencia, no una pantalla muerta.
 * =====================================================================
 */
class LiveDashboard extends Component
{
    public Survey $survey;

    /** connected | reconnecting | degraded */
    public string $connectionState = 'degraded';

    public ?string $lastUpdatedAt = null;

    /** brief §4.5: reducir la exposicion de datos personales accidentales. */
    public bool $showOpenText = true;

    public function mount(Survey $survey): void
    {
        // Autorizacion por recurso: un admin no ve el dashboard de una
        // encuesta ajena, aunque escriba la URL a mano (CA-12).
        $this->authorize('view', $survey);

        $this->survey = $survey;
        $this->touchTimestamp();
    }

    /**
     * Reconsulta completa contra la base de datos.
     *
     * Es la UNICA via por la que entran datos en esta pantalla, venga el
     * disparo del montaje, de un evento de Echo, de `wire:poll` o de una
     * reconexion. Un solo camino significa que no hay dos caminos que puedan
     * discrepar.
     */
    public function refreshStats(): void
    {
        $this->survey->refresh();
        $this->touchTimestamp();
    }

    /**
     * Llega un `stats.updated` por el canal privado.
     *
     * ============ DEF-01: COMO SE INVOCA ESTE METODO ============
     * ANTES llevaba el atributo
     *   #[On('echo-private:survey.{survey.public_id}.admin,stats.updated')]
     * que era CODIGO MUERTO: livewire/livewire 4.4.7 no interpreta el prefijo
     * `echo-private:` (verificado, 0 apariciones en su src/ y su dist/; la
     * unica funcion relacionada es SupportPolling). Registraba un oyente para
     * un nombre de evento literal que nada emitia jamas.
     *
     * Se ha ELIMINADO el atributo en lugar de dejarlo "por si acaso": codigo
     * que aparenta funcionar y no hace nada es peor que no tenerlo, porque
     * costo una fase entera de QA descubrir que no se disparaba.
     *
     * El puente REAL vive ahora en `resources/js/realtime.js`, que se
     * suscribe con Echo a `private-survey.{publicId}.admin`, escucha
     * `.stats.updated` (con punto inicial: el evento usa broadcastAs()) y
     * llama explicitamente a este metodo por `Livewire.find(id).call(...)`.
     * ============================================================
     *
     * `$payload` se recibe y SE IGNORA DELIBERADAMENTE. Es una senal de
     * "algo cambio, vuelve a mirar", no un dato que pintar.
     */
    public function onStatsUpdated(array $payload = []): void
    {
        $this->refreshStats();
    }

    /** Igual que `onStatsUpdated`: lo invoca el puente de `realtime.js`. */
    public function onTextReceived(array $payload = []): void
    {
        // Tampoco se antepone el texto del payload al feed: se reconsulta.
        // Asi el feed y los contadores proceden del mismo instante de lectura
        // y no pueden contarse historias distintas.
        $this->refreshStats();
    }

    /**
     * Conecta el indicador de conexion que `frontend-engineer` dejo expuesto
     * en `window.EncuestaLive.setConnectionState()` y que hasta ahora nadie
     * llamaba. El JS de Echo informa aqui del estado real del socket.
     */
    public function setConnectionState(string $state): void
    {
        if (! in_array($state, ['connected', 'reconnecting', 'degraded'], true)) {
            return;
        }

        $this->connectionState = $state;

        // Al recuperar la conexion, reconciliacion completa inmediata. No se
        // intentan reproducir los eventos perdidos durante el corte: la base
        // de datos es la verdad y recuperarla es una sola consulta (§8.2).
        if ($state === 'connected') {
            $this->refreshStats();
        }
    }

    public function toggleOpenTextVisibility(): void
    {
        $this->showOpenText = ! $this->showOpenText;
    }

    private function touchTimestamp(): void
    {
        $this->lastUpdatedAt = Carbon::now()->format('H:i:s');
    }

    /**
     * Intervalo de sondeo segun el estado de conexion (ADR-0002 §8.2).
     *
     * CONTRATO DURO (D-02, reforzado tras DEF-01): el tipo de retorno es
     * `string`, NO `?string`. Es imposible, por construccion, que este metodo
     * apague el respaldo. Si algun dia alguien anade un estado nuevo, el
     * `default` lo manda al sondeo mas agresivo (5 s), que es el lado seguro
     * del error: refrescar de mas nunca rompe nada, refrescar de menos deja
     * un dashboard mintiendo.
     */
    public function pollInterval(): string
    {
        return match ($this->connectionState) {
            'connected' => (string) config('encuestalive.realtime.poll_connected'),
            'reconnecting' => (string) config('encuestalive.realtime.poll_reconnecting'),
            default => (string) config('encuestalive.realtime.poll_degraded'),
        };
    }

    /** Estado del indicador visual (x-connection-indicator, 4 estados). */
    public function indicatorState(): string
    {
        return match ($this->connectionState) {
            'connected' => 'online',
            'reconnecting' => 'reconnecting',
            default => 'stale',
        };
    }

    public function indicatorDetail(): string
    {
        return match ($this->connectionState) {
            // Honesto en los dos sentidos: hay socket Y hay red de seguridad.
            'connected' => 'en vivo',
            'reconnecting' => 'reconectando…',
            // Texto HONESTO: no se promete tiempo real si no lo hay.
            default => 'actualizacion cada ' . $this->pollInterval(),
        };
    }

    #[Layout('layouts.admin')]
    public function render(StatsService $stats): View
    {
        // Instantanea leida de la BASE DE DATOS en cada render. Sin cache,
        // sin contador en memoria, sin estado acumulado entre peticiones.
        $snapshot = $stats->snapshot($this->survey);

        return view('livewire.admin.live-dashboard', [
            'stats' => $snapshot,
            'openText' => $this->showOpenText
                ? $stats->openTextAnswers($this->survey, 50)
                : [],
            // Si esto no viene vacio, hay un fallo de coherencia (CA-10) y la
            // pantalla debe decirlo en lugar de pintar numeros que no cuadran.
            'violations' => $snapshot->invariantViolations(),
            'pollInterval' => $this->pollInterval(),
        ]);
    }
}
