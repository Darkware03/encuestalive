<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A. Envio completado. Una fila = un envio finalizado y persistido (PRD §8.3).
 *
 * INVARIANTE DE PRIVACIDAD: sin columna de IP ni user-agent.
 *
 * ON DELETE:
 *  - survey_id             -> CASCADE   (eliminacion total de la encuesta, solo por consola)
 *  - participant_session_id-> SET NULL  (RN-09.5: la purga de metadatos de sesion a los
 *                                        N dias del cierre desvincula el envio SIN borrarlo)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();

            $table->foreignId('participant_session_id')->nullable()
                ->constrained('participant_sessions')->nullOnDelete();

            // RN-05 capa 2: clave de idempotencia generada por el cliente antes del
            // primer intento y constante entre reintentos.
            $table->uuid('submission_uuid');

            // RN-03: cada envio guarda la version de la estructura con la que respondio.
            $table->unsignedInteger('survey_version_number')->default(1);

            // PRD §8.3 / ARCHITECTURE §7.3: precision de segundo (la necesita el grafico
            // de evolucion). Contrasta con security_events.occurred_at, truncado al minuto.
            $table->timestamp('completed_at');

            $table->timestamps();

            // ADR-0005 capa 3 (defensa autoritativa): UNA respuesta por sesion.
            // La columna es nullable y UNIQUE admite multiples NULL tanto en SQLite
            // como en MySQL 8, por lo que la purga de RN-09.5 no colisiona.
            $table->unique('participant_session_id', 'responses_session_unique');

            // RN-05 capa 2: reintento con la misma clave -> no crea fila nueva.
            $table->unique(['survey_id', 'submission_uuid'], 'responses_survey_submission_uuid_unique');

            // Grafico de evolucion temporal y recuento de completadas por encuesta.
            $table->index(['survey_id', 'completed_at'], 'responses_survey_completed_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responses');
    }
};
