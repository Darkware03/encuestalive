<?php

namespace App\Domain\Participation;

use App\Models\ParticipantSession;
use App\Models\Survey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Token opaco de participante, con alcance POR ENCUESTA (ADR-0005).
 *
 * =================== LAS TRES PROPIEDADES QUE IMPORTAN ===================
 *
 * 1. ALCANCE POR ENCUESTA mediante `Path=/s/{publicId}`.
 *    Este es EL control tecnico que impide el seguimiento entre encuestas que
 *    el brief §5.2 prohibe. No es una promesa de buena conducta del servidor:
 *    es el NAVEGADOR quien no envia la cookie a /s/OTRA_ENCUESTA. Dos
 *    encuestas producen dos tokens no relacionables, en el mismo dispositivo
 *    y el mismo dia.
 *
 * 2. HttpOnly. JavaScript no puede leer el token, asi que un XSS no lo
 *    exfiltra y el participante no lo copia con facilidad desde la consola.
 *
 * 3. EN BASE DE DATOS SOLO VIVE EL SHA-256.
 *    Quien obtenga un volcado no puede fabricar cookies validas ni reutilizar
 *    tokens. No lleva `salt` y no lo necesita: no es una contrasena elegida
 *    por una persona, es un valor aleatorio de 256 bits, inatacable por fuerza
 *    bruta o diccionario.
 * =========================================================================
 *
 * El token NUNCA aparece en el cuerpo de una respuesta HTTP, ni en una URL, ni
 * en un log, ni en un evento de broadcasting, ni en el CSV.
 *
 * Esta clase recibe y devuelve valores; NO toca cookies. Escribir la cookie es
 * responsabilidad del middleware, que si vive en la capa HTTP. El dominio no
 * conoce `request()` ni `cookie()` (ARCHITECTURE.md §2).
 */
class ParticipantTokenManager
{
    /**
     * Nombre de la cookie: `el_pt_` + 8 caracteres derivados del public_id.
     *
     * Derivar en lugar de incrustar el `public_id` entero mantiene la cabecera
     * corta y evita que el nombre de la cookie delate por si solo que encuesta
     * se esta respondiendo si alguien mira por encima del hombro.
     */
    public function cookieName(string $publicId): string
    {
        return config('encuestalive.participant.cookie_prefix')
            . substr(hash('sha256', $publicId), 0, 8);
    }

    /** `Path=/s/{publicId}`: el aislamiento entre encuestas, en una linea. */
    public function cookiePath(string $publicId): string
    {
        return '/s/' . $publicId;
    }

    public function ttlMinutes(): int
    {
        return (int) config('encuestalive.participant.token_ttl_minutes');
    }

    /** 256 bits de CSPRNG en Base64 URL-safe sin relleno (43 caracteres). */
    public function generateToken(): string
    {
        $bytes = random_bytes((int) config('encuestalive.participant.token_bytes'));

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function hash(string $plainToken): string
    {
        return ParticipantSession::hashToken($plainToken);
    }

    /**
     * Resuelve la sesion de un token ya emitido. `null` si no existe.
     *
     * La busqueda es por `(survey_id, token_hash)`, que es justamente el indice
     * unico del esquema. Un token valido en otra encuesta NO resuelve aqui, ni
     * siquiera si alguien copiase la cookie a mano.
     */
    public function resolve(Survey $survey, string $plainToken): ?ParticipantSession
    {
        return ParticipantSession::query()
            ->where('survey_id', $survey->id)
            ->where('token_hash', $this->hash($plainToken))
            ->first();
    }

    /**
     * Emite una sesion nueva, o reanuda la existente si el token ya resolvia.
     *
     * Reanudar y no crear es lo que hace que `sesiones_iniciadas` no se infle
     * cuando alguien recarga la pagina diez veces o vuelve tras un corte de
     * red: la cookie resuelve la MISMA fila por el indice unico y la metrica
     * de D-07 sigue significando lo que dice que significa.
     *
     * @return array{session: ParticipantSession, token: string, issued: bool}
     */
    public function issueOrResume(Survey $survey, ?string $plainToken): array
    {
        if ($plainToken !== null && $plainToken !== '') {
            $existing = $this->resolve($survey, $plainToken);

            if ($existing !== null) {
                return ['session' => $existing, 'token' => $plainToken, 'issued' => false];
            }
        }

        $token = $this->generateToken();
        $now = Carbon::now();

        /*
         * `firstOrCreate` sobre el indice unico y no un INSERT a secas: dos
         * peticiones del mismo telefono en paralelo (la carga de la pagina y
         * el primer heartbeat) llegarian a la vez con el mismo token recien
         * emitido, y la segunda reventaria por unicidad.
         */
        $session = DB::transaction(fn () => ParticipantSession::query()->firstOrCreate(
            [
                'survey_id' => $survey->id,
                'token_hash' => $this->hash($token),
            ],
            [
                'submission_state' => ParticipantSession::STATE_STARTED,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        ));

        return ['session' => $session, 'token' => $token, 'issued' => true];
    }
}
