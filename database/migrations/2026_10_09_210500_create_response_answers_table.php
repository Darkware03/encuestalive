<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A. Respuesta a UNA pregunta dentro de un envio.
 *
 * Es la tabla que materializa el requisito duro "una misma sesion no puede responder
 * dos veces a la misma pregunta": UNIQUE(response_id, question_id). Como responses
 * tiene a su vez UNIQUE(participant_session_id), la composicion de ambas restricciones
 * garantiza a nivel de motor, no de aplicacion, que (sesion, pregunta) es unico.
 *
 * Unifica texto libre y selecciones en una sola tabla de cabecera para que la
 * restriccion sea UNA y aplique a los tres tipos de pregunta. ARCHITECTURE.md §7.1
 * dibujaba "response_selections / response_texts"; se consolida en
 * response_answers (+ response_selections) porque dos tablas hermanas no podrian
 * compartir esa unicidad. Desviacion documentada en DATABASE.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();

            // Solo para preguntas free_text. RN-13: ausencia != cadena vacia; una
            // pregunta opcional sin responder NO genera fila.
            $table->text('text_answer')->nullable();

            $table->timestamps();

            // ANTI DOBLE ENVIO DE LA MISMA SESION A LA MISMA PREGUNTA.
            $table->unique(['response_id', 'question_id'], 'response_answers_response_question_unique');

            // Agregados del dashboard: nº de envios que respondieron cada pregunta
            // (denominador de PRD §8.6) y listado de texto libre.
            $table->index('question_id', 'response_answers_question_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_answers');
    }
};
