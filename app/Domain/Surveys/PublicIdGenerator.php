<?php

namespace App\Domain\Surveys;

use App\Models\Survey;

/**
 * ADR-0006 — identificador publico de 16 caracteres Base32 Crockford.
 *
 * 16 caracteres x 5 bits = 80 bits de entropia desde un CSPRNG. No es una
 * ofuscacion reversible del id interno (eso descarto `sqids`, ADR-0006 opcion C)
 * ni lleva marca de tiempo en claro (eso descarto ULID, opcion D): es
 * aleatoriedad pura, de modo que conocer un identificador no permite deducir
 * otro ni acotar el espacio de busqueda.
 *
 * Alfabeto Crockford: SIN I, L, O ni U. El identificador se imprime en un QR y
 * a veces se dicta en voz alta o se teclea a mano cuando la camara no enfoca,
 * asi que los pares confundibles (0/O, 1/I/l) se eliminan del alfabeto.
 */
class PublicIdGenerator
{
    /** Base32 Crockford. 32 simbolos = 5 bits exactos por caracter. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = Survey::PUBLIC_ID_LENGTH;

    /**
     * Genera un identificador libre, reintentando ante colision.
     *
     * La colision accidental es practicamente imposible (con 10^6 encuestas la
     * probabilidad es ~4x10^-13), pero el bucle contra el indice unico la
     * elimina del todo en lugar de confiar en la estadistica.
     */
    public function generate(): string
    {
        do {
            $candidate = $this->random();
        } while (Survey::query()->where('public_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * Un identificador aleatorio, sin comprobar unicidad.
     *
     * Se toma 1 byte del CSPRNG por caracter y se usan sus 5 bits bajos. Eso
     * desperdicia 3 bits por byte, pero mantiene la distribucion perfectamente
     * uniforme: un `% 32` sobre un alfabeto que no divide a 256 introduciria
     * sesgo modular. Aqui 256 = 8 x 32, asi que `& 31` es uniforme.
     */
    public function random(): string
    {
        $bytes = random_bytes(self::LENGTH);
        $id = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $id .= self::ALPHABET[ord($bytes[$i]) & 31];
        }

        return $id;
    }

    /** Validacion de forma antes de tocar la base de datos. */
    public static function isWellFormed(string $candidate): bool
    {
        return (bool) preg_match('/^' . Survey::PUBLIC_ID_PATTERN . '$/', $candidate);
    }
}
