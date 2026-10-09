<?php

namespace App\Policies;

use App\Domain\Surveys\SurveyStateMachine;
use App\Models\Survey;
use App\Models\User;

/**
 * Autorizacion por recurso (ARCHITECTURE.md §5.2, CA-12).
 *
 * ============ "ESTA AUTENTICADO" NUNCA ES SUFICIENTE ============
 * Toda accion administrativa pasa por aqui y comprueba la propiedad de LA
 * ENCUESTA CONCRETA. Un administrador no puede exportar, cerrar ni escuchar el
 * canal privado de una encuesta ajena.
 *
 * El MVP tiene un solo rol (D-05 PA-02), asi que hoy la propiedad simple por
 * `created_by_user_id` basta. El punto de extension queda AISLADO en esta
 * clase: el dia que haya equipos o roles, se cambia aqui y en ningun otro
 * sitio, porque ningun controlador comprueba permisos por su cuenta.
 * ================================================================
 */
class SurveyPolicy
{
    public function __construct(
        private readonly SurveyStateMachine $states,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Edicion de la CABECERA (titulo, descripcion, instrucciones).
     *
     * Deliberadamente permitida en cualquier estado: corregir una errata del
     * enunciado de la encuesta con 200 personas respondiendo es seguro y util.
     * Lo que queda congelado al publicar es la ESTRUCTURA, y de eso se ocupa
     * QuestionService::assertEditable(), no esta policy.
     */
    public function update(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    /** Cambios ESTRUCTURALES: solo en borrador o programada. */
    public function updateStructure(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey)
            && $this->states->isStructurallyEditable($survey);
    }

    public function publish(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey) && $this->states->can($survey, 'publish');
    }

    public function pause(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey) && $this->states->can($survey, 'pause');
    }

    public function resume(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey) && $this->states->can($survey, 'resume');
    }

    public function close(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey) && $this->states->can($survey, 'close');
    }

    public function archive(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey) && $this->states->can($survey, 'archive');
    }

    public function duplicate(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    /** Solo borradores sin respuestas; con respuestas se archiva. */
    public function delete(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey)
            && $survey->status === Survey::STATUS_DRAFT
            && ! $survey->responses()->exists();
    }

    /**
     * Exportar: propietario y encuesta que haya llegado a publicarse.
     * Un borrador no tiene nada que exportar (409 en API.md §3.2).
     */
    public function export(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey) && in_array($survey->status, [
            Survey::STATUS_ACTIVE,
            Survey::STATUS_PAUSED,
            Survey::STATUS_CLOSED,
            Survey::STATUS_ARCHIVED,
        ], true);
    }

    /**
     * Descarga del QR: SOLO propiedad.
     *
     * La ausencia de `public_id` NO se comprueba aqui a proposito. Si se
     * comprobara, un borrador propio daria 403 ("no es tuyo") cuando la
     * verdad es 409 ("es tuyo, pero aun no se ha publicado"), que es lo que
     * fija API.md §3.1. Mezclar autorizacion y estado en la misma respuesta
     * confunde a quien integra y oculta el motivo real.
     *
     * El 409 lo emite QrController, que es quien conoce el contrato HTTP.
     */
    public function viewQr(User $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    private function owns(User $user, Survey $survey): bool
    {
        return $survey->created_by_user_id === $user->id;
    }
}
