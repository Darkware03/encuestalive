<?php

namespace App\Domain\Surveys;

use App\Domain\Realtime\SafeBroadcaster;
use App\Domain\Stats\StatsService;
use App\Events\SurveyStatusChanged;
use App\Models\AdminAuditEvent;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio de la encuesta: ciclo de vida, estructura y versionado.
 *
 * Cada transicion pasa SIEMPRE por SurveyStateMachine antes de tocar nada. Que
 * la interfaz oculte un boton no es control de acceso: el servidor rechaza la
 * transicion invalida aunque la peticion llegue con `curl`.
 *
 * El servicio no conoce HTTP (ARCHITECTURE.md §2): recibe el `User` como
 * parametro explicito en lugar de leer `auth()`, para que sea probable sin
 * simular una peticion y para que la dependencia quede visible en la firma.
 */
class SurveyService
{
    public function __construct(
        private readonly SurveyStateMachine $states,
        private readonly SurveyValidator $validator,
        private readonly PublicIdGenerator $publicIds,
        private readonly StatsService $stats,
        private readonly SafeBroadcaster $broadcaster,
    ) {}

    // ------------------------------------------------------------------ CRUD

    public function create(User $actor, array $attributes): Survey
    {
        $survey = new Survey([
            'title' => $this->boundedTitle($attributes['title'] ?? ''),
            'description' => $this->boundedOptional($attributes['description'] ?? null, 'survey_description', 'description', 'La descripcion'),
            'instructions' => $this->boundedOptional($attributes['instructions'] ?? null, 'survey_instructions', 'instructions', 'Las instrucciones'),
            'status' => Survey::STATUS_DRAFT,
            'version_number' => 1,
            'created_by_user_id' => $actor->id,
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
        ]);

        $survey->save();

        $this->audit($actor, $survey, AdminAuditEvent::ACTION_CREATED);

        return $survey;
    }

    /**
     * Edicion de la cabecera.
     *
     * POLITICA DE VERSIONADO (brief §4.2, API.md §4.2): la estructura es
     * inmutable una vez publicada. Con la encuesta ya publicada solo se
     * permite corregir titulo, descripcion e instrucciones, que son texto
     * cosmetico y no alteran el significado de ninguna respuesta ya recogida.
     * Las fechas solo se tocan antes de publicar.
     */
    public function update(User $actor, Survey $survey, array $attributes): Survey
    {
        $before = $survey->only(['title', 'description', 'instructions', 'starts_at', 'ends_at']);

        $survey->fill([
            'title' => $this->boundedTitle($attributes['title'] ?? $survey->title),
            'description' => $this->boundedOptional(
                $attributes['description'] ?? $survey->description,
                'survey_description',
                'description',
                'La descripcion',
            ),
            'instructions' => $this->boundedOptional(
                $attributes['instructions'] ?? $survey->instructions,
                'survey_instructions',
                'instructions',
                'Las instrucciones',
            ),
        ]);

        if ($this->states->isStructurallyEditable($survey)) {
            $survey->fill([
                'starts_at' => $attributes['starts_at'] ?? $survey->starts_at,
                'ends_at' => $attributes['ends_at'] ?? $survey->ends_at,
            ]);
        }

        $survey->save();

        // RN-03: el historial "antes/despues" vive en metadata, sin tabla extra.
        $this->audit($actor, $survey, AdminAuditEvent::ACTION_EDITED, [
            'before' => $this->stringifyDates($before),
            'after' => $this->stringifyDates(
                $survey->only(['title', 'description', 'instructions', 'starts_at', 'ends_at'])
            ),
        ]);

        return $survey;
    }

    // -------------------------------------------------------------- publicar

    /**
     * Publicar (draft|scheduled -> active).
     *
     * IDEMPOTENTE: republicar una encuesta ya activa es un no-op que devuelve
     * el MISMO `public_id`. Y el `public_id` NUNCA se regenera, porque ya
     * puede haber QR impresos y repartidos por una sala; regenerarlo los
     * invalidaria todos en silencio (ARCHITECTURE.md §3.1).
     *
     * @throws InvalidTransitionException estado incompatible (409)
     * @throws ValidationException        contenido no publicable (422)
     */
    public function publish(User $actor, Survey $survey): Survey
    {
        if ($survey->status === Survey::STATUS_ACTIVE) {
            return $survey;
        }

        $this->states->assertCan($survey, 'publish');
        $this->assertPublishable($survey);

        DB::transaction(function () use ($survey, $actor): void {
            if ($survey->public_id === null) {
                $survey->public_id = $this->publicIds->generate();
            }

            $survey->status = Survey::STATUS_ACTIVE;
            $survey->published_at ??= Carbon::now();

            if ($survey->starts_at === null) {
                $survey->starts_at = Carbon::now();
            }

            $survey->save();

            $this->audit($actor, $survey, AdminAuditEvent::ACTION_PUBLISHED, [
                'version_number' => $survey->version_number,
            ]);
        });

        $this->announce($survey);

        return $survey->refresh();
    }

    /** Programar (draft -> scheduled). RN-01.7: ademas `inicio > ahora`. */
    public function schedule(User $actor, Survey $survey): Survey
    {
        $this->states->assertCan($survey, 'schedule');
        $this->assertPublishable($survey);

        if ($survey->starts_at === null || $survey->starts_at->isPast()) {
            throw ValidationException::withMessages([
                'dates' => 'Para programar, la fecha de inicio debe estar en el futuro.',
            ]);
        }

        DB::transaction(function () use ($survey, $actor): void {
            $survey->public_id ??= $this->publicIds->generate();
            $survey->status = Survey::STATUS_SCHEDULED;
            $survey->save();

            $this->audit($actor, $survey, AdminAuditEvent::ACTION_SCHEDULED);
        });

        // Sin anuncio publico: `scheduled` no es un estado observable
        // (API.md §5.4). Anunciarlo revelaria una encuesta aun no abierta.

        return $survey->refresh();
    }

    public function pause(User $actor, Survey $survey): Survey
    {
        $this->states->assertCan($survey, 'pause');

        $survey->status = Survey::STATUS_PAUSED;
        $survey->save();

        $this->audit($actor, $survey, AdminAuditEvent::ACTION_PAUSED);
        $this->announce($survey);

        return $survey;
    }

    public function resume(User $actor, Survey $survey): Survey
    {
        $this->states->assertCan($survey, 'resume');

        // RN-11: reanudar dentro de la ventana. Reanudar una encuesta ya
        // vencida la dejaria "activa" sin aceptar respuestas, que es el peor
        // estado posible: la interfaz diria una cosa y el servidor otra.
        if ($survey->ends_at !== null && $survey->ends_at->isPast()) {
            throw ValidationException::withMessages([
                'dates' => 'La fecha de fin ya paso. Amplia la ventana o cierra la encuesta.',
            ]);
        }

        $survey->status = Survey::STATUS_ACTIVE;
        $survey->save();

        $this->audit($actor, $survey, AdminAuditEvent::ACTION_RESUMED);
        $this->announce($survey);

        return $survey;
    }

    /**
     * Cerrar. IRREVERSIBLE (D-05 PA-04).
     *
     * Antes de nada escribe `snapshot_*` (RN-02.5): congela las metricas
     * finales para que el historico siga siendo estable cuando la purga de
     * RN-09.5 se lleve las sesiones dentro de 30 dias. Sin esa instantanea, la
     * tasa de respuesta de una encuesta antigua pasaria de 64,3 % a indefinida
     * el dia de la purga, porque desapareceria el denominador.
     */
    public function close(User $actor, Survey $survey): Survey
    {
        $this->states->assertCan($survey, 'close');

        DB::transaction(function () use ($survey, $actor): void {
            $this->stats->writeClosingSnapshot($survey);

            $survey->status = Survey::STATUS_CLOSED;
            $survey->closed_at = Carbon::now();
            $survey->closed_by_user_id = $actor->id;
            $survey->save();

            $this->audit($actor, $survey, AdminAuditEvent::ACTION_CLOSED, [
                'snapshot_completed' => $survey->snapshot_completed,
                'snapshot_response_rate' => $survey->snapshot_response_rate,
            ]);
        });

        $this->announce($survey);

        return $survey->refresh();
    }

    /** Archivar: organizativo, NUNCA destructivo. No se toca una sola respuesta. */
    public function archive(User $actor, Survey $survey): Survey
    {
        $this->states->assertCan($survey, 'archive');

        $survey->status = Survey::STATUS_ARCHIVED;
        $survey->archived_at = Carbon::now();
        $survey->save();

        $this->audit($actor, $survey, AdminAuditEvent::ACTION_ARCHIVED);

        // Sin anuncio: `archived` no es un estado publicamente observable.
        // La encuesta ya estaba cerrada y los participantes ya lo saben.

        return $survey;
    }

    public function unarchive(User $actor, Survey $survey): Survey
    {
        $this->states->assertCan($survey, 'unarchive');

        $survey->status = Survey::STATUS_CLOSED;
        $survey->archived_at = null;
        $survey->save();

        $this->audit($actor, $survey, AdminAuditEvent::ACTION_UNARCHIVED);

        return $survey;
    }

    /**
     * Duplicar como NUEVA VERSION (RN-03).
     *
     * Es la alternativa declarada a la edicion destructiva: en vez de cambiar
     * preguntas de una encuesta publicada —lo que obligaria a versionar cada
     * pregunta y a decidir como agregar respuestas de versiones distintas— se
     * crea un borrador nuevo encadenado por `parent_survey_id`.
     *
     * La copia lleva preguntas y opciones. NO lleva `public_id` (el QR antiguo
     * debe seguir apuntando a la encuesta antigua), NO lleva respuestas y NO
     * lleva sesiones.
     */
    public function duplicate(User $actor, Survey $survey, ?string $title = null): Survey
    {
        return DB::transaction(function () use ($actor, $survey, $title): Survey {
            $copy = new Survey([
                // F-05: un titulo explicito tambien pasa por el limite. El
                // generado por nextVersionTitle ya viene recortado.
                'title' => $title !== null
                    ? $this->boundedTitle($title)
                    : $this->nextVersionTitle($survey),
                'description' => $survey->description,
                'instructions' => $survey->instructions,
                'status' => Survey::STATUS_DRAFT,
                'version_number' => $survey->version_number + 1,
                'parent_survey_id' => $survey->id,
                'created_by_user_id' => $actor->id,
            ]);

            // public_id se deja NULO a proposito: un borrador no tiene URL
            // publica que filtrar, y se le asignara una propia al publicarse.
            $copy->save();

            foreach ($survey->questions()->with('options')->get() as $question) {
                $newQuestion = Question::create([
                    'survey_id' => $copy->id,
                    'type' => $question->type,
                    'prompt' => $question->prompt,
                    'is_required' => $question->is_required,
                    'position' => $question->position,
                    'min_selections' => $question->min_selections,
                    'max_selections' => $question->max_selections,
                    'min_length' => $question->min_length,
                    'max_length' => $question->max_length,
                ]);

                foreach ($question->options as $option) {
                    QuestionOption::create([
                        'question_id' => $newQuestion->id,
                        'label' => $option->label,
                        'position' => $option->position,
                    ]);
                }
            }

            $this->audit($actor, $copy, AdminAuditEvent::ACTION_DUPLICATED, [
                'source_survey_id' => $survey->id,
                'source_version' => $survey->version_number,
            ]);

            return $copy;
        });
    }

    /**
     * Eliminar. SOLO borradores y SOLO sin respuestas.
     *
     * Con respuestas se archiva, nunca se borra: la eliminacion real es un
     * CASCADE que se llevaria por delante datos de participantes que
     * colaboraron de buena fe.
     */
    public function delete(User $actor, Survey $survey): void
    {
        if ($survey->status !== Survey::STATUS_DRAFT) {
            throw new InvalidTransitionException(
                $survey->status,
                'deleted',
                'Solo se puede eliminar un borrador. Si ya se publico, archivala.',
            );
        }

        if ($survey->responses()->exists()) {
            throw new InvalidTransitionException(
                $survey->status,
                'deleted',
                'Esta encuesta tiene respuestas. Archivala en lugar de eliminarla.',
            );
        }

        $title = $survey->title;
        $surveyId = $survey->id;
        $survey->delete();

        // El rastro sobrevive al borrado: survey_id es SET NULL y el titulo
        // queda congelado en survey_title_snapshot (DATABASE.md §2.8).
        AdminAuditEvent::create([
            'user_id' => $actor->id,
            'survey_id' => null,
            'survey_title_snapshot' => $title,
            'action' => 'survey.deleted',
            'metadata' => ['deleted_survey_id' => $surveyId],
            'occurred_at' => Carbon::now(),
        ]);
    }

    // ------------------------------------------------------------- RN-11

    /**
     * Cierre perezoso por vencimiento de la ventana temporal (RN-11).
     *
     * Se invoca desde la resolucion de la encuesta publica y desde el comando
     * programado. No hay demonio de relojes: una encuesta vencida se cierra la
     * primera vez que alguien la mira, y mientras tanto
     * EnsureSurveyAcceptsResponses ya la rechaza por fecha. El estado efectivo
     * nunca depende de que un proceso en segundo plano haya corrido.
     */
    public function closeIfExpired(Survey $survey): Survey
    {
        if ($survey->ends_at === null || ! $survey->ends_at->isPast()) {
            return $survey;
        }

        if (! in_array($survey->status, [Survey::STATUS_ACTIVE, Survey::STATUS_PAUSED, Survey::STATUS_SCHEDULED], true)) {
            return $survey;
        }

        DB::transaction(function () use ($survey): void {
            $this->stats->writeClosingSnapshot($survey);

            $survey->status = Survey::STATUS_CLOSED;
            $survey->closed_at = $survey->ends_at;
            $survey->save();

            AdminAuditEvent::create([
                'user_id' => null,
                'survey_id' => $survey->id,
                'survey_title_snapshot' => $survey->title,
                'action' => AdminAuditEvent::ACTION_CLOSED,
                'metadata' => ['reason' => 'ends_at_reached'],
                'occurred_at' => Carbon::now(),
            ]);
        });

        $this->announce($survey);

        return $survey->refresh();
    }

    /** Activacion perezosa de una encuesta programada cuyo inicio ya llego. */
    public function activateIfDue(Survey $survey): Survey
    {
        if ($survey->status !== Survey::STATUS_SCHEDULED) {
            return $survey;
        }

        if ($survey->starts_at === null || $survey->starts_at->isFuture()) {
            return $survey;
        }

        $survey->status = Survey::STATUS_ACTIVE;
        $survey->published_at ??= Carbon::now();
        $survey->save();

        $this->announce($survey);

        return $survey;
    }

    // ----------------------------------------------------------------- apoyo

    /** @throws ValidationException */
    private function assertPublishable(Survey $survey): void
    {
        $errors = $this->validator->publicationErrors($survey);

        if ($errors !== []) {
            // RN-01: rechazo COMPLETO y atomico, con todos los errores a la vez.
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Anuncio del cambio de estado por el canal PUBLICO.
     *
     * Dos salvaguardas:
     *   1. Solo estados publicamente observables (nunca draft/scheduled/archived).
     *   2. SafeBroadcaster: si Reverb esta caido, se registra y se sigue. El
     *      cambio de estado YA esta persistido; que no se pueda anunciar no
     *      puede deshacerlo.
     */
    private function announce(Survey $survey): void
    {
        if ($survey->public_id === null) {
            return;
        }

        if (! SurveyStatusChanged::isBroadcastableStatus($survey->status)) {
            return;
        }

        $this->broadcaster->dispatch(SurveyStatusChanged::fromSurvey($survey));
    }

    private function audit(User $actor, Survey $survey, string $action, array $metadata = []): void
    {
        AdminAuditEvent::create([
            'user_id' => $actor->id,
            'survey_id' => $survey->id,
            'survey_title_snapshot' => $survey->title,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * F-05 (MEDIO, Fase 7) — EL CAMINO DE SERVICIO NO VALIDABA LONGITUDES.
     *
     * ==================================================================
     *  EL HUECO
     * ==================================================================
     * `surveys.title` es VARCHAR(150). La longitud se validaba en DOS sitios,
     * y ninguno cubria este camino:
     *
     *   · SurveyEditor (Livewire)        -> solo si se pasa por la interfaz web
     *   · SurveyValidator::publication() -> solo AL PUBLICAR
     *
     * `SurveyService::create()/update()` se limitaban a `trim()`. El auditor
     * metio 10 000 caracteres por create() y 20 000 por update(), y ambos se
     * persistieron intactos.
     *
     * SQLite no aplica longitudes de VARCHAR, asi que en desarrollo no pasa
     * nada. Pero D-04 fija MySQL 8 como destino: alli el mismo INSERT FALLA
     * con error 1406, o —peor— TRUNCA EN SILENCIO si alguien desactiva
     * STRICT_TRANS_TABLES. Es una bomba de relojeria de despliegue: una
     * operacion que funciona en desarrollo revienta o corrompe el dato en
     * produccion. Exactamente lo que DATABASE.md §10.1 queria evitar.
     *
     * La correccion lleva el limite al SERVICIO, que es la frontera real del
     * dominio, con el mismo patron que QuestionService::boundedText() ya
     * establecia para `prompt` y `label`. Asi la regla deja de depender de
     * que capa invoque la operacion.
     *
     * NO se valida aqui la longitud MINIMA ni la obligatoriedad: un borrador
     * recien creado puede tener el titulo a medias, y RN-01 ya exige 3
     * caracteres AL PUBLICAR (SurveyValidator). Mover eso aqui cambiaria el
     * ciclo de vida del producto, y la correccion debe ser la minima.
     *
     * LIMITACION DECLARADA: no hay MySQL 8 en este host, asi que el fallo
     * 1406 que esto previene no se ha podido reproducir. Lo verificado es que
     * el servicio ahora rechaza el texto que desbordaria la columna.
     *
     * @throws ValidationException si el texto supera el limite declarado
     */
    private function boundedTitle(mixed $value): string
    {
        $text = trim((string) $value);
        $max = (int) config('encuestalive.limits.survey_title');

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                'title' => sprintf('El titulo no puede superar los %d caracteres.', $max),
            ]);
        }

        return $text;
    }

    /**
     * Igual que lo anterior para los campos opcionales.
     *
     * `description` e `instructions` son TEXT, no VARCHAR, asi que no
     * desbordan la columna en MySQL. Se acotan igualmente porque
     * `config/encuestalive.php` DECLARA un limite para ellos y la interfaz web
     * ya lo aplica: que el mismo dato se acepte o se rechace segun por donde
     * entre es el defecto de fondo de F-05, no el numero concreto.
     *
     * El `null` se conserva como `null`: no se convierte en cadena vacia, que
     * seria un cambio de semantica en la base de datos.
     *
     * @throws ValidationException
     */
    private function boundedOptional(mixed $value, string $limitKey, string $field, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        $max = (int) config('encuestalive.limits.'.$limitKey);

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([
                $field => sprintf('%s no puede superar los %d caracteres.', $label, $max),
            ]);
        }

        return $text;
    }

    private function nextVersionTitle(Survey $survey): string
    {
        $base = preg_replace('/\s+\(v\d+\)$/u', '', (string) $survey->title);
        $title = sprintf('%s (v%d)', $base, $survey->version_number + 1);

        // El titulo no puede desbordar la columna: SQLite no lo impediria aqui
        // pero MySQL 8 si, y ese es el motor de destino declarado (D-04).
        return mb_substr($title, 0, (int) config('encuestalive.limits.survey_title'));
    }

    private function stringifyDates(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $values[$key] = Carbon::instance($value)->toIso8601ZuluString();
            }
        }

        return $values;
    }
}
