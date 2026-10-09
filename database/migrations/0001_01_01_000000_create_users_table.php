<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        /*
         * F-H1 (Fase 7) — SIN ip_address NI user_agent. NO LAS VUELVAS A ANADIR.
         *
         * Esta es la tabla de sesiones DEL FRAMEWORK, no del modelo de
         * EncuestaLive. De fabrica trae `ip_address` y `user_agent`, y con
         * SESSION_DRIVER=database guardaria la IP y el navegador de CUALQUIER
         * visitante, participantes anonimos incluidos. Eso es un SEGUNDO
         * almacen de direcciones IP, que contradice el brief §5.2 y la
         * arquitectura de dos islas.
         *
         * D-11 ya lo resolvio fijando SESSION_DRIVER=file, y hoy no se escribe
         * nada aqui. Pero esa proteccion dependia de UNA VARIABLE DE ENTORNO:
         * un operador que pusiera `database` en el despliegue —que ademas es
         * el valor por defecto de config/session.php— reintroducia el segundo
         * almacen en silencio, sin que ninguna prueba se enterase.
         *
         * Al quitar las columnas, la propiedad deja de depender de la
         * configuracion y pasa a ser estructural: el dato no se puede guardar
         * porque no hay donde. Es el mismo criterio, acertado, que el diseno
         * aplico a la isla A.
         *
         * Para que el fallo no sea un error SQL criptico, AppServiceProvider
         * comprueba el driver en arranque y explica D-11 con todas las letras.
         */
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
