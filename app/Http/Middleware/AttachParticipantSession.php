<?php

namespace App\Http\Middleware;

use App\Domain\Participation\ParticipantTokenManager;
use App\Domain\Presence\PresenceService;
use App\Models\ParticipantSession;
use App\Models\Survey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve (o emite) la sesion anonima del participante (ADR-0005).
 *
 * Es el UNICO sitio donde se lee y se escribe la cookie `el_pt_{h}`. Los
 * servicios de dominio reciben el objeto `ParticipantSession` ya resuelto y
 * nunca tocan `request()` ni cookies (ARCHITECTURE.md §2).
 *
 * ===================== SIN CUENTA, SIN NOMBRE, SIN CORREO =====================
 * Aqui no se pide, ni se deduce, ni se almacena nada de la persona. Se crea una
 * fila con un hash aleatorio y dos marcas de tiempo. No se registra la IP: la
 * columna no existe en `participant_sessions` y no debe existir jamas.
 * ==============================================================================
 *
 * La cookie se emite con `Path=/s/{publicId}`, que es el control tecnico que
 * impide el seguimiento entre encuestas: el navegador no la enviara a otra.
 */
class AttachParticipantSession
{
    public const SURVEY_ATTRIBUTE = 'el.survey';
    public const SESSION_ATTRIBUTE = 'el.participant_session';

    public function __construct(
        private readonly ParticipantTokenManager $tokens,
        private readonly PresenceService $presence,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $survey = $request->attributes->get(self::SURVEY_ATTRIBUTE);

        if (! $survey instanceof Survey) {
            return $next($request);
        }

        $publicId = (string) $survey->public_id;
        $cookieName = $this->tokens->cookieName($publicId);
        $plainToken = $request->cookie($cookieName);

        if (! is_string($plainToken) || $plainToken === '') {
            $plainToken = null;
        }

        [$session, $token, $issued] = array_values(
            $this->tokens->issueOrResume($survey, $plainToken)
        );

        /** @var ParticipantSession $session */
        $request->attributes->set(self::SESSION_ATTRIBUTE, $session);
        $request->attributes->set('el.session_issued', $issued);

        /** @var Response $response */
        $response = $next($request);

        /*
         * La cookie se reemite en cada peticion, tambien cuando ya existia.
         * Asi la vigencia de 24 h se renueva mientras la persona sigue
         * participando: en un evento largo, dejar que caduque a mitad de la
         * sesion crearia una sesion nueva, inflaria `sesiones_iniciadas` y
         * dejaria al participante sin su proteccion anti-duplicado.
         */
        $response->headers->setCookie($this->buildCookie($cookieName, $token, $publicId));

        return $response;
    }

    private function buildCookie(string $name, string $value, string $publicId): Cookie
    {
        return Cookie::create(
            name: $name,
            value: $value,
            expire: now()->addMinutes($this->tokens->ttlMinutes())->getTimestamp(),
            // EL control anti-seguimiento entre encuestas.
            path: $this->tokens->cookiePath($publicId),
            domain: config('session.domain'),
            // `Secure` fuera de local. En desarrollo con http:// una cookie
            // Secure no se envia nunca y la participacion dejaria de funcionar.
            secure: app()->environment() !== 'local',
            // JS no puede leer el token: un XSS no lo exfiltra.
            httpOnly: true,
            raw: false,
            // Lax y no None: la encuesta se abre escaneando un QR, que es una
            // navegacion de primer nivel. No hace falta enviarla entre sitios.
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
