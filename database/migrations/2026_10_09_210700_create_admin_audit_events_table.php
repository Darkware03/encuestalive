<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A (lado administrativo). Eventos administrativos del brief §8 y RF-13:
 * publicar, pausar, cerrar, archivar, editar (antes/despues de RN-03), exportar CSV.
 *
 * NO es un registro de seguridad: aqui NO hay IP. La IP vive exclusivamente en
 * security_events (isla B). Esta tabla responde "quien hizo que con la encuesta",
 * no "desde donde se conecto nadie".
 *
 * ON DELETE: survey_id SET NULL + copia del titulo, para que el rastro de auditoria
 * sobreviva a la eliminacion de la encuesta (brief §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('survey_id')->nullable()
                ->constrained('surveys')->nullOnDelete();
            $table->string('survey_title_snapshot', 150)->nullable();

            $table->string('action', 50);

            // json() es portable: TEXT en SQLite, JSON nativo en MySQL 8.
            // REGLA: nunca se consulta por dentro (no JSON_EXTRACT, no ->'$.x'),
            // solo se lee completo desde PHP. Asi la portabilidad esta garantizada.
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['survey_id', 'occurred_at'], 'admin_audit_survey_occurred_index');
            $table->index(['user_id', 'occurred_at'], 'admin_audit_user_occurred_index');
            $table->index(['action', 'occurred_at'], 'admin_audit_action_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
    }
};
