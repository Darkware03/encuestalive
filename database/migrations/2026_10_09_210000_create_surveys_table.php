<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A — DATOS DE PARTICIPACION (ARCHITECTURE.md §7.1).
 *
 * Tabla raiz del agregado "encuesta".
 *
 * Portabilidad (ADR-0003): solo tipos del constructor de esquemas de Laravel,
 * sin enum de BD, sin funciones de motor, strings con longitud explicita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();

            // ADR-0006: 16 caracteres Base32 Crockford, 80 bits, nullable
            // (los borradores no tienen URL publica), unico, inmutable.
            $table->string('public_id', 16)->nullable()->unique();

            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();

            // PRD §7 / ADR-0003: string + constante de clase, nunca enum de BD.
            // draft | scheduled | active | paused | closed | archived
            $table->string('status', 20)->default('draft');

            // RN-03: "duplicar como nueva version" encadena versiones.
            $table->unsignedInteger('version_number')->default(1);
            $table->foreignId('parent_survey_id')->nullable()
                ->constrained('surveys')->nullOnDelete();

            // Propietario administrativo. PA-02: un unico rol admin en el MVP.
            // nullOnDelete: borrar una cuenta jamas debe borrar encuestas ni respuestas.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Ventana temporal (RN-11: estado efectivo calculado en cada peticion).
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();

            // RN-02.5 / RN-09.5: instantanea de resumen persistida AL CERRAR, para que
            // el historico siga siendo estable despues de purgar metadatos de sesion.
            $table->unsignedInteger('snapshot_sessions_started')->nullable();
            $table->unsignedInteger('snapshot_participated')->nullable();
            $table->unsignedInteger('snapshot_completed')->nullable();
            // D-07: completadas / sesiones_iniciadas * 100, una cifra decimal.
            $table->decimal('snapshot_response_rate', 5, 1)->nullable();

            $table->timestamps();

            // Listado administrativo por defecto: filtra por estado y ordena por creacion.
            $table->index(['status', 'created_at'], 'surveys_status_created_at_index');
            // RN-11: barrido perezoso/planificado de encuestas vencidas.
            $table->index(['status', 'ends_at'], 'surveys_status_ends_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
