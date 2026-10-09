<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuario administrativo de DEMOSTRACION.
 *
 * D-05 (PA-02): un unico rol admin en el MVP, por lo que no hay tabla de roles.
 * Todo usuario de `users` es administrador.
 *
 * Idempotente: `updateOrCreate` por correo. Correr el seeder N veces deja
 * exactamente una fila.
 *
 * DATOS FICTICIOS. El dominio `.test` esta reservado (RFC 6761) y no resuelve en
 * Internet. La contrasena es de desarrollo y debe cambiarse antes de cualquier
 * despliegue real: el checklist de la Fase 8 debe recogerlo.
 */
class AdminUserSeeder extends Seeder
{
    public const EMAIL = 'admin@encuestalive.test';
    public const PASSWORD = 'password';

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Administradora de demostracion',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ],
        );
    }
}
