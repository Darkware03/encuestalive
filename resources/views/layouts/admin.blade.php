@props([
    /* Título de la pantalla. Se usa en <title> y en la barra superior. */
    'title' => 'Panel',
    /* Clave del destino activo: panel | encuestas | historial | cuenta */
    'active' => 'panel',
    /* [['label' => 'Encuestas', 'href' => '/admin/encuestas'], ['label' => 'Jornada TI']]
       El ÚLTIMO elemento es texto, no enlace, y lleva aria-current="page" (§3.2). */
    'breadcrumbs' => [],
    'connection' => 'online',
    'connectionDetail' => null,
])

{{--
    layouts/admin — zona administrativa (UX_UI_SPEC §3.1, §3.2, §3.4, §10).

    · Contenedor hasta 80rem: tablas y dashboards necesitan anchura.
    · Navegación LATERAL PERMANENTE desde lg (1024px); por debajo, cajón
      desde ☰ con scrim, Esc y foco atrapado.
    · Cuatro destinos. Nada más. Si el producto crece, se agrupa.
    · Barra superior fija de 56px en móvil: ☰ · título · conexión · avatar.
    · Landmarks reales: header / nav / main / footer, un solo <main>.

    Las rutas de los enlaces son las del mapa de navegación de §3.2 y hoy
    NO EXISTEN todavía: routes/ está fuera de mi ámbito de escritura en esta
    fase. Se dejan como href literales para que el día que se registren las
    rutas baste con sustituirlos por route(). Ver el informe de Fase 5a.
--}}

@php
    $nav = [
        ['key' => 'panel', 'label' => 'Panel', 'href' => '/admin', 'icon' => 'home'],
        ['key' => 'encuestas', 'label' => 'Encuestas', 'href' => '/admin/encuestas', 'icon' => 'clipboard'],
        ['key' => 'historial', 'label' => 'Historial', 'href' => '/admin/historial', 'icon' => 'clock'],
    ];

    $navSecondary = [
        ['key' => 'privacidad', 'label' => 'Privacidad', 'href' => '/privacidad', 'icon' => 'lock-closed'],
        ['key' => 'cuenta', 'label' => 'Cuenta', 'href' => '/admin/cuenta', 'icon' => 'user-circle'],
    ];

    $itemBase = 'flex items-center gap-3 rounded-md px-3 py-2.5 text-base font-medium '
        . 'min-h-11 transition-colors motion-safe:duration-[var(--duration-fast)] '
        . 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700';
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- §10 regla 4: sin maximum-scale ni user-scalable=no. Bloquear el zoom
         incumple WCAG 1.4.4. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · EncuestaLive</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-surface-canvas text-ink-900 antialiased">

    <x-skip-link target="contenido" />

    <div class="lg:flex" data-modal-background>

        {{-- ============================================= NAVEGACIÓN LATERAL --}}
        {{-- Desde lg es permanente. Por debajo es un cajón superpuesto. --}}
        <div
            class="group/drawer fixed inset-0 hidden data-[open=true]:block
                   lg:static lg:block lg:w-64 lg:shrink-0"
            style="z-index: var(--z-overlay)"
            data-nav-drawer
            data-open="false"
        >
            {{-- Scrim: solo existe en el cajón móvil --}}
            <div class="el-scrim absolute inset-0 lg:hidden" data-nav-close aria-hidden="true"></div>

            <nav
                class="relative flex h-full w-64 flex-col border-r border-ink-200 bg-white px-3 py-4"
                aria-label="Navegación principal"
            >
                <div class="mb-6 flex items-center justify-between px-3">
                    <span class="text-xl font-bold text-brand-700">EncuestaLive</span>

                    <button
                        type="button"
                        class="-mr-2 inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-700
                               hover:bg-ink-100 focus-visible:outline-2 focus-visible:outline-offset-2
                               focus-visible:outline-brand-700 lg:hidden"
                        data-nav-close
                        aria-label="Cerrar navegación"
                    >
                        <x-icon name="x-mark" :size="20" />
                    </button>
                </div>

                <ul class="flex flex-col gap-1">
                    @foreach ($nav as $item)
                        <li>
                            <a
                                href="{{ $item['href'] }}"
                                class="{{ $itemBase }} {{ $active === $item['key']
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-700 hover:bg-ink-100' }}"
                                @if ($active === $item['key']) aria-current="page" @endif
                            >
                                <x-icon :name="$item['icon']" :size="20" />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <hr class="my-4 border-ink-200">

                <ul class="flex flex-col gap-1">
                    @foreach ($navSecondary as $item)
                        <li>
                            <a
                                href="{{ $item['href'] }}"
                                class="{{ $itemBase }} {{ $active === $item['key']
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-700 hover:bg-ink-100' }}"
                                @if ($active === $item['key']) aria-current="page" @endif
                            >
                                <x-icon :name="$item['icon']" :size="20" />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>

        {{-- ========================================================= CUERPO --}}
        <div class="flex min-w-0 flex-1 flex-col">

            {{-- Barra superior fija de 56px (§3.4) --}}
            <header
                class="sticky top-0 flex h-14 items-center gap-3 border-b border-ink-200 bg-white px-4"
                style="z-index: var(--z-sticky)"
            >
                <button
                    type="button"
                    class="-ml-2 inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-700
                           hover:bg-ink-100 focus-visible:outline-2 focus-visible:outline-offset-2
                           focus-visible:outline-brand-700 lg:hidden"
                    data-nav-toggle
                    aria-expanded="false"
                    aria-controls="nav-principal"
                    aria-label="Abrir navegación"
                >
                    <x-icon name="bars-3" :size="24" />
                </button>

                <p class="min-w-0 flex-1 truncate text-base font-semibold text-ink-900">{{ $title }}</p>

                {{-- Región de conexión SEPARADA de la región de datos (§9.4) --}}
                <x-connection-indicator :state="$connection" :detail="$connectionDetail" class="hidden sm:inline-flex" />

                <span class="inline-flex h-11 w-11 items-center justify-center rounded-full text-ink-700">
                    <x-icon name="user-circle" :size="24" />
                </span>
            </header>

            {{-- Banda de estado (desconexión / reconexión). EMPUJA el contenido,
                 nunca se superpone: taparía datos (18.a). --}}
            @isset($statusBanner)
                {{ $statusBanner }}
            @endisset

            <main id="contenido" class="min-w-0 flex-1 px-4 py-6 md:px-6 md:py-8 lg:px-8">
                <div class="mx-auto w-full max-w-admin">

                    @if (count($breadcrumbs))
                        <nav aria-label="Ruta de navegación" class="mb-4">
                            <ol class="flex flex-wrap items-center gap-1 text-sm text-ink-600">
                                @foreach ($breadcrumbs as $i => $crumb)
                                    <li class="flex items-center gap-1">
                                        @if ($i > 0)
                                            <span class="text-ink-500" aria-hidden="true">/</span>
                                        @endif

                                        @if (! empty($crumb['href']) && $i < count($breadcrumbs) - 1)
                                            <a
                                                href="{{ $crumb['href'] }}"
                                                class="rounded-sm text-brand-700 underline underline-offset-2 hover:text-brand-800
                                                       focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                                            >{{ $crumb['label'] }}</a>
                                        @else
                                            <span class="font-medium text-ink-900" aria-current="page">{{ $crumb['label'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </nav>
                    @endif

                    @isset($heading)
                        {{ $heading }}
                    @else
                        <h1 class="text-3xl font-bold text-ink-900">{{ $title }}</h1>
                    @endisset

                    <div class="mt-6">
                        {{ $slot }}
                    </div>
                </div>
            </main>

            <footer class="border-t border-ink-200 px-4 py-6 text-sm text-ink-500 md:px-6 lg:px-8">
                <div class="mx-auto flex w-full max-w-admin flex-wrap items-center justify-between gap-2">
                    <span>EncuestaLive</span>
                    <a
                        href="/privacidad"
                        class="rounded-sm text-brand-700 underline underline-offset-2 hover:text-brand-800
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                    >Aviso de privacidad</a>
                </div>
            </footer>
        </div>
    </div>

    {{-- Barra de acción inferior fija cuando hay una acción primaria clara
         (§3.4). Por encima de lg se usa la acción en línea de la pantalla. --}}
    @isset($actionBar)
        <div
            class="el-action-bar sticky bottom-0 border-t border-ink-200 bg-white px-4 pt-4 lg:hidden"
            style="z-index: var(--z-sticky)"
        >
            {{ $actionBar }}
        </div>
    @endisset

    {{ $modals ?? '' }}

    <x-toast-region>
        {{ $toasts ?? '' }}
    </x-toast-region>
</body>
</html>
