<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/v1/mobile/*')) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Mobile app expects every response in the { success, message, ... }
        // envelope, including errors.
        $this->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/v1/mobile/*')) {
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->first() ?? 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $this->renderable(function (HttpException $e, $request) {
            if ($request->is('api/v1/mobile/*')) {
                $messages = [403 => 'Forbidden.', 404 => 'Not found.'];
                $status = $e->getStatusCode();
                $message = $e->getMessage();

                // Route-model binding raises "No query results for model
                // [App\Models\Thread] 5", which handed the client our internal
                // class names. Deliberate abort(404, '...') messages still pass
                // through.
                if ($message === '' || str_contains($message, 'No query results for model')) {
                    $message = $messages[$status] ?? 'Request failed.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], $status);
            }
        });
    }

    /**
     * Force JSON rendering for any error on an API route, so a client that
     * forgets the "Accept: application/json" header still gets JSON (never an
     * HTML redirect to the web login page).
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        return $request->is('api/*') || parent::shouldReturnJson($request, $e);
    }
}
