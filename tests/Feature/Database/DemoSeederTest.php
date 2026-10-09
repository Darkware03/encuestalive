<?php

namespace Tests\Feature\Database;

use App\Models\ParticipantSession;
use App\Models\Question;
use App\Models\Response;
use App\Models\SecurityEvent;
use App\Models\Survey;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSurveySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_runs_without_errors_and_leaves_a_published_survey(): void
    {
        $this->seed(DatabaseSeeder::class);

        $survey = Survey::where('public_id', DemoSurveySeeder::DEMO_ACTIVE_PUBLIC_ID)->first();

        $this->assertNotNull($survey);
        $this->assertSame(Survey::STATUS_ACTIVE, $survey->status);
        $this->assertDatabaseHas('users', ['email' => AdminUserSeeder::EMAIL]);
    }

    /** El brief pide los TRES tipos de pregunta disponibles para frontend y QA. */
    public function test_the_demo_survey_has_the_three_question_types(): void
    {
        $this->seed(DatabaseSeeder::class);

        $types = Survey::where('public_id', DemoSurveySeeder::DEMO_ACTIVE_PUBLIC_ID)
            ->firstOrFail()
            ->questions()
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            Question::TYPE_FREE_TEXT,
            Question::TYPE_MULTIPLE_CHOICE,
            Question::TYPE_SINGLE_CHOICE,
        ], $types);
    }

    /** Idempotencia: dos pasadas dejan exactamente el mismo estado. */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $before = [
            'users' => User::count(),
            'surveys' => Survey::count(),
            'questions' => Question::count(),
            'sessions' => ParticipantSession::count(),
            'responses' => Response::count(),
            'security' => SecurityEvent::count(),
        ];

        $this->seed(DatabaseSeeder::class);

        $after = [
            'users' => User::count(),
            'surveys' => Survey::count(),
            'questions' => Question::count(),
            'sessions' => ParticipantSession::count(),
            'responses' => Response::count(),
            'security' => SecurityEvent::count(),
        ];

        $this->assertSame($before, $after);
    }

    /**
     * Las metricas normativas de PRD §8 cuadran sobre los datos sembrados, y se
     * cumplen las invariantes de §8.7 que QA debe poder comprobar (CA-10).
     */
    public function test_seeded_metrics_satisfy_the_prd_invariants(): void
    {
        $this->seed(DatabaseSeeder::class);

        $survey = Survey::where('public_id', DemoSurveySeeder::DEMO_ACTIVE_PUBLIC_ID)->firstOrFail();
        $sessions = ParticipantSession::forSurvey($survey->id);

        $started = (clone $sessions)->count();
        $participated = (clone $sessions)->participated()->count();
        $completed = (clone $sessions)->completed()->count();
        $connected = (clone $sessions)->connected()->count();
        $pending = (clone $sessions)->pending()->count();
        $connectedCompleted = (clone $sessions)->connected()->completed()->count();

        $this->assertSame(14, $started);
        $this->assertSame(12, $participated);
        $this->assertSame(9, $completed);
        $this->assertSame(8, $connected);
        $this->assertSame(3, $pending);

        // PRD §8.7 — invariantes verificables en cualquier instante.
        $this->assertLessThanOrEqual($participated, $completed);
        $this->assertLessThanOrEqual($started, $participated);
        $this->assertLessThanOrEqual($started, $connected);
        $this->assertSame($connected, $pending + $connectedCompleted);

        // D-07 — tasa de respuesta = completadas / sesiones iniciadas.
        $rate = round($completed / $started * 100, 1);
        $this->assertSame(64.3, $rate);
        $this->assertGreaterThanOrEqual(0, $rate);
        $this->assertLessThanOrEqual(100, $rate);

        // Una fila de `responses` por sesion completada (PRD §8.3, relacion 1:1).
        $this->assertSame($completed, Response::forSurvey($survey->id)->count());
    }

    /** RN-13: una pregunta opcional sin responder NO genera fila de respuesta. */
    public function test_optional_free_text_without_answer_stores_no_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $survey = Survey::where('public_id', DemoSurveySeeder::DEMO_ACTIVE_PUBLIC_ID)->firstOrFail();
        $freeText = $survey->questions()->where('type', Question::TYPE_FREE_TEXT)->firstOrFail();

        $this->assertFalse($freeText->is_required);
        $this->assertSame(5, $freeText->answers()->count());
        $this->assertSame(9, Response::forSurvey($survey->id)->count());
    }

    /** El seeder no siembra ninguna IP real: solo rangos de documentacion. */
    public function test_seeded_ips_are_documentation_ranges_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (SecurityEvent::pluck('ip_address') as $ip) {
            $this->assertTrue(
                str_starts_with($ip, '192.0.2.')      // RFC 5737 TEST-NET-1
                || str_starts_with($ip, '198.51.100.') // RFC 5737 TEST-NET-2
                || str_starts_with($ip, '203.0.113.'), // RFC 5737 TEST-NET-3
                "IP sembrada fuera del rango de documentacion: {$ip}"
            );
        }
    }
}
