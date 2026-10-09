<?php

/*
 * config/encuestalive.php — parametros de dominio de EncuestaLive.
 *
 * Encargo abierto por DATABASE.md §8.2 y §10.4 para backend-engineer.
 *
 * Todo valor que una decision vinculante fija (D-06, D-07, D-12) vive aqui y
 * NO repartido por el codigo: un numero magico duplicado es un numero que
 * acabara divergiendo.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Presencia (D-12)
    |--------------------------------------------------------------------------
    |
    | D-12 resolvio la discrepancia entre ADR-0004 (15/45) y el PRD §8.1
    | (20/60) a favor del PRD: en un evento con datos moviles saturados, una
    | ventana de 45 s declara "desconectado" a quien solo tuvo un bache de red
    | e infla artificialmente la metrica de pendientes.
    |
    | La ventana NO afecta al esquema: `conectadas` se deriva de last_seen_at
    | en cada consulta y no hay contador almacenado (DATABASE.md §11.6).
    |
    */
    'presence' => [
        'heartbeat_interval_seconds' => (int) env('PRESENCE_HEARTBEAT_SECONDS', 20),
        'expiry_window_seconds' => (int) env('PRESENCE_EXPIRY_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sesion anonima del participante (ADR-0005)
    |--------------------------------------------------------------------------
    |
    | El token es opaco, de 256 bits, y su alcance es POR ENCUESTA: la cookie
    | se emite con Path=/s/{publicId}, de modo que el navegador no la envia a
    | otra encuesta. Ese es el control tecnico que impide el seguimiento entre
    | encuestas; no una promesa de buena conducta del servidor.
    |
    */
    'participant' => [
        'cookie_prefix' => 'el_pt_',
        'token_bytes' => 32,
        'token_ttl_minutes' => (int) env('PARTICIPANT_TOKEN_TTL_MINUTES', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limitacion de frecuencia (brief §6.1, hallazgo F-06)
    |--------------------------------------------------------------------------
    |
    | ================= EL COMPROMISO, EXPLICADO =================
    | Brief §6.1: una sala de 200 personas con un unico wifi COMPARTE UNA SOLA
    | IP PUBLICA, y D-05 PA-01 fija 300 sesiones simultaneas como objetivo. Un
    | limite por IP ingenuo cerraria la encuesta a los demas en cuanto
    | respondiera el primero, y ese es literalmente el caso de uso principal.
    |
    | Por eso la clave PRIMARIA sigue siendo el token de sesion: 300 telefonos
    | tras una IP son 300 cubos independientes. Eso no cambia.
    |
    | Lo que F-06 corrige es que `public-open`, `heartbeat` y `public-state` no
    | tenian NINGUNA red de seguridad por IP, de modo que un cliente que
    | acumulara tokens podia crecer sin techo. Ahora hay un segundo cubo por
    | IP, dimensionado MUY por encima del uso humano legitimo:
    |
    |   · 300 participantes abriendo la pagina y recargando algo    -> 1200/min
    |   · 300 participantes latiendo cada 20 s (3/min) con margen   -> 3000/min
    |   · 300 participantes sondeando el estado cada 5 s (12/min)   -> 6000/min
    |
    | PRECIO QUE SE PAGA, SIN ADORNOS: un techo por IP nunca es gratis. Si una
    | sola IP llevara MAS de ~300 personas muy activas, empezaria a recibir
    | 429. Se ha preferido ese riesgo remoto a dejar tres rutas sin techo
    | alguno, porque `heartbeat` ESCRIBE en participant_sessions y SQLite
    | admite un unico escritor (riesgo R3). El techo acota la amplificacion;
    | no la elimina.
    |
    | Son configurables precisamente para que el propietario pueda subirlos
    | si su evento es mayor, sin tocar codigo.
    |
    */
    'rate_limits' => [
        'public_open_per_token' => (int) env('RL_PUBLIC_OPEN_TOKEN', 60),
        'public_open_per_ip' => (int) env('RL_PUBLIC_OPEN_IP', 1200),

        'heartbeat_per_token' => (int) env('RL_HEARTBEAT_TOKEN', 10),
        'heartbeat_per_ip' => (int) env('RL_HEARTBEAT_IP', 3000),

        'public_state_per_token' => (int) env('RL_PUBLIC_STATE_TOKEN', 30),
        'public_state_per_ip' => (int) env('RL_PUBLIC_STATE_IP', 6000),

        'submit_per_token' => (int) env('RL_SUBMIT_TOKEN', 5),
        'submit_per_ip' => (int) env('RL_SUBMIT_IP', 40),

        'session_issue_per_ip' => (int) env('RL_SESSION_ISSUE_IP', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retencion (D-12, brief §5.3)
    |--------------------------------------------------------------------------
    |
    | D-12 enmendo D-05 a 30 dias: privacidad desde el diseno, y ante la duda
    | el plazo mas corto es el mas defendible.
    |
    | [SUPUESTO - requiere validacion legal] Nadie del equipo valida normativa
    | salvadorena. Configurable para que el propietario lo ajuste.
    |
    */
    'retention' => [
        'security_log_days' => (int) env('SECURITY_LOG_RETENTION_DAYS', 30),
        'session_days' => (int) env('SESSION_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tiempo real (D-02, ADR-0002)
    |--------------------------------------------------------------------------
    |
    | Reverb es el primario. El sondeo NO es opcional: es lo que garantiza
    | CA-08 si Reverb no arranca en el entorno de destino.
    |
    | Regla dura e innegociable de D-02: el dashboard lee SIEMPRE de la base de
    | datos via StatsService. El WebSocket solo indica CUANDO refrescar, nunca
    | QUE dato mostrar.
    |
    */
    'realtime' => [
        /*
        |----------------------------------------------------------------------
        | EL SUELO DE SONDEO (DEF-01, Fase 6b) — NUNCA null
        |----------------------------------------------------------------------
        |
        | Hasta la Fase 6b el estado `connected` devolvia null y el atributo
        | `wire:poll` DESAPARECIA del DOM. Combinado con que livewire/livewire
        | 4.4.7 no trae puente con Echo (verificado: 0 apariciones de
        | "echo-private" en vendor/livewire/livewire/src y /dist), el resultado
        | era un dashboard CONGELADO que mostraba "Conectado · en vivo". El
        | sistema solo se actualizaba cuando Reverb estaba CAIDO: el
        | comportamiento exactamente inverso al deseado.
        |
        | D-02 es una regla dura: "se mantiene OBLIGATORIO el respaldo
        | wire:poll; no es opcional" y "un fallo de difusion JAMAS puede
        | degradar el servicio". Un respaldo que se apaga cuando el primario
        | parece sano no es un respaldo: es una averia silenciosa, peor que no
        | tener tiempo real, porque la pantalla miente.
        |
        | PRINCIPIO INNEGOCIABLE: Reverb solo puede ACELERAR el refresco, jamas
        | sustituirlo ni apagarlo. Por eso los tres estados tienen intervalo y
        | NINGUNO puede ser null. Con socket sano el sondeo se ESPACIA a 15 s
        | —red de seguridad barata: 4 peticiones/minuto por admin— en lugar de
        | desaparecer. Asi un fallo de difusion se convierte en una
        | degradacion imperceptible (15 s de latencia) y no en un dashboard
        | muerto.
        */
        'poll_connected' => env('REALTIME_POLL_CONNECTED', '15s'),
        // Durante un corte, para que el dashboard no se congele.
        'poll_reconnecting' => env('REALTIME_POLL_RECONNECTING', '10s'),
        // Respaldo permanente en estado `degraded` (ADR-0002).
        'poll_degraded' => env('REALTIME_POLL_DEGRADED', '5s'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites de contenido aplicados en PHP
    |--------------------------------------------------------------------------
    |
    | CRITICO (DATABASE.md §10.1): SQLite NO aplica longitudes de VARCHAR. Un
    | texto de 5000 caracteres entra aqui en un string(500) y fallaria en
    | MySQL 8, es decir, en produccion. La validacion en PHP es la UNICA
    | barrera real en desarrollo y es obligatoria, no opcional.
    |
    */
    'limits' => [
        'survey_title' => 150,
        'survey_description' => 2000,
        'survey_instructions' => 2000,
        'question_prompt' => 300,
        'option_label' => 200,
        'max_questions' => 50,
        'max_options_per_question' => 20,
        'free_text_max' => 2000,
        'free_text_default_max' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exportacion CSV (D-06, brief §6.2)
    |--------------------------------------------------------------------------
    */
    'export' => [
        // D-06: el CSV exporta AMBAS bases de porcentaje en columnas separadas.
        'include_both_percentage_bases' => true,
        /*
         * brief §6.2: caracteres que convierten una celda en formula.
         *
         * F-03 (Fase 7): esta lista traia tambien "\t" y "\r", lo que
         * describia mal el control y dejaba fuera a LF, al espacio y al NBSP,
         * que escapaban. Ya no es una lista de "primeros bytes peligrosos":
         * CsvInjectionSanitizer RECORTA todo el espacio en blanco inicial
         * (incluidos TAB, CR, LF, NBSP, BOM y los espacios Unicode) y despues
         * compara el primer caracter SIGNIFICATIVO contra estos cuatro.
         *
         * Por eso TAB y CR salen de aqui: no son prefijos de formula, son
         * espacio en blanco que se recorta antes de decidir. `\t=1+1` sigue
         * neutralizandose; `\t1+1` no, porque no es una formula.
         *
         * La coherencia entre esta lista y el saneador la verifica
         * tests/Feature/Export/CsvInjectionSanitizerTest.php.
         */
        'formula_prefixes' => ['=', '+', '-', '@'],
    ],
];
