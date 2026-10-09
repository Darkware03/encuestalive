<?php

use App\Http\Middleware\AttachParticipantSession;
use App\Http\Middleware\EnsureSurveyAcceptsResponses;
use App\Http\Middleware\ResolvePublicSurvey;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Cabeceras de seguridad y correlation_id en TODA peticion web,
         * publica y administrativa (ARCHITECTURE.md §5.3).
         */
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'survey.public' => ResolvePublicSurvey::class,
            'survey.participant' => AttachParticipantSession::class,
            'survey.accepts' => EnsureSurveyAcceptsResponses::class,
        ]);

        /*
         * CSRF: NINGUNA ruta esta exenta, incluido el POST publico de
         * respuestas (ARCHITECTURE.md §5.3). No se llama a `validateCsrfTokens`
         * con excepciones a proposito; dejar constancia de la ausencia es
         * mas util que el silencio.
         */
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Una transicion de estado invalida es un CONFLICTO (409), no un error
         * del servidor ni un fallo de validacion: el dato era correcto, lo
         * invalido era el momento (API.md §0.6).
         */
        $exceptions->render(function (
            \App\Domain\Surveys\InvalidTransitionException $e,
            Request $request,
        ) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'correlation_id' => $request->attributes->get('el.correlation_id'),
                ], 409);
            }

            return back()->withErrors(['status' => $e->getMessage()]);
        });
    })->create();
