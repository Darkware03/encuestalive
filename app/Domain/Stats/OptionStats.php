<?php

namespace App\Domain\Stats;

/**
 * Recuento de una opcion. Lleva las DOS bases de porcentaje de D-06 calculadas,
 * porque el CSV debe exportar ambas en columnas separadas y el dashboard debe
 * poder declarar cual esta usando sin recalcular nada por su cuenta.
 */
final class OptionStats
{
    public function __construct(
        public readonly int $optionId,
        public readonly string $label,
        public readonly int $count,
        /** Base A (D-06, por defecto en multiple): 0..n, puede superar 1.0. */
        public readonly float $percentageOfParticipants,
        /** Base B: 0..1, la suma de todas las opciones da exactamente 1.0. */
        public readonly float $percentageOfSelections,
    ) {}

    /** Porcentaje en la base por defecto de la pregunta, con una cifra decimal. */
    public function percentFor(string $basis): float
    {
        $ratio = $basis === QuestionStats::BASIS_PARTICIPANTS
            ? $this->percentageOfParticipants
            : $this->percentageOfSelections;

        return round($ratio * 100, 1);
    }

    public function toBroadcastArray(): array
    {
        return [
            'option_id' => $this->optionId,
            'count' => $this->count,
            'percentage' => round($this->percentageOfParticipants, 3),
        ];
    }
}
