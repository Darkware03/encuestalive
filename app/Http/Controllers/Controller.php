<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base de los controladores.
 *
 * `AuthorizesRequests` habilita `$this->authorize()`, que es como cada accion
 * administrativa vuelve a pasar por SurveyPolicy en el SERVIDOR. Que la
 * interfaz oculte un boton no es control de acceso (API.md §4).
 */
abstract class Controller
{
    use AuthorizesRequests;
}
