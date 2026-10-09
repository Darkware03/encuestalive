{{--
    gallery — banco de pruebas visual del sistema de diseño (Fase 5a).

    Renderiza TODOS los componentes en TODOS los estados que la especificación
    les exige. Es a la vez mi verificación y el material que usará darkware en
    la Fase 9.

    HONESTIDAD SOBRE LOS DATOS: todas las cifras, nombres de encuesta y
    porcentajes de esta página son de MUESTRA, escritos a mano para poder ver
    la forma del componente. No proceden de la base de datos: `database-engineer`
    y `backend-engineer` trabajan en paralelo y aún no hay modelo ni StatsService.
    Nada aquí debe leerse como un dato real del producto.

    NOTA SOBRE LOS ESTADOS :hover, :focus-visible y :active
    Son estados del navegador y no se pueden «congelar» desde el servidor sin
    duplicar las clases. En esta galería se muestran:
      · su definición exacta (qué clases los producen),
      · una réplica estática con las MISMAS clases aplicadas en reposo,
        marcada como «simulada», para poder compararla en una captura.
    La comprobación del estado real con el puntero y con el teclado es de la
    Fase 6 (qa-engineer) y la Fase 9. No la declaro verificada.
--}}

@php
    $toc = [
        'foco' => 'Foco',
        'botones' => 'Botones',
        'campos' => 'Campos de formulario',
        'opciones' => 'Radio y checkbox',
        'badges' => 'Badges de estado',
        'tarjetas' => 'Tarjetas y KPI',
        'tabla' => 'Tabla',
        'conexion' => 'Indicador de conexión',
        'progreso' => 'Barra de progreso',
        'resultados' => 'Barras de resultado',
        'bandas' => 'Bandas de estado',
        'toasts' => 'Toasts',
        'modal' => 'Modal',
        'skeleton' => 'Skeletons',
        'vacios' => 'Estados vacíos',
        'tipografia' => 'Tipografía y color',
    ];
@endphp

{{-- ====================================================== índice --}}
<nav aria-label="Índice de la galería" class="mb-10 rounded-lg border border-ink-200 bg-white p-4">
    <h2 class="mb-3 text-sm font-semibold text-ink-700">Contenido</h2>
    {{--
        DEFECTO CORREGIDO TRAS VER EL RENDER (Fase 5a): la comprobación
        automática de objetivos táctiles marcó estos enlaces como 17px de alto,
        por debajo de los 24px de WCAG 2.2 §2.5.8. No se acogen a la excepción
        «inline» porque no están dentro de una frase: son una lista de destinos.
        Ahora cada uno ocupa 44px de alto, el estándar del proyecto (§9.5).
    --}}
    <ul class="flex flex-wrap gap-x-2 gap-y-1">
        @foreach ($toc as $anchor => $label)
            <li>
                <a
                    href="#{{ $anchor }}"
                    class="inline-flex min-h-11 items-center rounded-md px-2 text-sm text-brand-700 underline underline-offset-2
                           hover:bg-ink-100 hover:text-brand-800
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
                >{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>

{{-- ====================================================== 1. FOCO --}}
<x-gallery-section
    id="foco"
    title="Regla de foco transversal"
    spec="§6.0"
    note="Un único patrón en todo el producto, con :focus-visible (nunca :focus, que dispararía el anillo también al hacer clic con ratón). Anillo de 2px con 2px de separación, contraste 9,47:1 sobre blanco. WCAG 2.2 §2.4.13."
>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-gallery-demo state="definición">
            <pre class="overflow-x-auto font-mono text-xs text-ink-800">:focus-visible {
  outline: 2px solid #1B3AA8;
  outline-offset: 2px;
  border-radius: inherit;
}</pre>
        </x-gallery-demo>

        <x-gallery-demo state="simulado sobre un botón" note="Réplica estática con outline aplicado en reposo. El estado real se dispara al tabular.">
            <span class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-brand-700 px-5 text-base font-semibold text-white outline-2 outline-offset-2 outline-brand-700">
                Publicar encuesta
            </span>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 2. BOTONES --}}
<x-gallery-section
    id="botones"
    title="Botones"
    spec="§6.1"
    note="Cinco variantes. Altura 44px en admin y 48px en el participante. El texto es un verbo en infinitivo. En «loading» el ancho se congela para que el botón no se encoja al cambiar a gerundio. El estado «error» NO APLICA a un botón: el error vive en el formulario o en un toast."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="variantes · default">
            <x-button variant="primary">Publicar encuesta</x-button>
            <x-button variant="secondary">Guardar borrador</x-button>
            <x-button variant="outline">Duplicar</x-button>
            <x-button variant="ghost">Cancelar</x-button>
            <x-button variant="danger">Cerrar encuesta</x-button>
        </x-gallery-demo>

        <x-gallery-demo state="hover (simulado)" note="Clases hover: aplicadas en reposo para poder capturarlas.">
            <span class="inline-flex h-11 items-center rounded-md bg-brand-800 px-5 text-base font-semibold text-white">Publicar encuesta</span>
            <span class="inline-flex h-11 items-center rounded-md bg-brand-100 px-5 text-base font-semibold text-brand-700">Guardar borrador</span>
            <span class="inline-flex h-11 items-center rounded-md border border-ink-600 bg-ink-50 px-5 text-base font-semibold text-ink-800">Duplicar</span>
            <span class="inline-flex h-11 items-center rounded-md bg-ink-100 px-5 text-base font-semibold text-ink-700">Cancelar</span>
            <span class="inline-flex h-11 items-center rounded-md bg-danger-600 px-5 text-base font-semibold text-white">Cerrar encuesta</span>
        </x-gallery-demo>

        <x-gallery-demo state="active / pressed (simulado)">
            <span class="inline-flex h-11 items-center rounded-md bg-brand-900 px-5 text-base font-semibold text-white">Publicar encuesta</span>
            <span class="inline-flex h-11 items-center rounded-md bg-brand-200 px-5 text-base font-semibold text-brand-700">Guardar borrador</span>
            <span class="inline-flex h-11 items-center rounded-md border border-border-strong bg-ink-100 px-5 text-base font-semibold text-ink-800">Duplicar</span>
        </x-gallery-demo>

        <x-gallery-demo state="focus-visible (simulado)">
            <span class="inline-flex h-11 items-center rounded-md bg-brand-700 px-5 text-base font-semibold text-white outline-2 outline-offset-2 outline-brand-700">Publicar encuesta</span>
            <span class="inline-flex h-11 items-center rounded-md border border-border-strong bg-white px-5 text-base font-semibold text-ink-800 outline-2 outline-offset-2 outline-brand-700">Duplicar</span>
        </x-gallery-demo>

        <x-gallery-demo state="disabled" note="#EDF1F7 sobre #626A79 — 4,80:1. Cursor not-allowed, nunca pointer.">
            <x-button variant="primary" disabled>Publicar encuesta</x-button>
            <x-button variant="secondary" disabled>Guardar borrador</x-button>
            <x-button variant="danger" disabled>Cerrar encuesta</x-button>
        </x-gallery-demo>

        <x-gallery-demo state="loading" note="Gerundio del mismo verbo + spinner + aria-busy + disabled. El ancho se congela por JS al medir el reposo.">
            <x-button variant="primary" loading loading-label="Publicando…">Publicar encuesta</x-button>
            <x-button variant="secondary" loading loading-label="Guardando…">Guardar</x-button>
            <x-button variant="outline" loading loading-label="Generando…">Exportar</x-button>
        </x-gallery-demo>

        <x-gallery-demo state="tamaños · sm 32 / md 44 / lg 48">
            <x-button variant="outline" size="sm">Acción menor</x-button>
            <x-button variant="primary" size="md">Acción admin</x-button>
            <x-button variant="primary" size="lg">Acción del participante</x-button>
        </x-gallery-demo>

        <x-gallery-demo state="con icono · solo icono (44×44)">
            <x-button variant="primary" icon="plus">Nueva encuesta</x-button>
            <x-button variant="outline" icon="arrow-down-tray">Exportar CSV</x-button>
            <x-button variant="ghost" icon-only icon="pencil-square" label="Editar encuesta" />
            <x-button variant="ghost" icon-only icon="trash" label="Eliminar encuesta" />
        </x-gallery-demo>

        <x-gallery-demo state="block · ancho completo en móvil" class="lg:col-span-2">
            <div class="w-full max-w-participant">
                <x-button variant="primary" size="lg" block>Enviar respuestas</x-button>
            </div>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 3. CAMPOS --}}
<x-gallery-section
    id="campos"
    title="Campos de formulario"
    spec="§6.2 · §6.5"
    note="Etiqueta visible SIEMPRE, asociada por for/id. Nunca el placeholder como etiqueta. Borde border-strong (#78849A, 3,77:1): border-subtle no vale como único límite de un control. Tamaño de fuente 16px mínimo para que iOS no haga zoom al enfocar."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="input · default + hint">
            <x-input
                id="g-titulo"
                label="Título de la encuesta"
                placeholder="Satisfacción Jornada TI"
                hint="Lo verán los participantes al escanear el QR."
                required
            />
        </x-gallery-demo>

        <x-gallery-demo state="input · error" note="Borde danger-700 de 2px y fondo BLANCO: el texto debe seguir legible. aria-invalid + aria-describedby.">
            <x-input
                id="g-titulo-error"
                label="Título de la encuesta"
                value=""
                error="Escribe un título para la encuesta."
                required
            />
        </x-gallery-demo>

        <x-gallery-demo state="input · disabled">
            <x-input id="g-codigo" label="Código público" value="JTI-2026" disabled hint="Se genera al publicar." />
        </x-gallery-demo>

        <x-gallery-demo state="input · loading (validación remota)">
            <x-input id="g-slug" label="Identificador público" value="jornada-ti" loading hint="Comprobando disponibilidad…" />
        </x-gallery-demo>

        <x-gallery-demo state="input · focus-visible (simulado)">
            <div class="w-full">
                <span class="mb-2 block text-sm font-semibold text-ink-700">Título de la encuesta</span>
                <span class="flex h-11 w-full items-center rounded-md border border-brand-700 bg-white px-4 text-base text-ink-900 outline-2 outline-offset-2 outline-brand-700">Jornada TI 2026</span>
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="input · con icono (buscador)">
            <x-input id="g-buscar" label="Buscar encuestas" placeholder="Nombre o código" leading-icon="magnifying-glass" />
        </x-gallery-demo>

        <x-gallery-demo state="select · default / error / disabled" class="lg:col-span-2">
            <div class="grid w-full gap-4 md:grid-cols-3">
                <x-select
                    id="g-tipo"
                    label="Tipo de pregunta"
                    :options="['single' => 'Selección única', 'multi' => 'Selección múltiple', 'text' => 'Respuesta libre']"
                    selected="single"
                />
                <x-select
                    id="g-tipo-err"
                    label="Tipo de pregunta"
                    :options="['single' => 'Selección única']"
                    placeholder="Elige un tipo"
                    error="Elige el tipo de pregunta."
                />
                <x-select
                    id="g-tipo-dis"
                    label="Tipo de pregunta"
                    :options="['single' => 'Selección única']"
                    selected="single"
                    disabled
                />
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="textarea · contador 0–89 % (ink-500)">
            <x-textarea
                id="g-ta-ok"
                label="¿Qué mejorarías?"
                value="La sala se quedó pequeña a media mañana."
                :maxlength="500"
                hint="Opcional."
            />
        </x-gallery-demo>

        <x-gallery-demo state="textarea · contador 90–99 % (warning-800)" note="A partir del 90 % cambia de color y anuncia «Quedan N caracteres», como máximo una vez por segundo.">
            <x-textarea
                id="g-ta-near"
                label="¿Qué mejorarías?"
                :maxlength="120"
                :demo-length="112"
                value="La sala se quedó pequeña a media mañana y el audio del segundo ponente no se oyó bien desde el fondo de la sala."
            />
        </x-gallery-demo>

        {{--
            DEFECTO CORREGIDO TRAS VER EL RENDER (Fase 5a): esta celda decía
            «contador 100 %» pero mostraba 57/60. El texto de muestra tenía 57
            caracteres y el JS recalcula el contador al cargar, así que pintaba
            el umbral de 90-99 %, no el de 100 %. La galería estaba enseñando
            un estado distinto del que anunciaba. Ahora el texto mide
            exactamente los 57 caracteres del maxlength.
        --}}
        <x-gallery-demo state="textarea · contador 100 % (danger-700)" note="El texto se TRUNCA en el límite; el contador no se pone en rojo para castigar, sino para avisar de que ya no cabe más.">
            <x-textarea
                id="g-ta-full"
                label="¿Qué mejorarías?"
                :maxlength="57"
                value="La sala se quedó pequeña y el audio no se oía bien atrás."
            />
        </x-gallery-demo>

        <x-gallery-demo state="textarea · error / disabled">
            <div class="grid w-full gap-4">
                <x-textarea id="g-ta-err" label="Instrucciones" :maxlength="200" error="Las instrucciones no pueden quedar vacías si activas la portada." />
                <x-textarea id="g-ta-dis" label="Instrucciones" :maxlength="200" value="Responde con sinceridad." disabled />
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="resumen de errores (>3 campos)" spec="17.b" class="lg:col-span-2">
            <x-error-summary
                class="w-full"
                :errors="[
                    ['label' => 'Título', 'message' => 'escribe un título para la encuesta.', 'anchor' => 'g-titulo-error'],
                    ['label' => 'Pregunta 1', 'message' => 'añade al menos 2 opciones.', 'anchor' => 'g-opciones'],
                    ['label' => 'Pregunta 3', 'message' => 'el máximo debe ser mayor que el mínimo.', 'anchor' => 'g-limites'],
                ]"
            />
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 4. OPCIONES --}}
<x-gallery-section
    id="opciones"
    title="Radio y checkbox"
    spec="§6.3 · §6.4"
    note="Elementos nativos con appearance-none y estilo propio: el nativo ya aporta grupo, flechas y anuncio correcto. Toda la fila es el objetivo táctil (48px de alto, ancho completo). El error se muestra en el fieldset, no en cada opción."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="radio · default, checked, hover, disabled">
            <x-choice-group
                id="g-radio"
                legend="¿Cómo valoras la jornada?"
                hint="Obligatoria · elige una opción"
                required
                class="w-full"
            >
                <x-choice-option name="g-valoracion" value="excelente" checked>Excelente</x-choice-option>
                <x-choice-option name="g-valoracion" value="buena">Buena</x-choice-option>
                <x-choice-option name="g-valoracion" value="regular" description="Fila con texto de apoyo">Regular</x-choice-option>
                <x-choice-option name="g-valoracion" value="mala" disabled>Mala (opción retirada)</x-choice-option>
            </x-choice-group>
        </x-gallery-demo>

        <x-gallery-demo state="radio · error en el fieldset" note="Borde izquierdo danger-700 de 3px + mensaje bajo la leyenda. Ninguna fila se tiñe de rojo.">
            <x-choice-group
                id="g-radio-err"
                legend="¿Cómo valoras la jornada?"
                error="Elige una opción para continuar."
                required
                class="w-full"
            >
                <x-choice-option name="g-valoracion-err" value="excelente">Excelente</x-choice-option>
                <x-choice-option name="g-valoracion-err" value="buena">Buena</x-choice-option>
            </x-choice-group>
        </x-gallery-demo>

        <x-gallery-demo state="radio · focus-visible sobre la FILA (simulado)" note="El anillo rodea la fila completa, no el círculo de 20px.">
            <label class="el-option-row target-comfortable relative flex w-full items-center gap-3 rounded-lg border-2 border-ink-200 bg-white px-4 py-3 text-base outline-2 outline-offset-2 outline-brand-700">
                <input class="el-control-dot" type="radio" name="g-foco-demo" aria-label="Excelente" />
                <span class="min-w-0 flex-1">Excelente</span>
            </label>
        </x-gallery-demo>

        <x-gallery-demo state="checkbox · límites mín/máx" spec="§6.4" note="Al alcanzar el máximo, las NO seleccionadas pasan a disabled y se anuncia por aria-live. Prevenir el error es mejor que informarlo (WCAG 3.3.4).">
            <x-choice-group
                id="g-limites"
                legend="¿Qué temas te interesaron más?"
                :min="1"
                :max="3"
                :count="3"
                class="w-full"
            >
                <x-choice-option type="checkbox" name="g-temas[]" value="a" checked>Accesibilidad</x-choice-option>
                <x-choice-option type="checkbox" name="g-temas[]" value="b" checked>Tiempo real</x-choice-option>
                <x-choice-option type="checkbox" name="g-temas[]" value="c" checked>Seguridad</x-choice-option>
                <x-choice-option type="checkbox" name="g-temas[]" value="d" disabled>Despliegue</x-choice-option>
                <x-choice-option type="checkbox" name="g-temas[]" value="e" disabled>Base de datos</x-choice-option>
            </x-choice-group>
        </x-gallery-demo>

        <x-gallery-demo state="checkbox · grupo en envío (aria-busy)" class="lg:col-span-2">
            <x-choice-group id="g-busy" legend="Enviando tus respuestas…" loading class="w-full">
                <x-choice-option type="checkbox" name="g-busy-op[]" value="a" checked>Accesibilidad</x-choice-option>
                <x-choice-option type="checkbox" name="g-busy-op[]" value="b">Tiempo real</x-choice-option>
            </x-choice-group>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 5. BADGES --}}
<x-gallery-section
    id="badges"
    title="Badge de estado de encuesta"
    spec="§6.7"
    note="Los seis estados. SIEMPRE incluyen la palabra: nunca un punto de color a secas (WCAG 1.4.1). Los estados interactivos no aplican: un badge es informativo, no accionable."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="los 6 estados · sobre superficie blanca">
            <x-status-badge status="draft" />
            <x-status-badge status="scheduled" />
            <x-status-badge status="active" />
            <x-status-badge status="paused" />
            <x-status-badge status="closed" />
            <x-status-badge status="archived" />
        </x-gallery-demo>

        <x-gallery-demo state="los 6 estados · sobre canvas ink-50" surface="canvas">
            <x-status-badge status="draft" />
            <x-status-badge status="scheduled" />
            <x-status-badge status="active" />
            <x-status-badge status="paused" />
            <x-status-badge status="closed" />
            <x-status-badge status="archived" />
        </x-gallery-demo>

        <x-gallery-demo state="badge genérico (no es el estado de la encuesta)" class="lg:col-span-2">
            <x-badge tone="neutral">Obligatoria</x-badge>
            <x-badge tone="brand" icon="lock-closed">Anónima</x-badge>
            <x-badge tone="info">Selección múltiple</x-badge>
            <x-badge tone="success" icon="check">Guardado</x-badge>
            <x-badge tone="warning">Sin fecha de cierre</x-badge>
            <x-badge tone="danger">Límite alcanzado</x-badge>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 6. TARJETAS --}}
<x-gallery-section
    id="tarjetas"
    title="Tarjetas y KPI"
    spec="§6.6"
    note="Una tarjeta solo existe si AGRUPA contenido relacionado y lo SEPARA de otro grupo. La elevación por defecto es un borde de 1px, no una sombra. Las cifras son de muestra: en la Fase 5b vienen de StatsService, única fuente autorizada (D-07)."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="card · default">
            <x-card class="w-full" title="Satisfacción Jornada TI" subtitle="Publicada hace 5 h">
                <p class="text-sm text-ink-600">94 respuestas · cierra en 42 min</p>
            </x-card>
        </x-gallery-demo>

        <x-gallery-demo state="card · clicable (hover / focus reales)">
            <x-card class="w-full" href="#tarjetas" title="Consulta presupuesto 2027" subtitle="318 respuestas">
                <p class="text-sm text-ink-600">Pasa el ratón o tabula hasta aquí para ver hover y foco.</p>
            </x-card>
        </x-gallery-demo>

        <x-gallery-demo state="card · error" note="Borde danger-700 + banda interna. El resto del contenido queda intacto.">
            <x-card class="w-full" title="Clima laboral" error="No pudimos cargar el recuento. Los datos mostrados son de hace 3 min.">
                <p class="text-sm text-ink-600">412 respuestas</p>
            </x-card>
        </x-gallery-demo>

        <x-gallery-demo state="card · disabled">
            <x-card class="w-full" title="Encuesta archivada" subtitle="No admite cambios" disabled>
                <p class="text-sm text-ink-600">El contenido queda atenuado y sin eventos de puntero.</p>
            </x-card>
        </x-gallery-demo>

        <x-gallery-demo state="KPI · 1 / 2 / 4 columnas según ancho" class="lg:col-span-2">
            <div class="grid w-full grid-cols-1 gap-4 min-[400px]:grid-cols-2 lg:grid-cols-4">
                <x-stat label="Activas" value="3" note="de 12" />
                <x-stat label="Sesiones conectadas" value="128" note="ahora" />
                <x-stat label="Respuestas hoy" value="412" note="últ. 24 h" />
                <x-stat label="Tasa de respuesta" value="74 %" note="media" />
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="KPI · datos obsoletos (sin conexión)" class="lg:col-span-2" note="18.a: al perder la conexión los contadores NO se vacían ni se ponen a cero. Se mantiene el último valor conocido, atenuado, declarando la antigüedad. Poner un 0 sería comunicar un dato falso.">
            <div class="grid w-full grid-cols-1 gap-4 min-[400px]:grid-cols-2 lg:grid-cols-4">
                <x-stat label="Activas" value="3" stale stale-note="hace 3 min" />
                <x-stat label="Sesiones conectadas" value="128" stale stale-note="hace 3 min" />
                <x-stat label="Respuestas hoy" value="412" stale stale-note="hace 3 min" />
                <x-stat label="Tasa de respuesta" value="74 %" stale stale-note="hace 3 min" />
            </div>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 7. TABLA --}}
<x-gallery-section
    id="tabla"
    title="Tabla"
    spec="§6.8"
    note="<table> real con <caption> oculto, <th scope> y aria-sort. Sin zebra striping. REGLA DURA: nada de scroll horizontal — por debajo de md la tabla se transforma en lista de tarjetas. Estrecha el navegador para verlo."
>
    <div class="grid gap-6">
        <x-gallery-demo state="default · hover · fila seleccionada · fila disabled">
            <x-table
                class="w-full"
                caption="Encuestas del organizador, ordenadas por fecha de creación descendente"
                :columns="[
                    ['label' => 'Encuesta', 'sort' => 'descending'],
                    ['label' => 'Estado'],
                    ['label' => 'Respuestas', 'numeric' => true],
                    ['label' => 'Acciones'],
                ]"
            >
                <x-table-row>
                    <x-table-cell header>Satisfacción Jornada TI</x-table-cell>
                    <x-table-cell><x-status-badge status="active" /></x-table-cell>
                    <x-table-cell numeric>94</x-table-cell>
                    <x-table-cell>
                        <div class="flex gap-1">
                            <x-button variant="ghost" size="sm" icon="eye">Resultados</x-button>
                        </div>
                    </x-table-cell>
                </x-table-row>

                <x-table-row selected>
                    <x-table-cell header>Consulta presupuesto 2027</x-table-cell>
                    <x-table-cell><x-status-badge status="active" /></x-table-cell>
                    <x-table-cell numeric>318</x-table-cell>
                    <x-table-cell>
                        <div class="flex gap-1">
                            <x-button variant="ghost" size="sm" icon="eye">Resultados</x-button>
                        </div>
                    </x-table-cell>
                </x-table-row>

                <x-table-row>
                    <x-table-cell header>Clima laboral</x-table-cell>
                    <x-table-cell><x-status-badge status="closed" /></x-table-cell>
                    <x-table-cell numeric>1 204</x-table-cell>
                    <x-table-cell>
                        <div class="flex gap-1">
                            <x-button variant="ghost" size="sm" icon="arrow-down-tray">Exportar</x-button>
                        </div>
                    </x-table-cell>
                </x-table-row>

                <x-table-row disabled>
                    <x-table-cell header>Encuesta piloto</x-table-cell>
                    <x-table-cell><x-status-badge status="archived" /></x-table-cell>
                    <x-table-cell numeric>12</x-table-cell>
                    {{-- Fila disabled: las acciones se RETIRAN, no se ocultan --}}
                    <x-table-cell><span class="text-sm text-ink-500">—</span></x-table-cell>
                </x-table-row>

                <x-slot:cards>
                    <ul class="flex flex-col gap-4">
                        <li>
                            <x-card>
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-base font-semibold text-ink-900">Satisfacción Jornada TI</p>
                                    <x-status-badge status="active" />
                                </div>
                                <p class="mt-1 text-sm tabular text-ink-600">94 respuestas</p>
                                <div class="mt-4">
                                    <x-button variant="outline" size="md" block icon="eye">Ver resultados</x-button>
                                </div>
                            </x-card>
                        </li>
                        <li>
                            <x-card>
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-base font-semibold text-ink-900">Consulta presupuesto 2027</p>
                                    <x-status-badge status="active" />
                                </div>
                                <p class="mt-1 text-sm tabular text-ink-600">318 respuestas</p>
                                <div class="mt-4">
                                    <x-button variant="outline" size="md" block icon="eye">Ver resultados</x-button>
                                </div>
                            </x-card>
                        </li>
                        <li>
                            <x-card>
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-base font-semibold text-ink-900">Clima laboral</p>
                                    <x-status-badge status="closed" />
                                </div>
                                <p class="mt-1 text-sm tabular text-ink-600">1 204 respuestas</p>
                                <div class="mt-4">
                                    <x-button variant="outline" size="md" block icon="arrow-down-tray">Exportar</x-button>
                                </div>
                            </x-card>
                        </li>
                    </ul>
                </x-slot:cards>
            </x-table>
        </x-gallery-demo>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-gallery-demo state="loading · 5 filas de skeleton con el número real de columnas">
                <x-table
                    class="w-full"
                    caption="Cargando encuestas"
                    loading
                    :columns="[['label' => 'Encuesta'], ['label' => 'Estado'], ['label' => 'Respuestas', 'numeric' => true], ['label' => 'Acciones']]"
                />
            </x-gallery-demo>

            <x-gallery-demo state="error · se conservan los datos antiguos visibles">
                <x-table
                    class="w-full"
                    caption="Encuestas con error de carga"
                    error="No pudimos actualizar la lista. Mostramos la última versión conocida."
                    :columns="[['label' => 'Encuesta'], ['label' => 'Estado'], ['label' => 'Respuestas', 'numeric' => true]]"
                >
                    <x-table-row>
                        <x-table-cell header>Satisfacción Jornada TI</x-table-cell>
                        <x-table-cell><x-status-badge status="active" /></x-table-cell>
                        <x-table-cell numeric>94</x-table-cell>
                    </x-table-row>

                    {{-- DEFECTO CORREGIDO (Fase 5a): sin este slot, en móvil la
                         banda de error quedaba sola y los «datos antiguos
                         visibles» que promete §6.8 desaparecían. --}}
                    <x-slot:cards>
                        <x-card>
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-base font-semibold text-ink-900">Satisfacción Jornada TI</p>
                                <x-status-badge status="active" />
                            </div>
                            <p class="mt-1 text-sm tabular text-ink-600">94 respuestas</p>
                        </x-card>
                    </x-slot:cards>
                </x-table>
            </x-gallery-demo>
        </div>
    </div>
</x-gallery-section>

{{-- ====================================================== 8. CONEXIÓN --}}
<x-gallery-section
    id="conexion"
    title="Indicador de conexión"
    spec="§6.14 · §7.4"
    note="Componente crítico del producto. Forma distinta por estado (lleno / medio / hueco) además de color, texto siempre visible junto al punto, y región role=status separada de la región de datos. role=alert solo en «sin conexión»."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="los 4 estados">
            <div class="flex w-full flex-col gap-3">
                <x-connection-indicator state="online" detail="act. hace 2 s" />
                <x-connection-indicator state="reconnecting" detail="(2/5)" />
                <x-connection-indicator state="offline" detail="datos de hace 3 min" />
                <x-connection-indicator state="stale" />
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="prueba sin color" surface="canvas" note="Las mismas cuatro marcas en escala de grises: la forma sigue distinguiendo los estados aunque desaparezca el color.">
            <div class="flex w-full flex-col gap-3" style="filter: grayscale(1)">
                <x-connection-indicator state="online" detail="act. hace 2 s" />
                <x-connection-indicator state="reconnecting" detail="(2/5)" />
                <x-connection-indicator state="offline" detail="datos de hace 3 min" />
                <x-connection-indicator state="stale" />
            </div>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 9. PROGRESO --}}
<x-gallery-section
    id="progreso"
    title="Barra de progreso del participante"
    spec="§6.15"
    note="role=progressbar con aria-valuenow/min/max Y el texto «2 de 3». Altura 4px, pista ink-200, relleno brand-700, transición 320ms. Solo se muestra con 3 o más preguntas: con 2 es ruido."
>
    <div class="grid gap-6 lg:grid-cols-3">
        <x-gallery-demo state="0 de 3">
            <x-progress-bar :value="0" :max="3" text="0 de 3" class="w-full" />
        </x-gallery-demo>
        <x-gallery-demo state="1 de 3">
            <x-progress-bar :value="1" :max="3" text="1 de 3" class="w-full" />
        </x-gallery-demo>
        <x-gallery-demo state="3 de 3">
            <x-progress-bar :value="3" :max="3" text="3 de 3" class="w-full" />
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 10. RESULTADOS --}}
<x-gallery-section
    id="resultados"
    title="Barras de resultado"
    spec="§6.9"
    note="Barras horizontales: las etiquetas largas se leen sin rotar texto. Anatomía obligatoria etiqueta · barra · conteo · porcentaje, los tres en TEXTO. Cifras de muestra. El cálculo real —incluida la base de la selección múltiple de D-06, que puede superar el 100 %— es de StatsService en la Fase 5b."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="default · 4 series">
            <ul class="flex w-full flex-col gap-4">
                <x-result-bar label="Excelente" :count="52" :percent="55.3" :series="1" />
                <x-result-bar label="Buena" :count="28" :percent="29.8" :series="2" />
                <x-result-bar label="Regular" :count="10" :percent="10.6" :series="3" />
                <x-result-bar label="Mala" :count="4" :percent="4.3" :series="4" />
            </ul>
        </x-gallery-demo>

        <x-gallery-demo state="empty · barras a 0 con las etiquetas visibles" note="«Aún no hay respuestas a esta pregunta». No se oculta el gráfico: se muestra su forma vacía.">
            <div class="w-full">
                <p class="mb-3 text-sm text-ink-600">Aún no hay respuestas a esta pregunta.</p>
                <ul class="flex w-full flex-col gap-4">
                    <x-result-bar label="Excelente" :count="0" :percent="0" :series="1" />
                    <x-result-bar label="Buena" :count="0" :percent="0" :series="2" />
                    <x-result-bar label="Regular" :count="0" :percent="0" :series="3" />
                </ul>
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="loading · skeleton con el número real de barras previstas" class="lg:col-span-2">
            <x-skeleton-group class="w-full" label="Cargando resultados">
                <div class="flex flex-col gap-4">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="flex flex-col gap-1.5">
                            <x-skeleton shape="text" width="40%" />
                            <x-skeleton shape="chart-bar" />
                        </div>
                    @endfor
                </div>
            </x-skeleton-group>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 11. BANDAS --}}
<x-gallery-section
    id="bandas"
    title="Bandas de estado de conexión"
    spec="18.a · 19.b · 19.c"
    note="EMPUJAN el contenido, nunca se superponen: taparían datos del dashboard. Secuencia completa de §7.4: conectado → reconectando → sin conexión → recuperando → restablecido."
>
    <div class="flex flex-col gap-4">
        <x-alert tone="warning" class="w-full" title="Reconectando… intento 2 de 5">
            Los datos pueden no estar actualizados.
        </x-alert>

        <x-alert tone="danger" class="w-full" assertive icon="x-circle" title="Sin conexión en tiempo real">
            Mostrando datos de hace 3 min.
            <x-slot:action>
                <x-button variant="outline" size="sm" icon="arrow-path">Reintentar ahora</x-button>
            </x-slot:action>
        </x-alert>

        <x-alert tone="success" class="w-full">
            Conexión restablecida · datos actualizados.
        </x-alert>

        <x-alert tone="info" class="w-full">
            Las respuestas se guardan en este teléfono y se enviarán cuando vuelva la conexión.
        </x-alert>
    </div>
</x-gallery-section>

{{-- ====================================================== 12. TOASTS --}}
<x-gallery-section
    id="toasts"
    title="Toasts"
    spec="§6.10"
    note="Éxito 4 s · información 5 s · advertencia 8 s · ERROR SIN AUTOCIERRE (WCAG 2.2.1). El temporizador se pausa al pasar el ratón o al enfocar. Botón de cerrar de 44×44 siempre presente. Un toast nunca es el único medio de comunicar un error de formulario. En producción van fijos (abajo a la derecha en escritorio, arriba en móvil); aquí se muestran en el flujo para poder capturarlos."
>
    <div class="flex flex-col gap-4">
        {{-- :persist solo en la galería: si no, el de éxito (4 s) y el de
             información (5 s) se autocierran antes de la captura. --}}
        <x-toast tone="success" title="Encuesta publicada" persist>Ya puedes mostrar el código QR.</x-toast>
        <x-toast tone="info" persist>La exportación se está generando. Te avisamos al terminar.</x-toast>
        <x-toast tone="warning" title="Sin fecha de cierre" persist>La encuesta seguirá activa hasta que la cierres a mano.</x-toast>
        <x-toast tone="error" title="No pudimos guardar">Revisa tu conexión y vuelve a intentarlo. No se ha perdido nada de lo escrito.</x-toast>
    </div>
</x-gallery-section>

{{-- ====================================================== 13. MODAL --}}
<x-gallery-section
    id="modal"
    title="Modal"
    spec="§6.11"
    note="role=dialog, aria-modal, foco atrapado, Esc cierra, al cerrar el foco vuelve al disparador, fondo con inert. Por debajo de md se convierte en hoja inferior a ancho completo. Para acciones destructivas, el botón permanece deshabilitado hasta que el texto escrito coincide exactamente con el título."
>
    <div class="flex flex-wrap gap-3">
        <x-button variant="outline" data-modal-open="g-modal-export">Abrir modal de exportación</x-button>
        <x-button variant="danger" data-modal-open="g-modal-delete">Abrir confirmación destructiva</x-button>
        <x-button variant="outline" data-modal-open="g-modal-session">Abrir «sesión expirada»</x-button>
    </div>

    <p class="mt-3 text-sm text-ink-600">
        Los tres modales están en el DOM, ocultos. La captura estática del modal abierto se hace
        con <code class="font-mono text-xs">tools/capture-gallery.mjs</code>, que lo abre antes de disparar la foto.
    </p>
</x-gallery-section>

{{-- ====================================================== 14. SKELETON --}}
<x-gallery-section
    id="skeleton"
    title="Skeletons"
    spec="§6.12 · 16.b"
    note="Reproducen la FORMA y la ALTURA reales del contenido que sustituyen; si no, se produce el salto de diseño (CLS) que el brief prohíbe. Contenedor con aria-busy y texto oculto «Cargando…»; los bloques son aria-hidden. Bajo prefers-reduced-motion: color plano, sin brillo."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="skeleton de KPI (misma altura que x-stat)">
            <div class="grid w-full grid-cols-2 gap-4">
                @for ($i = 0; $i < 4; $i++)
                    <x-skeleton-group class="rounded-lg border border-ink-200 bg-white p-4 md:p-6">
                        <x-skeleton shape="text" width="60%" />
                        <div class="mt-2"><x-skeleton shape="kpi" width="45%" /></div>
                        <div class="mt-1"><x-skeleton shape="text" width="30%" height="0.75rem" /></div>
                    </x-skeleton-group>
                @endfor
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="skeleton de la página del participante" spec="16.d">
            <x-skeleton-group class="w-full" label="Cargando la encuesta">
                <x-skeleton shape="title" width="70%" />
                <div class="mt-2"><x-skeleton shape="text" :lines="2" /></div>
                <div class="mt-6 flex flex-col gap-3">
                    @for ($i = 0; $i < 3; $i++)
                        <x-skeleton shape="block" height="48px" />
                    @endfor
                </div>
            </x-skeleton-group>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ====================================================== 15. VACÍOS --}}
<x-gallery-section
    id="vacios"
    title="Estados vacíos"
    spec="§6.13"
    note="CUATRO variantes con mensajes DISTINTOS: un estado vacío genérico no ayuda. Icono decorativo de 48px, título xl/600, explicación de máx. 40 caracteres por línea y UNA acción primaria como mucho. El icono usa ink-500, no ink-400 (que es decorativo y no alcanza contraste)."
>
    <div class="grid gap-6 md:grid-cols-2">
        <x-empty-state variant="no-surveys">
            <x-button variant="primary" icon="plus">Nueva encuesta</x-button>
        </x-empty-state>

        <x-empty-state variant="no-results" title='Sin resultados para "clima"'>
            <x-button variant="outline">Limpiar filtros</x-button>
        </x-empty-state>

        <x-empty-state variant="awaiting-responses">
            <x-button variant="primary" icon="qr-code">Presentar QR</x-button>
        </x-empty-state>

        <x-empty-state variant="no-text-answers" />
    </div>
</x-gallery-section>

{{-- ====================================================== 16. TIPOGRAFÍA --}}
<x-gallery-section
    id="tipografia"
    title="Tipografía, color y espaciado"
    spec="§5.1 · §5.3"
    note="Inter autoalojada (SIL OFL), sin CDN externa en tiempo de ejecución. Si esta sección se ve con la grotesca del sistema en lugar de Inter, es que el woff2 no se emitió: es el control visual de que la fuente carga."
>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-gallery-demo state="escala tipográfica">
            <div class="flex w-full flex-col gap-2">
                <p class="text-5xl font-bold text-ink-900">5xl · Código QR</p>
                <p class="text-4xl font-bold tabular text-ink-900">4xl · 1 204</p>
                <p class="text-3xl font-bold text-ink-900">3xl · Título de página (h1)</p>
                <p class="text-2xl font-semibold text-ink-900">2xl · Título de pantalla (h2)</p>
                <p class="text-xl font-semibold text-ink-900">xl · Título de tarjeta (h3)</p>
                <p class="text-lg font-semibold text-ink-900">lg · Enunciado de pregunta en móvil</p>
                <p class="text-base text-ink-900">base · Cuerpo, 16px, mínimo en inputs móviles</p>
                <p class="text-sm text-ink-600">sm · Etiquetas, ayuda, celdas de tabla</p>
                <p class="text-xs text-ink-500">xs · Metadatos, pie de tabla</p>
            </div>
        </x-gallery-demo>

        {{--
            DEFECTO CORREGIDO TRAS VER EL RENDER (Fase 5a): esta comparación
            estaba envuelta en `font-mono`. Una monoespaciada YA tiene cifras
            de ancho fijo, así que las dos columnas salían idénticas y la
            demostración no probaba nada. Ahora usa Inter (proporcional), que
            es la familia real del producto y donde el 1 sí es más estrecho
            que el 0.
        --}}
        <x-gallery-demo state="tabular-nums: obligatorio en todo contador en vivo" note="Arriba sin tabular-nums, abajo con él, en Inter. Sin la corrección, un contador que pasa de 99 a 100 cambia de ancho y el diseño «salta» — justo lo que el brief prohíbe.">
            <div class="flex w-full flex-col gap-3">
                <div>
                    <p class="text-xs text-ink-500">sin tabular-nums</p>
                    <p class="text-4xl font-bold text-ink-900">1111</p>
                    <p class="text-4xl font-bold text-ink-900">0000</p>
                </div>
                <div>
                    <p class="text-xs text-ink-500">con tabular-nums</p>
                    <p class="text-4xl font-bold tabular text-ink-900">1111</p>
                    <p class="text-4xl font-bold tabular text-ink-900">0000</p>
                </div>
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="roles de color en modo claro" class="lg:col-span-2">
            <div class="grid w-full gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @php
                    $roles = [
                        ['Texto principal', 'ink-900', 'text-ink-900', '17,44:1'],
                        ['Texto secundario', 'ink-600', 'text-ink-600', '6,83:1'],
                        ['Texto terciario', 'ink-500', 'text-ink-500', '4,88:1'],
                        ['Enlace / acción', 'brand-700', 'text-brand-700', '9,47:1'],
                        ['Éxito', 'success-700', 'text-success-700', '6,09:1'],
                        ['Error', 'danger-700', 'text-danger-700', '6,54:1'],
                        ['Aviso', 'warning-800', 'text-warning-800', '7,48:1'],
                        ['Información', 'info-700', 'text-info-700', '7,00:1'],
                    ];
                @endphp
                @foreach ($roles as [$name, $token, $cls, $ratio])
                    <div class="rounded-md border border-ink-200 bg-white p-3">
                        <p class="text-base font-semibold {{ $cls }}">{{ $name }}</p>
                        <p class="mt-1 font-mono text-xs text-ink-500">{{ $token }} · {{ $ratio }}</p>
                    </div>
                @endforeach
            </div>
        </x-gallery-demo>

        <x-gallery-demo state="series de gráfico 1–6" class="lg:col-span-2" note="Ninguna pareja depende solo del eje rojo-verde, pero la simulación de daltonismo NO se ha realizado (§5.2). Por eso la etiqueta y el valor en texto son obligatorios en toda serie.">
            <div class="grid w-full grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @for ($s = 1; $s <= 6; $s++)
                    <div class="rounded-md border border-ink-200 bg-white p-3">
                        <span class="block h-8 rounded-sm bg-chart-{{ $s }}"></span>
                        <p class="mt-2 font-mono text-xs text-ink-600">chart-{{ $s }}</p>
                    </div>
                @endfor
            </div>
        </x-gallery-demo>
    </div>
</x-gallery-section>

{{-- ============================================ modales (fuera del flujo) --}}
<x-modal id="g-modal-export" title="Exportar resultados">
    <p class="mb-4">Elige el formato y el alcance de la exportación.</p>

    <x-select
        id="g-export-formato"
        label="Formato"
        :options="['csv' => 'CSV (separado por comas)', 'json' => 'JSON']"
        selected="csv"
        class="mb-4"
    />

    <x-choice-group id="g-export-alcance" legend="Alcance" class="mt-2">
        <x-choice-option name="g-export-alcance-op" value="todo" checked>Todas las respuestas</x-choice-option>
        <x-choice-option name="g-export-alcance-op" value="abiertas">Solo respuestas de texto libre</x-choice-option>
    </x-choice-group>

    <x-slot:footer>
        <x-button variant="outline" data-modal-close data-modal-initial-focus>Cancelar</x-button>
        <x-button variant="primary" icon="arrow-down-tray">Exportar</x-button>
    </x-slot:footer>
</x-modal>

<x-modal id="g-modal-delete" title="Eliminar «Clima laboral»">
    <p class="mb-2">
        Esta acción no se puede deshacer. Se eliminarán la encuesta y sus 1 204 respuestas.
    </p>
    <p class="mb-4 text-sm text-ink-600">
        Escribe <strong class="font-semibold text-ink-900">Clima laboral</strong> para confirmar.
    </p>

    <x-input
        id="g-delete-confirm"
        label="Título de la encuesta"
        placeholder="Clima laboral"
        data-confirm-input="g-delete-submit"
        data-confirm-expected="Clima laboral"
    />

    <x-slot:footer>
        {{-- Foco inicial en el control NO destructivo (§6.11) --}}
        <x-button variant="outline" data-modal-close data-modal-initial-focus>Cancelar</x-button>
        <x-button id="g-delete-submit" variant="danger" icon="trash" disabled>Eliminar definitivamente</x-button>
    </x-slot:footer>
</x-modal>

{{-- Sin botón de cerrar: no hay nada útil detrás (18.c) --}}
<x-modal id="g-modal-session" title="Tu sesión expiró" locked :dismissible="false">
    <p class="mb-2">Por seguridad cerramos la sesión tras un periodo de inactividad.</p>
    <p class="text-sm text-ink-600">Lo que estabas editando se guardó como borrador.</p>

    <x-slot:footer>
        <x-button variant="primary" data-modal-initial-focus>Iniciar sesión de nuevo</x-button>
    </x-slot:footer>
</x-modal>
