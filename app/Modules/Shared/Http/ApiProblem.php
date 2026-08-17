<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Formato de error uniforme, estilo RFC 7807 (ARQUITECTURA_MAESTRA §8).
 *
 * Un solo formato para toda la API significa que la app Flutter y la SPA escriben **un**
 * manejador de errores en lugar de uno por endpoint. Con dos formatos distintos, el cliente
 * acaba mirando si existe la llave `errors` para adivinar qué pasó.
 *
 *     {
 *       "type":   "validation_error",
 *       "title":  "Los datos enviados no son válidos.",
 *       "status": 422,
 *       "detail": "…",
 *       "errors": { "campo": ["mensaje"] }
 *     }
 *
 * `type` es un código estable que el cliente puede comparar; `title` y `detail` son texto
 * para humanos en español y **pueden cambiar** sin ser un cambio de contrato. Esa separación
 * es la razón de ser del formato: sin `type`, el cliente terminaría comparando cadenas
 * traducibles.
 *
 * Lo que NO se filtra al cliente: nombres de permisos (le enumeraría las capacidades del
 * sistema), rutas de archivo y trazas. Eso vive en el log y en la bitácora.
 */
final class ApiProblem
{
    /**
     * Registra el formato en el manejador de excepciones de la aplicación.
     */
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => self::validation($e),
                $e instanceof AuthenticationException => self::make(
                    'unauthenticated',
                    'No has iniciado sesión.',
                    401,
                ),
                // `ModelNotFoundException` se convierte antes en NotFoundHttpException por el
                // binding de rutas, pero se cubren los dos por si alguien usa findOrFail.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => self::make(
                    'not_found',
                    'No se encontró el recurso solicitado.',
                    404,
                ),
                $e instanceof HttpExceptionInterface => self::fromHttpException($e),
                default => null,
            };
        });
    }

    private static function validation(ValidationException $e): JsonResponse
    {
        return self::make(
            type: 'validation_error',
            title: 'Los datos enviados no son válidos.',
            status: 422,
            detail: null,
            errors: $e->errors(),
        );
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $type = match ($status) {
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            422 => 'unprocessable',
            423 => 'locked',
            429 => 'too_many_requests',
            default => 'http_error',
        };

        // El mensaje de una HttpException del proyecto está escrito para el usuario final
        // —los de AuthorizationDenied y PinAuthorizationFailed, por ejemplo—, así que se
        // pasa tal cual. Si viene vacío, se usa un texto genérico en lugar de exponer el
        // mensaje por defecto de Symfony, que está en inglés.
        $message = $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación.';

        return self::make($type, $message, $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function make(
        string $type,
        string $title,
        int $status,
        ?string $detail = null,
        array $errors = [],
    ): JsonResponse {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status);
    }
}
