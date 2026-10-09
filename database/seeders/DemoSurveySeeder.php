<?php

namespace Database\Seeders;

use App\Models\AdminAuditEvent;
use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\ResponseAnswer;
use App\Models\ResponseSelection;
use App\Models\SecurityEvent;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de DEMOSTRACION para que frontend y QA tengan con que trabajar.
 *
 * ==========================  DATOS FICTICIOS  ==============================
 * Nada de lo que se siembra aqui procede de una persona real. Los textos libres
 * son frases genericas sobre una capacitacion imaginaria; las IP de
 * security_events pertenecen al rango de documentacion 192.0.2.0/24 (RFC 5737);
 * el correo usa el TLD reservado .test (RFC 6761). PROHIBIDO sustituirlos por
 * datos personales reales (brief §5).
 * ===========================================================================
 *
 * IDEMPOTENCIA
 * Se borra y se vuelve a crear el bloque de demostracion identificado por los
 * `public_id` fijos de abajo. Ejecutar el seeder N veces deja exactamente el
 * mismo estado: no acumula encuestas ni respuestas. Las encuestas que NO esten
 * en esa lista no se tocan jamas.
 *
 * El `public_id` de demostracion es FIJO y por tanto predecible, al contrario
 * que los de produccion (ADR-0006, 80 bits de CSPRNG). Es deliberado: permite
 * que frontend y QA guarden la URL /s/DEM0K7M2QX9RTB4W. No debe sembrarse en un
 * entorno accesible desde Internet.
 */
class DemoSurveySeeder extends Seeder
{
    public const DEMO_ACTIVE_PUBLIC_ID = 'DEM0K7M2QX9RTB4W';

    /*
     * CORREGIDO EN LA FASE 5b (backend-engineer), reportado en el informe.
     *
     * El valor anterior era 'DEM0CL0SED9XKT2V', que se leia bien ("DEMO
     * CLOSED") pero contenia 'L' y 'O'. El alfabeto Base32 Crockford de
     * ADR-0006 excluye expresamente I, L, O y U para que el identificador se
     * pueda dictar en voz alta sin confusiones, y el patron de la ruta
     * publica es '[0-9A-HJKMNP-TV-Z]{16}'.
     *
     * Consecuencia real, no teorica: /s/DEM0CL0SED9XKT2V NO ENCAJABA en la
     * ruta y devolvia 404, de modo que la encuesta cerrada de demostracion
     * era inalcanzable y no se podia verificar CA-13 con datos del seeder.
     *
     * El valor nuevo respeta el alfabeto y conserva la legibilidad.
     */
    public const DEMO_CLOSED_PUBLIC_ID = 'DEM0CERRADA9XKT2';

    /** Titulo del borrador: no tiene public_id con el que identificarlo (ADR-0006). */
    public const DEMO_DRAFT_TITLE = '[DEMO] Borrador sin publicar';

    public function run(): void
    {
        $admin = User::where('email', AdminUserSeeder::EMAIL)->first()
            ?? User::query()->orderBy('id')->firstOrFail();

        $this->purgePreviousDemo();

        DB::transaction(function () use ($admin): void {
            $survey = $this->createActiveDemoSurvey($admin);
            $this->createParticipation($survey);
            $this->createClosedDemoSurvey($admin);
            $this->createDraftDemoSurvey($admin);
            $this->createSecurityIsland($survey);
        });
    }

    /**
     * Idempotencia. El borrado en cascada se apoya en las FK reales declaradas en
     * las migraciones, que en SQLite solo funcionan porque config/database.php
     * activa `foreign_key_constraints` (D-04).
     */
    private function purgePreviousDemo(): void
    {
        $ids = Survey::query()
            ->whereIn('public_id', [self::DEMO_ACTIVE_PUBLIC_ID, self::DEMO_CLOSED_PUBLIC_ID])
            ->orWhere('title', self::DEMO_DRAFT_TITLE)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            // admin_audit_events.survey_id es SET NULL; se limpian explicitamente
            // para no dejar huerfanos de demostracion acumulandose.
            AdminAuditEvent::whereIn('survey_id', $ids)->delete();
            Survey::whereIn('id', $ids)->delete();
        }

        // Isla B: se identifica por su propio marcador, nunca por una FK a la isla A.
        SecurityEvent::where('reason', 'demo_seed')->delete();
    }

    private function createActiveDemoSurvey(User $admin): Survey
    {
        $survey = Survey::create([
            'public_id' => self::DEMO_ACTIVE_PUBLIC_ID,
            'title' => '[DEMO] Evaluacion de la capacitacion',
            'description' => 'Encuesta de demostracion con datos ficticios. '
                .'Sus respuestas son anonimas: no pedimos nombre ni correo.',
            'instructions' => 'Responda las tres preguntas y pulse Enviar.',
            'status' => Survey::STATUS_ACTIVE,
            'version_number' => 1,
            'created_by_user_id' => $admin->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(6),
            'published_at' => now()->subHour(),
        ]);

        // --- Pregunta 1: seleccion unica -------------------------------------
        $q1 = Question::create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'En general, como valora la capacitacion de hoy?',
            'is_required' => true,
            'position' => 1,
        ]);
        $this->options($q1, ['Excelente', 'Buena', 'Regular', 'Deficiente']);

        // --- Pregunta 2: seleccion multiple ----------------------------------
        $q2 = Question::create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_MULTIPLE_CHOICE,
            'prompt' => 'Que aspectos le resultaron mas utiles? (puede elegir varios)',
            'is_required' => true,
            'position' => 2,
            'min_selections' => 1,
            'max_selections' => 3,
        ]);
        $this->options($q2, ['Metodologia', 'Materiales', 'Ejercicios practicos', 'Tiempo dedicado']);

        // --- Pregunta 3: texto libre, opcional (RN-13) ------------------------
        Question::create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_FREE_TEXT,
            'prompt' => 'Que mejoraria para la proxima sesion?',
            'is_required' => false,
            'position' => 3,
            'min_length' => 0,
            'max_length' => 1000,
        ]);

        AdminAuditEvent::create([
            'user_id' => $admin->id,
            'survey_id' => $survey->id,
            'survey_title_snapshot' => $survey->title,
            'action' => AdminAuditEvent::ACTION_PUBLISHED,
            'metadata' => ['source' => 'DemoSurveySeeder'],
            'occurred_at' => now()->subHour(),
        ]);

        return $survey;
    }

    /**
     * Siembra sesiones y respuestas de modo que las metricas de PRD §8 cuadren y
     * QA pueda comprobar las invariantes de §8.7 sin tocar nada:
     *
     *   sesiones_iniciadas = 14
     *   participaron       = 12   (9 completadas + 3 en progreso)
     *   completadas        =  9
     *   conectadas         =  8   (5 completadas conectadas + 3 en progreso conectadas)
     *   pendientes         =  3
     *   tasa_respuesta     = 9/14 = 64,3 %   (D-07)
     *
     *   completadas <= participaron <= sesiones_iniciadas            9 <= 12 <= 14  OK
     *   pendientes + conectadas_que_completaron = conectadas          3 +  5 =  8   OK
     */
    private function createParticipation(Survey $survey): void
    {
        $questions = $survey->questions()->with('options')->get();
        $single = $questions->firstWhere('type', Question::TYPE_SINGLE_CHOICE);
        $multiple = $questions->firstWhere('type', Question::TYPE_MULTIPLE_CHOICE);
        $freeText = $questions->firstWhere('type', Question::TYPE_FREE_TEXT);

        $singleOptions = $single->options->pluck('id')->all();
        $multipleOptions = $multiple->options->pluck('id')->all();

        // Distribucion fija (no aleatoria) para que los graficos sean reproducibles.
        $singlePlan = [0, 0, 0, 0, 1, 1, 1, 2, 3];              // 9 completadas
        $multiplePlan = [
            [0, 1], [0], [0, 2], [1, 2], [0, 1, 3],
            [2], [0], [1], [0, 2],
        ];
        $comments = [
            'Mas ejercicios practicos y menos teoria.',
            'El tiempo se quedo corto para la ultima parte.',
            null,
            'Los materiales llegaron tarde al correo.',
            null,
            'Todo bien, gracias.',
            null,
            'Repetir la dinamica de grupos.',
            null,
        ];

        // --- 9 sesiones que completaron (5 siguen conectadas, 4 ya expiraron) --
        foreach ($singlePlan as $i => $singleChoiceIndex) {
            $stillConnected = $i < 5;

            $session = ParticipantSession::create([
                'survey_id' => $survey->id,
                'token_hash' => ParticipantSession::hashToken("demo-completed-{$i}"),
                'submission_state' => ParticipantSession::STATE_COMPLETED,
                'first_seen_at' => now()->subMinutes(50 - $i * 3),
                'last_seen_at' => $stillConnected
                    ? now()->subSeconds(5)
                    : now()->subMinutes(20),
                'completed_at' => now()->subMinutes(40 - $i * 3),
            ]);

            $response = Response::create([
                'survey_id' => $survey->id,
                'participant_session_id' => $session->id,
                'submission_uuid' => sprintf('00000000-0000-4000-8000-%012d', $i + 1),
                'survey_version_number' => $survey->version_number,
                'completed_at' => $session->completed_at,
            ]);

            $a1 = ResponseAnswer::create([
                'response_id' => $response->id,
                'question_id' => $single->id,
            ]);
            ResponseSelection::create([
                'response_answer_id' => $a1->id,
                'question_option_id' => $singleOptions[$singleChoiceIndex],
            ]);

            $a2 = ResponseAnswer::create([
                'response_id' => $response->id,
                'question_id' => $multiple->id,
            ]);
            foreach ($multiplePlan[$i] as $optionIndex) {
                ResponseSelection::create([
                    'response_answer_id' => $a2->id,
                    'question_option_id' => $multipleOptions[$optionIndex],
                ]);
            }

            // RN-13: la pregunta opcional sin responder NO genera fila.
            if ($comments[$i] !== null) {
                ResponseAnswer::create([
                    'response_id' => $response->id,
                    'question_id' => $freeText->id,
                    'text_answer' => $comments[$i],
                ]);
            }
        }

        // --- 3 sesiones en progreso y conectadas => `pendientes` = 3 -----------
        for ($i = 0; $i < 3; $i++) {
            ParticipantSession::create([
                'survey_id' => $survey->id,
                'token_hash' => ParticipantSession::hashToken("demo-pending-{$i}"),
                'submission_state' => ParticipantSession::STATE_IN_PROGRESS,
                'first_seen_at' => now()->subMinutes(6),
                'last_seen_at' => now()->subSeconds(8),
            ]);
        }

        // --- 2 sesiones que abrieron y se fueron sin interactuar ---------------
        for ($i = 0; $i < 2; $i++) {
            ParticipantSession::create([
                'survey_id' => $survey->id,
                'token_hash' => ParticipantSession::hashToken("demo-abandoned-{$i}"),
                'submission_state' => ParticipantSession::STATE_STARTED,
                'first_seen_at' => now()->subMinutes(35),
                'last_seen_at' => now()->subMinutes(34),
            ]);
        }
    }

    /**
     * Encuesta cerrada con su instantanea de resumen (RN-02.5), para que el
     * historico se pueda probar incluso tras purgar metadatos de sesion.
     */
    private function createClosedDemoSurvey(User $admin): void
    {
        $survey = Survey::create([
            'public_id' => self::DEMO_CLOSED_PUBLIC_ID,
            'title' => '[DEMO] Ronda anterior (cerrada)',
            'description' => 'Encuesta cerrada de demostracion.',
            'status' => Survey::STATUS_CLOSED,
            'version_number' => 1,
            'created_by_user_id' => $admin->id,
            'starts_at' => now()->subDays(8),
            'ends_at' => now()->subDays(7),
            'published_at' => now()->subDays(8),
            'closed_at' => now()->subDays(7),
            'closed_by_user_id' => $admin->id,
            'snapshot_sessions_started' => 41,
            'snapshot_participated' => 36,
            'snapshot_completed' => 33,
            'snapshot_response_rate' => 80.5,   // 33/41 (D-07)
        ]);

        $question = Question::create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_SINGLE_CHOICE,
            'prompt' => 'Recomendaria esta capacitacion?',
            'is_required' => true,
            'position' => 1,
        ]);
        $this->options($question, ['Si', 'No']);

        AdminAuditEvent::create([
            'user_id' => $admin->id,
            'survey_id' => $survey->id,
            'survey_title_snapshot' => $survey->title,
            'action' => AdminAuditEvent::ACTION_CLOSED,
            'metadata' => ['source' => 'DemoSurveySeeder', 'reason' => 'manual'],
            'occurred_at' => now()->subDays(7),
        ]);
    }

    /** Borrador: sin public_id (ADR-0006) y, por tanto, sin URL publica. */
    private function createDraftDemoSurvey(User $admin): void
    {
        $survey = Survey::create([
            'public_id' => null,
            'title' => self::DEMO_DRAFT_TITLE,
            'description' => 'Sirve para probar la validacion de publicabilidad (RN-01).',
            'status' => Survey::STATUS_DRAFT,
            'version_number' => 1,
            'created_by_user_id' => $admin->id,
        ]);

        Question::create([
            'survey_id' => $survey->id,
            'type' => Question::TYPE_MULTIPLE_CHOICE,
            'prompt' => 'Pregunta incompleta: solo tiene una opcion (RN-01.4 la rechaza).',
            'is_required' => true,
            'position' => 1,
            'min_selections' => 1,
            'max_selections' => 1,
        ]);
    }

    /**
     * ISLA B. Se siembra a proposito para que appsec y QA puedan comprobar que,
     * aun existiendo registros de seguridad de la MISMA encuesta y del MISMO
     * minuto, NO hay forma de escribir una consulta que una una respuesta con una
     * IP: no existe columna ni clave foranea que lo permita.
     */
    private function createSecurityIsland(Survey $survey): void
    {
        $rows = [
            [SecurityEvent::TYPE_RATE_LIMITED, '192.0.2.17', 429, 'POST /s/{publicId}/responses'],
            [SecurityEvent::TYPE_RATE_LIMITED, '192.0.2.17', 429, 'POST /s/{publicId}/responses'],
            [SecurityEvent::TYPE_INVALID_PUBLIC_ID, '192.0.2.88', 404, 'GET /s/{publicId}'],
            [SecurityEvent::TYPE_AUTH_FAILED, '198.51.100.5', 422, 'POST /login'],
        ];

        foreach ($rows as $i => [$type, $ip, $status, $route]) {
            SecurityEvent::create([
                'occurred_at' => now()->subMinutes(10 + $i),   // el modelo trunca al minuto
                'event_type' => $type,
                'ip_address' => $ip,
                'route' => $route,
                'http_status' => $status,
                'reason' => 'demo_seed',
                'correlation_id' => sprintf('11111111-1111-4111-8111-%012d', $i + 1),
                'user_id' => null,
                // Entero suelto, sin FK. Riesgo residual declarado en ARCHITECTURE §7.3.
                'survey_id' => $type === SecurityEvent::TYPE_AUTH_FAILED ? null : $survey->id,
            ]);
        }
    }

    /** @param  array<int,string>  $labels */
    private function options(Question $question, array $labels): void
    {
        foreach ($labels as $index => $label) {
            QuestionOption::create([
                'question_id' => $question->id,
                'label' => $label,
                'position' => $index + 1,
            ]);
        }
    }
}
