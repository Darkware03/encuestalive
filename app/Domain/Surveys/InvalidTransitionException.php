<?php

namespace App\Domain\Surveys;

use RuntimeException;

/**
 * Transicion de estado rechazada por la maquina de estados.
 *
 * Se traduce a HTTP 409 (API.md §0.6: "conflicto de estado"), nunca a 500 ni a
 * 422: el dato enviado era valido, lo invalido era el momento.
 */
class InvalidTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'No se puede pasar de "%s" a "%s".',
            $from,
            $to,
        ));
    }

    public function status(): int
    {
        return 409;
    }
}
