<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de `php artisan db:seed` y de `migrate:fresh --seed`.
 *
 * TODOS los seeders de EncuestaLive son IDEMPOTENTES: correrlos varias veces
 * sobre la misma base deja exactamente el mismo estado (ver cada clase).
 * Siembran unicamente DATOS FICTICIOS.
 *
 * NO se usa el trait WithoutModelEvents a proposito: SecurityEvent depende de su
 * evento `saving` para truncar occurred_at al minuto (ARCHITECTURE §7.3).
 * Silenciar los eventos de modelo romperia esa mitigacion durante el sembrado.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            DemoSurveySeeder::class,
        ]);
    }
}
