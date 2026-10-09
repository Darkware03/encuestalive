<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A. Preguntas de una encuesta.
 *
 * ON DELETE CASCADE desde surveys: la eliminacion de una encuesta solo es posible
 * por comando de consola con respaldo verificado (RN-10.7 / brief §13, T-13);
 * cuando ocurre, debe llevarse el agregado completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();

            // single_choice | multiple_choice | free_text  (API.md §addQuestion)
            $table->string('type', 20);
            $table->string('prompt', 500);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('position');

            // Solo multiple_choice. RN-01.5: 1 <= min <= max <= nº opciones.
            $table->unsignedSmallInteger('min_selections')->nullable();
            $table->unsignedSmallInteger('max_selections')->nullable();

            // Solo free_text. RN-01.6: 0 <= min_length < max_length <= 2000.
            $table->unsignedSmallInteger('min_length')->nullable();
            $table->unsignedSmallInteger('max_length')->nullable();

            $table->timestamps();

            // Orden estable del formulario y del dashboard; ademas evita posiciones repetidas.
            $table->unique(['survey_id', 'position'], 'questions_survey_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
