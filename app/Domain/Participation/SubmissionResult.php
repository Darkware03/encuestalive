<?php

namespace App\Domain\Participation;

use App\Models\Response;
use Illuminate\Support\Carbon;

/**
 * Resultado de un envio. Lleva `duplicate` para que la capa HTTP pueda
 * responder 200 con `duplicate: true` en lugar de 409 (API.md §2.5).
 *
 * POR QUE 200 Y NO 409, que es la decision contraintuitiva: el caso real que
 * produce un reenvio no es un abuso, es el participante cuya red fallo DESPUES
 * de que el servidor guardase. Hizo todo bien. Mostrarle un error por algo que
 * si funciono seria culparle de un fallo nuestro. El cliente trata
 * `duplicate: true` exactamente igual que un envio nuevo: pantalla de
 * confirmacion. Eso es CA-07.
 */
final class SubmissionResult
{
    public function __construct(
        public readonly Response $response,
        public readonly bool $duplicate,
        public readonly string $receiptId,
        public readonly Carbon $completedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => 'recorded',
            'receipt_id' => $this->receiptId,
            'completed_at' => $this->completedAt->toIso8601ZuluString(),
            'duplicate' => $this->duplicate,
        ];
    }
}
