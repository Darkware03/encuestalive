<?php

namespace App\Domain\Surveys;

use App\Models\Survey;

/**
 * Maquina de estados de la encuesta (PRD RN-02/RN-10, API.md §4.1).
 *
 *   draft ──publish──> active ──pause──> paused ──resume──> active
 *     │                  │                 │
 *     │                  └─────close───────┴──> closed ──archive──> archived
 *     └──schedule──> scheduled ──(llega starts_at)──> active
 *     └──delete──> ∅  (solo sin respuestas)
 *
 * REGLA DURA: esta clase es la UNICA autoridad sobre que transicion es valida,
 * y se invoca en el SERVIDOR en cada accion. Que la interfaz oculte un boton no
 * es control de acceso (API.md §4: "toda accion vuelve a comprobar en servidor").
 *
 * `closed` es IRREVERSIBLE (D-05 PA-04). No existe arista de vuelta a active:
 * la alternativa declarada es duplicar como nueva version. Desarchivar si es
 * posible porque archivar es organizativo, no destructivo (DATABASE.md §11.10)
 * y devuelve exactamente a `closed`, que sigue sin aceptar respuestas.
 */
class SurveyStateMachine
{
    /**
     * Grafo de transiciones permitidas: accion => [origen validos => destino].
     *
     * @var array<string, array{from: array<int, string>, to: string}>
     */
    public const TRANSITIONS = [
        'publish' => [
            'from' => [Survey::STATUS_DRAFT, Survey::STATUS_SCHEDULED],
            'to' => Survey::STATUS_ACTIVE,
        ],
        'schedule' => [
            'from' => [Survey::STATUS_DRAFT],
            'to' => Survey::STATUS_SCHEDULED,
        ],
        // Disparada por el reloj al llegar starts_at (RN-11), no por un humano.
        'activate' => [
            'from' => [Survey::STATUS_SCHEDULED],
            'to' => Survey::STATUS_ACTIVE,
        ],
        'pause' => [
            'from' => [Survey::STATUS_ACTIVE],
            'to' => Survey::STATUS_PAUSED,
        ],
        'resume' => [
            'from' => [Survey::STATUS_PAUSED],
            'to' => Survey::STATUS_ACTIVE,
        ],
        'close' => [
            'from' => [Survey::STATUS_ACTIVE, Survey::STATUS_PAUSED, Survey::STATUS_SCHEDULED],
            'to' => Survey::STATUS_CLOSED,
        ],
        'archive' => [
            'from' => [Survey::STATUS_CLOSED],
            'to' => Survey::STATUS_ARCHIVED,
        ],
        'unarchive' => [
            'from' => [Survey::STATUS_ARCHIVED],
            'to' => Survey::STATUS_CLOSED,
        ],
    ];

    /** Estados que aceptan respuestas de participantes. Solo uno. */
    public const ACCEPTS_RESPONSES = [Survey::STATUS_ACTIVE];

    /** Estados en los que la estructura (preguntas y opciones) es editable. */
    public const STRUCTURALLY_EDITABLE = [Survey::STATUS_DRAFT, Survey::STATUS_SCHEDULED];

    /** Estados visibles en la ruta publica /s/{publicId}; el resto da 404. */
    public const PUBLICLY_VISIBLE = [
        Survey::STATUS_ACTIVE,
        Survey::STATUS_PAUSED,
        Survey::STATUS_CLOSED,
    ];

    public function can(Survey $survey, string $action): bool
    {
        $transition = self::TRANSITIONS[$action] ?? null;

        return $transition !== null
            && in_array($survey->status, $transition['from'], true);
    }

    /**
     * @throws InvalidTransitionException
     */
    public function assertCan(Survey $survey, string $action): void
    {
        $transition = self::TRANSITIONS[$action] ?? null;

        if ($transition === null) {
            throw new InvalidTransitionException(
                $survey->status,
                $action,
                sprintf('La accion "%s" no existe en la maquina de estados.', $action),
            );
        }

        if (! in_array($survey->status, $transition['from'], true)) {
            throw new InvalidTransitionException(
                $survey->status,
                $transition['to'],
                $this->explain($survey->status, $action),
            );
        }
    }

    public function target(string $action): string
    {
        return self::TRANSITIONS[$action]['to']
            ?? throw new InvalidTransitionException('?', $action);
    }

    /** Acciones disponibles AHORA. La interfaz las consume; no las inventa. */
    public function availableActions(Survey $survey): array
    {
        return array_keys(array_filter(
            self::TRANSITIONS,
            fn (array $t) => in_array($survey->status, $t['from'], true),
        ));
    }

    public function acceptsResponses(Survey $survey): bool
    {
        return in_array($survey->status, self::ACCEPTS_RESPONSES, true);
    }

    public function isStructurallyEditable(Survey $survey): bool
    {
        return in_array($survey->status, self::STRUCTURALLY_EDITABLE, true);
    }

    public function isPubliclyVisible(Survey $survey): bool
    {
        return in_array($survey->status, self::PUBLICLY_VISIBLE, true)
            && $survey->public_id !== null;
    }

    /**
     * Mensaje para una persona, no para un registro. Explica POR QUE no se
     * puede y cual es la alternativa, en lugar de "transicion invalida".
     */
    private function explain(string $status, string $action): string
    {
        return match (true) {
            $status === Survey::STATUS_CLOSED && in_array($action, ['publish', 'resume', 'activate'], true)
                => 'Una encuesta cerrada no se puede reabrir. Duplicala como nueva version.',
            $status === Survey::STATUS_ARCHIVED
                => 'Esta encuesta esta archivada. Desarchivala antes de operar sobre ella.',
            $status === Survey::STATUS_DRAFT && $action === 'close'
                => 'Un borrador no se puede cerrar porque nunca estuvo abierto. Eliminalo si no lo necesitas.',
            $status === Survey::STATUS_ACTIVE && $action === 'publish'
                => 'Esta encuesta ya esta publicada.',
            $status === Survey::STATUS_ACTIVE && $action === 'resume'
                => 'Esta encuesta ya esta activa.',
            $status === Survey::STATUS_PAUSED && $action === 'pause'
                => 'Esta encuesta ya esta pausada.',
            default => sprintf(
                'No se puede "%s" una encuesta en estado "%s".',
                $action,
                $status,
            ),
        };
    }
}
