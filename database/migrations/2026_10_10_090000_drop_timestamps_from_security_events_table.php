<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-01 (ALTO, Fase 7) — LA TRUNCATURA AL MINUTO ERA DECORATIVA.
 *
 * =====================================================================
 *  POR QUE SE ELIMINAN created_at / updated_at DE LA ISLA B
 * =====================================================================
 * La migracion original llamaba a `$table->timestamps()`. El modelo trunca
 * `occurred_at` al minuto (ARCHITECTURE.md §7.3) para degradar la correlacion
 * temporal con `responses.completed_at`, pero Eloquent escribia `created_at`
 * en la MISMA FILA con precision de SEGUNDO, y nadie la tocaba.
 *
 * El auditor lo demostro: `occurred_at 22:22:00` frente a `created_at 22:32:08`.
 * Un JOIN por ventana de +-2 s entre `security_events.created_at` y
 * `responses.completed_at` devolvia candidatos de reasociacion respuesta<->IP.
 * Treinta lineas de comentario defendiendo la privacidad quedaban anuladas por
 * una linea de conveniencia.
 *
 * DECISION: se ELIMINAN, no se truncan. Justificacion:
 *
 *  1. `occurred_at` YA ES la marca temporal canonica del evento. `created_at`
 *     seria un duplicado suyo con peor garantia de privacidad.
 *  2. Un `security_event` es INMUTABLE: se escribe una vez y no se actualiza
 *     jamas (no hay un solo `->update()` sobre el modelo en todo el codigo).
 *     `updated_at` carece literalmente de significado.
 *  3. Truncarlas tambien al minuto dejaria tres columnas que hay que recordar
 *     truncar. Eliminarlas convierte la propiedad en estructural: no se puede
 *     olvidar truncar una columna que no existe. Es el mismo criterio —
 *     acertado — que el diseno aplico a la isla A: la consulta no se puede
 *     escribir porque la columna no esta.
 *  4. Esta tabla no necesita auditoria de si misma. Es el registro de
 *     auditoria.
 *
 * La propiedad queda verificada por
 * tests/Feature/Database/TemporalPrecisionTest.php, que falla si CUALQUIER
 * columna temporal de la isla B persiste segundos distintos de cero.
 *
 * REVERSIBILIDAD: `down()` las vuelve a crear como nullable para no romper una
 * reversion, pero reintroduce el hallazgo F-01. No se debe revertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            // Reintroduce F-01. Documentado a proposito.
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
};
