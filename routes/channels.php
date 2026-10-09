<?php

use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Autorizacion de canales de broadcasting
|--------------------------------------------------------------------------
|
| ============ "ES ADMIN" NO ES SUFICIENTE (CA-12, R-09) ============
| La comprobacion resuelve LA ENCUESTA CONCRETA por su public_id y aplica
| SurveyPolicy::view. Un administrador autenticado NO puede escuchar el
| dashboard de una encuesta ajena: recibe 403 de /broadcasting/auth.
|
| Devolver `true` por el mero hecho de estar autenticado seria la fuga que
| R-09 describe: un segundo admin viendo presencia y texto libre en vivo de
| una encuesta que no es suya.
| ===================================================================
|
| El canal PUBLICO `survey.{publicId}` no se declara aqui a proposito: los
| canales publicos no se autorizan, cualquiera puede suscribirse. Por eso lo
| unico que viaja por el es el ciclo de vida de la encuesta, con un payload
| cerrado de tres claves (API.md §5.4).
|
*/

Broadcast::channel('survey.{publicId}.admin', function (User $user, string $publicId) {
    $survey = Survey::query()->where('public_id', $publicId)->first();

    if ($survey === null) {
        return false;
    }

    return $user->can('view', $survey);
});
