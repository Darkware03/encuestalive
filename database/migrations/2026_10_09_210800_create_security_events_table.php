<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA B — REGISTROS TECNICOS DE SEGURIDAD (brief §5.1, ARCHITECTURE.md §7).
 *
 * =====================================================================
 *  SEPARACION FISICA. LEER ANTES DE ANADIR UNA SOLA COLUMNA AQUI.
 * =====================================================================
 * Esta es la UNICA tabla del sistema que almacena direcciones IP.
 *
 * PROHIBIDO, y verificado por tests/Feature/Database/PrivacySeparationTest.php:
 *   - participant_session_id   (no existe)
 *   - response_id              (no existe)
 *   - response_answer_id       (no existe)
 *   - token / token_hash       (no existe)
 *   - contenido de respuestas  (no existe)
 *   - CUALQUIER clave foranea hacia una tabla de la isla A
 *
 * survey_id existe deliberadamente como entero SUELTO, SIN clave foranea: es
 * necesario para investigar abuso dirigido a una encuesta concreta, pero no debe
 * crear un puente referencial entre las dos islas. Riesgo residual de correlacion
 * temporal declarado en ARCHITECTURE.md §7.3 y mitigado truncando occurred_at
 * al MINUTO (ver SecurityEvent::truncateToMinute()).
 *
 * user_id SI es clave foranea: apunta a administradores autenticados, que no son
 * participantes anonimos y cuya identidad ya es conocida por el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            // ARCHITECTURE §7.3: SIEMPRE truncado al minuto (segundos = 00).
            $table->timestamp('occurred_at');

            // rate_limited | auth_failed | invalid_public_id | foreign_option |
            // tampered_cookie | export_denied | ...
            $table->string('event_type', 50);

            // 45 = maximo de una IPv6 con sufijo IPv4 mapeado.
            $table->string('ip_address', 45)->nullable();

            $table->string('route', 255)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('reason', 100)->nullable();

            // Aleatorio por peticion. ARCHITECTURE §7.2.5: NO se guarda en la isla A.
            $table->uuid('correlation_id')->nullable();

            // Solo administradores. Nunca participantes.
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // ENTERO SUELTO A PROPOSITO. Sin ->constrained(): sin puente entre islas.
            $table->unsignedBigInteger('survey_id')->nullable();

            $table->timestamps();

            // Purga por retencion (SECURITY_LOG_RETENTION_DAYS, 30 por defecto).
            $table->index('occurred_at', 'security_events_occurred_index');
            // Investigacion de abuso: por tipo, por origen y por encuesta afectada.
            $table->index(['event_type', 'occurred_at'], 'security_events_type_occurred_index');
            $table->index(['ip_address', 'occurred_at'], 'security_events_ip_occurred_index');
            $table->index(['survey_id', 'occurred_at'], 'security_events_survey_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
