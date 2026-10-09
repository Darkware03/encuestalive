<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Autenticacion administrativa (ARCHITECTURE.md §5.1, API.md §3).
 *
 * ===================== LO QUE NO EXISTE, A PROPOSITO =====================
 * · SIN REGISTRO PUBLICO. No hay ruta `register`. Las cuentas se crean con
 *   `php artisan encuestalive:crear-admin`. Esto elimina de raiz toda una
 *   clase de abuso: no se puede crear una cuenta que no deberia existir si no
 *   hay forma de crear cuentas por HTTP.
 *
 * · SIN RECUPERACION POR CORREO. No hay SMTP en este entorno, y montar un
 *   flujo de recuperacion que no puede enviar correos seria una pantalla que
 *   miente. El restablecimiento se hace por consola, con acceso al servidor,
 *   que es un control mas fuerte que un enlace en un buzon.
 *
 * · UN SOLO ROL (D-05 PA-02). No hay permisos que gestionar en el MVP.
 * =========================================================================
 *
 * Hash bcrypt: Argon2id exigiria `ext-sodium`, que no esta en este build.
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            SecurityLog::authFailed($request);

            /*
             * Mensaje GENERICO. Nunca "ese correo no existe" ni "contrasena
             * incorrecta": distinguirlos convierte el login en un oraculo que
             * confirma que direcciones tienen cuenta (API.md §3).
             */
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        // Contra la fijacion de sesion: el identificador con el que la persona
        // llego no puede seguir siendo valido una vez autenticada.
        $request->session()->regenerate();

        SecurityLog::record('auth_succeeded', $request, 302, null, Auth::id());

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = Auth::id();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        SecurityLog::record('auth_logout', $request, 302, null, $userId);

        return redirect()->route('login');
    }
}
