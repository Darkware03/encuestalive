<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A. Marca de una opcion dentro de la respuesta a una pregunta.
 *
 * single_choice   -> exactamente 1 fila por response_answer
 * multiple_choice -> entre min_selections y max_selections filas
 * free_text       -> 0 filas
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_option_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Una misma opcion no puede marcarse dos veces en la misma respuesta
            // (API.md §answers[].option_ids: "sin duplicados", ahora tambien en BD).
            $table->unique(
                ['response_answer_id', 'question_option_id'],
                'response_selections_answer_option_unique'
            );

            // ADR-0003 / ARCHITECTURE §14 (encargo explicito): es el indice que sostiene
            // el agregado principal del dashboard,
            //   SELECT question_option_id, COUNT(*) ... GROUP BY question_option_id
            $table->index('question_option_id', 'response_selections_option_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_selections');
    }
};
