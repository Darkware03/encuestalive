{{--
    P1 · Login administrativo (UX_UI_SPEC pantalla 1).

    Documento propio y no `layouts.admin`: ese layout trae navegacion lateral,
    migas y avatar, y ninguna de esas tres cosas debe existir antes de
    autenticarse. Una pantalla de login que ya muestra el menu del panel
    insinua que hay algo detras y da pistas de la estructura interna.

    SIN enlace de "crear cuenta" y SIN "he olvidado mi contrasena": no existe
    registro publico ni recuperacion por correo (ARCHITECTURE.md §5.1). Poner
    un enlace que no lleva a ningun sitio seria mentir en la interfaz.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Entrar · EncuestaLive</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-dvh flex-col bg-surface-canvas text-ink-900 antialiased">

    <x-skip-link target="contenido" />

    <main id="contenido" class="flex flex-1 items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">

            <div class="mb-8 text-center">
                <p class="text-2xl font-bold text-brand-700">EncuestaLive</p>
                <h1 class="mt-2 text-xl font-semibold text-ink-900">Entrar al panel</h1>
                <p class="mt-1 text-sm text-ink-600">Solo personal administrador.</p>
            </div>

            {{-- Resumen de errores: role="alert" y foco al aparecer (17.b).
                 El mensaje es GENERICO a proposito; nunca dice si el correo
                 existe. --}}
            @if ($errors->any())
                <x-error-summary
                    class="mb-6"
                    title="No pudimos entrar:"
                    :errors="collect($errors->keys())->map(fn ($k) => [
                        'label' => $k === 'email' ? 'Credenciales' : $k,
                        'message' => $errors->first($k),
                        'anchor' => $k,
                    ])->all()"
                />
            @endif

            <x-card>
                <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
                    {{-- CSRF: ninguna ruta de estado esta exenta (ARCH §5.3). --}}
                    @csrf

                    <x-input
                        id="email"
                        name="email"
                        type="email"
                        label="Correo electronico"
                        autocomplete="username"
                        required
                        :value="old('email')"
                        :error="$errors->first('email')"
                    />

                    <x-input
                        id="password"
                        name="password"
                        type="password"
                        label="Contrasena"
                        autocomplete="current-password"
                        required
                        :error="$errors->first('password')"
                    />

                    <label class="flex items-center gap-3 text-sm text-ink-700">
                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                            class="el-control-dot h-5 w-5 rounded-sm border border-border-strong"
                        >
                        Mantener la sesion abierta
                    </label>

                    <x-button type="submit" variant="primary" size="md" block>
                        Entrar
                    </x-button>
                </form>
            </x-card>

            <p class="mt-6 text-center text-xs text-ink-500">
                ¿Sin cuenta? Las cuentas se crean desde la consola del servidor
                con <code class="font-mono">encuestalive:crear-admin</code>.
            </p>

            <p class="mt-2 text-center text-xs text-ink-500">
                <a
                    href="{{ route('privacy') }}"
                    class="rounded-sm text-brand-700 underline underline-offset-2 hover:text-brand-800
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                >Aviso de privacidad</a>
            </p>
        </div>
    </main>
</body>
</html>
