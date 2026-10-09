<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text as promptText;

/**
 * Crea o restablece la cuenta administradora.
 *
 * ============ POR QUE ESTO ES UN COMANDO Y NO UNA PANTALLA ============
 * No hay SMTP en este entorno, asi que un flujo de "he olvidado mi
 * contrasena" por correo no podria enviar nada: seria una pantalla que
 * miente.
 *
 * Y la alternativa es mejor, no peor: restablecer desde la consola exige
 * acceso al servidor, que es un control de seguridad MAS FUERTE que un enlace
 * en un buzon de correo. Ademas elimina de raiz toda la superficie de ataque
 * de un formulario de recuperacion publico.
 * ======================================================================
 *
 *   php artisan encuestalive:crear-admin
 *   php artisan encuestalive:crear-admin --email=... --name=... --password=...
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'encuestalive:crear-admin
        {--email= : Correo de la cuenta}
        {--name= : Nombre visible}
        {--password= : Contrasena (si se omite, se pide de forma oculta)}';

    protected $description = 'Crea una cuenta administradora o restablece su contrasena.';

    public function handle(): int
    {
        $email = $this->option('email')
            ?: promptText('Correo', required: true);

        $existing = User::query()->where('email', $email)->first();

        $name = $this->option('name')
            ?: ($existing->name ?? promptText('Nombre', default: 'Administracion', required: true));

        /*
         * La contrasena se pide OCULTA cuando no llega por opcion. Pasarla en
         * --password es comodo para un script, pero queda en el historial del
         * shell y en la lista de procesos; por eso el modo interactivo es el
         * predeterminado y el otro es la excepcion explicita.
         */
        $password = $this->option('password')
            ?: promptPassword('Contrasena', required: true);

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            // 12 caracteres minimo. Es una cuenta sin recuperacion por correo
            // y con acceso a todas las respuestas: el coste de una contrasena
            // larga lo paga una sola persona una sola vez.
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                // El cast `hashed` del modelo ya aplicaria bcrypt, pero se
                // hace explicito: si alguien quitara el cast, esta linea
                // seguiria impidiendo guardar una contrasena en claro.
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        $this->components->info(sprintf(
            '%s la cuenta %s.',
            $existing ? 'Actualizada' : 'Creada',
            $user->email,
        ));

        // La contrasena NO se repite por pantalla ni se registra en ningun
        // log: quien la acaba de escribir ya la conoce.
        $this->components->warn('Guarda la contrasena: no hay recuperacion por correo.');

        return self::SUCCESS;
    }
}
