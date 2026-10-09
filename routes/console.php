<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| F-13 (ALTO, Fase 7) — LA RETENCION DE 30 DIAS NO SE EJECUTABA JAMAS
|--------------------------------------------------------------------------
|
| Los dos comandos de purga estaban escritos, probados y funcionaban. Pero
| NADIE LOS INVOCABA: `php artisan schedule:list` devolvia literalmente
| "No scheduled tasks have been defined".
|
| Esto no era solo una tarea olvidada. `resources/views/public/privacy.blade.php`
| PROMETE a los participantes, en la pagina publica de privacidad, que sus
| registros se borran a los N dias. Mientras no hubo planificador, esa frase
| era FALSA, y una promesa de privacidad incumplida es peor que no haberla
| hecho. Se corrige aqui como cuestion de honestidad con el usuario, no solo
| de higiene tecnica.
|
| Ademas, la purga es EL control que de verdad acota la ventana de correlacion
| temporal de F-01/F-02: sin ella, el universo de filas correlacionables no
| son 30 dias, es todo el historial.
|
| ---------------------------------------------------------------------------
| LIMITACION, DECLARADA SIN ADORNOS (verificable solo parcialmente aqui)
| ---------------------------------------------------------------------------
| Registrar la tarea NO la ejecuta. El planificador de Laravel necesita que
| algo invoque `schedule:run` cada minuto. En produccion eso es una entrada de
| cron del sistema:
|
|     * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
|
| **EN ESTE HOST NO HAY CRON NI SYSTEMD**, y no hay root para instalarlos. Por
| tanto:
|
|   · VERIFICADO aqui: que ambos comandos estan registrados y aparecen en
|     `schedule:list`, y que al ejecutarlos purgan de verdad
|     (tests/Feature/Console/ScheduledRetentionTest.php).
|   · NO VERIFICADO aqui: que se disparen solos cada noche. Eso depende de una
|     entrada de cron del entorno de destino y es responsabilidad de la Fase 8
|     (devops). Queda declarado como limitacion, no como aprobado.
|
| La guia de despliegue debe incluir la entrada de cron Y una comprobacion de
| que `schedule:list` no esta vacio despues de desplegar.
|
*/

/*
 * Isla B: borrado DURO de los registros de seguridad pasados los
 * SECURITY_LOG_RETENTION_DAYS (30 por defecto, D-12).
 *
 * `withoutOverlapping()` porque SQLite es de un solo escritor y un borrado
 * largo solapado con el siguiente solo produce bloqueos. `onOneServer()` para
 * que, si manana hay mas de una instancia, la purga no se duplique.
 * `runInBackground()` NO se usa a proposito: queremos el codigo de salida.
 */
Schedule::command('encuestalive:prune-security-events')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Purga la isla B pasados los dias de retencion (D-12, F-13).');

/*
 * RN-09.5: desvinculacion de los metadatos de sesion de encuestas cerradas
 * hace tiempo (ON DELETE SET NULL). Se separa 15 minutos de la anterior para
 * no competir por el unico escritor de SQLite.
 */
Schedule::command('encuestalive:prune-sessions')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Desvincula metadatos de sesion antiguos (RN-09.5, F-13).');
