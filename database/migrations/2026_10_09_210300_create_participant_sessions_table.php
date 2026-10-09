<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A. Sesion de participacion anonima (PRD §8.0, ADR-0004, ADR-0005).
 *
 * INVARIANTE DE PRIVACIDAD (brief §5.2, ARCHITECTURE.md §7.2.1):
 *   Esta tabla NO tiene, y no debe tener nunca, columna de IP, user-agent,
 *   idioma, zona horaria, resolucion ni referente. La columna no existe, de modo
 *   que la consulta que uniria una respuesta con una IP es imposible de escribir.
 *   Verificado por tests/Feature/Database/PrivacySeparationTest.php.
 *
 * ADR-0005: se guarda SHA-256 del token opaco, jamas el token en claro.
 * El alcance es POR ENCUESTA: el unico indice de busqueda es (survey_id, token_hash),
 * asi que un mismo navegador en dos encuestas produce dos filas no relacionables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();

            // SHA-256 en hexadecimal = 64 caracteres exactos.
            $table->string('token_hash', 64);

            // started | in_progress | completed  (PRD §8.0 estado_envio)
            $table->string('submission_state', 20)->default('started');

            $table->timestamp('first_seen_at');
            // ADR-0004: heartbeat. "Conectada" se DERIVA de esta marca, no hay contador.
            $table->timestamp('last_seen_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // ADR-0005 / ADR-0004: una fila por token y encuesta. Dos pestanas del mismo
            // telefono comparten cookie -> mismo token -> una fila -> cuenta uno.
            // Es ademas el indice de resolucion de la cookie en cada peticion.
            $table->unique(['survey_id', 'token_hash'], 'participant_sessions_survey_token_unique');

            // ADR-0004 (encargo explicito): sostiene la consulta de presencia
            //   WHERE survey_id = ? AND last_seen_at > (ahora - ventana)
            // que se ejecuta en cada refresco del dashboard.
            $table->index(['survey_id', 'last_seen_at'], 'participant_sessions_survey_last_seen_index');

            // PRD §8.2/§8.3/§8.4: participaron / completadas / pendientes agrupan por estado.
            $table->index(['survey_id', 'submission_state'], 'participant_sessions_survey_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_sessions');
    }
};
