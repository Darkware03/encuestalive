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
        // Respaldo permanente en estado `degraded` (ADR-0002).
        'poll_degraded' => env('REALTIME_POLL_DEGRADED', '5s'),
        // Durante un corte, para que el dashboard no se congele.
        'poll_reconnecting' => env('REALTIME_POLL_RECONNECTING', '10s'),
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
        // brief §6.2: prefijos que convierten una celda en formula.
        'formula_prefixes' => ['=', '+', '-', '@', "\t", "\r"],
    ],
];
