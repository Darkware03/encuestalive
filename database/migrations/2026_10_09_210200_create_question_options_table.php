<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISLA A. Opciones de una pregunta de seleccion.
 *
 * RN-03 solo permite anadir/eliminar opciones mientras la encuesta esta en borrador,
 * por lo que el CASCADE no puede destruir selecciones ya registradas en la practica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 255);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['question_id', 'position'], 'question_options_question_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
