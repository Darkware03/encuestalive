<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-H1 (Fase 7) — ELIMINA EL SEGUNDO ALMACEN LATENTE DE DIRECCIONES IP.
 *
 * La migracion original de `sessions` (0001_01_01_000000) ya se ha corregido
 * para que las bases de datos NUEVAS nazcan sin `ip_address` ni `user_agent`.
 * Esta migracion hace lo propio con las que YA EXISTEN, porque una migracion
 * aplicada no se vuelve a ejecutar y sin esto el esquema real de un entorno
 * en marcha seguiria teniendo las columnas.
 *
 * Contexto: `sessions` es la tabla del framework. Con SESSION_DRIVER=database
 * guardaria la IP y el user-agent de CUALQUIER visitante, participantes
 * anonimos incluidos, lo que contradice el brief §5.2. D-11 lo mitigo fijando
 * SESSION_DRIVER=file, pero esa mitigacion dependia de una variable de
 * entorno; aqui pasa a ser una propiedad del esquema.
 *
 * SEGURO DE EJECUTAR: con el driver `file` la tabla esta vacia y nada la lee.
 * Las comprobaciones de existencia evitan fallar si ya se aplico a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        $drop = array_values(array_filter(
            ['ip_address', 'user_agent'],
            fn (string $column): bool => Schema::hasColumn('sessions', $column),
        ));

        if ($drop === []) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            // Reintroduce el segundo almacen de IP. Documentado a proposito.
            if (! Schema::hasColumn('sessions', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }

            if (! Schema::hasColumn('sessions', 'user_agent')) {
                $table->text('user_agent')->nullable();
            }
        });
    }
};
