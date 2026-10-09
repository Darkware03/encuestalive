<?php

/*
 * tools/f6/provision.php — aprovisionamiento del banco de pruebas E2E (Fase 6).
 *
 * Crea una base de datos SQLite DEDICADA para las pruebas de extremo a extremo,
 * separada de la de desarrollo, para que:
 *   · los E2E no ensucien los datos con los que trabaja nadie mas;
 *   · el fixture sea EXACTAMENTE el mismo en cada ejecucion (reproducibilidad);
 *   · CA-15 pueda reiniciar servicios contra un fichero cuyo contenido previo
 *     se conoce con exactitud.
 *
 * NO toca codigo de produccion. Solo escribe filas.
 *
 * Uso:
 *   DB_DATABASE=/ruta/e2e.sqlite php tools/f6/provision.php
 *
 * Salida: JSON con los identificadores publicos y credenciales del fixture,
 * que las pruebas de Playwright leen de `storage/app/f6/fixture.json`.
 *
 * DATOS FICTICIOS. Ni un dato personal real (brief §5). Correos en .test
 * (RFC 6761); ninguna IP, ningun nombre de persona.
 */

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fresh = in_array('--fresh', $argv, true);

if ($fresh) {
    fwrite(STDERR, "[provision] migrate:fresh sobre " . config('database.connections.sqlite.database') . PHP_EOL);
    Artisan::call('migrate:fresh', ['--force' => true]);
    fwrite(STDERR, Artisan::output());
}

/* ------------------------------------------------------------------ admins */

$owner = User::query()->updateOrCreate(
    ['email' => 'qa-e2e@encuestalive.test'],
    ['name' => 'QA E2E', 'password' => Hash::make('qa-e2e-password'), 'email_verified_at' => now()],
);

// Segundo admin: CA-12 exige que "ser admin" NO baste para ver una encuesta
// ajena. Sin un segundo propietario no se puede probar esa mitad del criterio.
$intruder = User::query()->updateOrCreate(
    ['email' => 'qa-otro@encuestalive.test'],
    ['name' => 'QA Otro Admin', 'password' => Hash::make('qa-e2e-password'), 'email_verified_at' => now()],
);

/* ---------------------------------------------------------------- limpieza */

/*
 * OJO CON EL ALFABETO. ADR-0006 usa Base32 Crockford, que EXCLUYE I, L, O y U
 * para que el identificador se pueda dictar en voz alta. El patron de la ruta
 * publica es '[0-9A-HJKMNP-TV-Z]{16}'.
 *
 * Mi primer fixture usaba 'E2EACTIVA00000AA' y 'E2EPAUSADA0000AA': ACTIVA lleva
 * una I y PAUSADA una U, asi que NINGUNA de las dos encajaba en la ruta y
 * /s/{publicId} devolvia 404. Error mio, no de la aplicacion: el backend ya
 * habia tropezado con lo mismo en el seeder de demostracion. Se deja escrito
 * para que no se repita por tercera vez.
 */
$ids = ['E2E0ACTVA0000001', 'E2E0CERRADA00002', 'E2E0PASVA0000003', 'E2E0AJENA0000004', 'E2E0CARRERA00005'];

DB::transaction(function () use ($ids): void {
    // El borrado en cascada esta probado en Fase 4; aqui solo se usa.
    Survey::query()->whereIn('public_id', $ids)->get()->each->delete();
});

/* ------------------------------------------------------------- constructor */

/**
 * @param array<int, array> $spec
 */
$makeSurvey = function (
    string $publicId,
    string $title,
    string $status,
    User $user,
    array $spec,
    ?Carbon $endsAt = null,
) : Survey {
    $survey = Survey::query()->create([
        'public_id' => $publicId,
        'title' => $title,
        'description' => 'Encuesta de banco de pruebas E2E de la Fase 6. Datos ficticios.',
        'status' => $status,
        'version_number' => 1,
        'created_by_user_id' => $user->id,
        'published_at' => $status === Survey::STATUS_DRAFT ? null : now(),
        'closed_at' => $status === Survey::STATUS_CLOSED ? now() : null,
        'closed_by_user_id' => $status === Survey::STATUS_CLOSED ? $user->id : null,
        'ends_at' => $endsAt,
    ]);

    foreach ($spec as $position => $q) {
        $question = Question::query()->create([
            'survey_id' => $survey->id,
            'type' => $q['type'],
            'prompt' => $q['prompt'],
            'is_required' => $q['required'] ?? true,
            'position' => $position + 1,
            'min_selections' => $q['min'] ?? null,
            'max_selections' => $q['max'] ?? null,
            'min_length' => $q['min_length'] ?? null,
            'max_length' => $q['max_length'] ?? null,
        ]);

        foreach ($q['options'] ?? [] as $i => $label) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'label' => $label,
                'position' => $i + 1,
            ]);
        }
    }

    return $survey->fresh(['questions.options']);
};

/*
 * Fixture principal. Cubre los tres tipos soportados (CA-01) y, sobre todo,
 * lleva limites de seleccion multiple REALES (min 1, max 2 de 4 opciones) para
 * que CA-05 se pueda probar por exceso y por defecto, no solo por el camino
 * feliz. El texto libre tiene max_length 200: un limite BAJO a proposito, para
 * que la prueba de desbordamiento no tenga que enviar 2000 caracteres.
 */
$spec = [
    [
        'type' => Question::TYPE_SINGLE_CHOICE,
        'prompt' => '¿Como valoras la jornada?',
        'options' => ['Excelente', 'Buena', 'Mejorable'],
    ],
    [
        'type' => Question::TYPE_MULTIPLE_CHOICE,
        'prompt' => '¿Que temas te interesaron? (maximo 2)',
        'min' => 1,
        'max' => 2,
        'options' => ['Accesibilidad', 'Seguridad', 'Rendimiento', 'Pruebas'],
    ],
    [
        'type' => Question::TYPE_FREE_TEXT,
        'prompt' => 'Comentario libre (opcional)',
        'required' => false,
        'max_length' => 200,
    ],
];

$active = $makeSurvey('E2E0ACTVA0000001', '[E2E] Jornada activa', Survey::STATUS_ACTIVE, $owner, $spec);
$closed = $makeSurvey('E2E0CERRADA00002', '[E2E] Jornada cerrada', Survey::STATUS_CLOSED, $owner, $spec);
$paused = $makeSurvey('E2E0PASVA0000003', '[E2E] Jornada pausada', Survey::STATUS_PAUSED, $owner, $spec);
$alien = $makeSurvey('E2E0AJENA0000004', '[E2E] Encuesta de otro admin', Survey::STATUS_ACTIVE, $intruder, $spec);
$race = $makeSurvey('E2E0CARRERA00005', '[E2E] Carrera de cierre', Survey::STATUS_ACTIVE, $owner, $spec);

/* ------------------------------------------------- respuestas de partida */

/*
 * Tres respuestas de base en la encuesta activa.
 *
 * Sin esto, la exportacion CSV recien aprovisionada no tiene ni una fila de
 * datos y las pruebas de CA-11/CA-14 no pueden comprobar su contenido: no
 * fallarian por un defecto, sino por falta de datos, que es la peor clase de
 * fallo porque parece real.
 *
 * Se escriben por el MODELO y no por HTTP a proposito: el aprovisionamiento
 * debe funcionar con el servidor apagado.
 */
$now = Carbon::now();
$baseline = [
    ['single' => 0, 'multi' => [0], 'text' => 'La jornada estuvo muy bien organizada.'],
    ['single' => 1, 'multi' => [1, 2], 'text' => null],
    ['single' => 0, 'multi' => [3], 'text' => 'Mas ejemplos practicos, por favor.'],
];

DB::transaction(function () use ($active, $baseline, $now): void {
    $questions = $active->questions->keyBy('type');
    $single = $questions[Question::TYPE_SINGLE_CHOICE];
    $multi = $questions[Question::TYPE_MULTIPLE_CHOICE];
    $free = $questions[Question::TYPE_FREE_TEXT];

    foreach ($baseline as $i => $row) {
        $session = App\Models\ParticipantSession::query()->create([
            'survey_id' => $active->id,
            'token_hash' => hash('sha256', 'f6-baseline-' . $i . '-' . $active->public_id),
            'submission_state' => App\Models\ParticipantSession::STATE_COMPLETED,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'completed_at' => $now,
        ]);

        $response = App\Models\Response::query()->create([
            'survey_id' => $active->id,
            'participant_session_id' => $session->id,
            'submission_uuid' => (string) Illuminate\Support\Str::uuid(),
            'survey_version_number' => $active->version_number,
            'completed_at' => $now,
        ]);

        $a1 = App\Models\ResponseAnswer::query()->create([
            'response_id' => $response->id, 'question_id' => $single->id, 'text_answer' => null,
        ]);
        App\Models\ResponseSelection::query()->create([
            'response_answer_id' => $a1->id,
            'question_option_id' => $single->options[$row['single']]->id,
        ]);

        $a2 = App\Models\ResponseAnswer::query()->create([
            'response_id' => $response->id, 'question_id' => $multi->id, 'text_answer' => null,
        ]);
        foreach ($row['multi'] as $idx) {
            App\Models\ResponseSelection::query()->create([
                'response_answer_id' => $a2->id,
                'question_option_id' => $multi->options[$idx]->id,
            ]);
        }

        if ($row['text'] !== null) {
            App\Models\ResponseAnswer::query()->create([
                'response_id' => $response->id, 'question_id' => $free->id, 'text_answer' => $row['text'],
            ]);
        }
    }
});

/* ---------------------------------------------------------------- salida */

$out = [
    'generated_at' => now()->toIso8601ZuluString(),
    'database' => config('database.connections.sqlite.database'),
    'admin' => ['email' => $owner->email, 'password' => 'qa-e2e-password', 'id' => $owner->id],
    'other_admin' => ['email' => $intruder->email, 'password' => 'qa-e2e-password', 'id' => $intruder->id],
    'surveys' => [],
];

foreach (['active' => $active, 'closed' => $closed, 'paused' => $paused, 'alien' => $alien, 'race' => $race] as $k => $s) {
    $out['surveys'][$k] = [
        'id' => $s->id,
        'public_id' => $s->public_id,
        'title' => $s->title,
        'status' => $s->status,
        'questions' => $s->questions->map(fn (Question $q) => [
            'id' => $q->id,
            // El enunciado hace falta en los E2E para comprobar que el CSV
            // exporta las preguntas reales. Faltaba en la primera version y
            // la asercion comparaba contra `undefined`, que en JS no explota:
            // simplemente no encuentra nada. Por eso se declara explicito.
            'prompt' => $q->prompt,
            'type' => $q->type,
            'required' => (bool) $q->is_required,
            'min_selections' => $q->min_selections,
            'max_selections' => $q->max_selections,
            'max_length' => $q->max_length,
            'options' => $q->options->map(fn (QuestionOption $o) => ['id' => $o->id, 'label' => $o->label])->all(),
        ])->all(),
    ];
}

$dir = storage_path('app/f6');
if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($dir . '/fixture.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
