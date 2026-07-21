<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
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

        $this->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/v1/mobile/*')) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Mobile app expects every response in the { success, message, ... }
        // envelope, including errors.
        $this->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/v1/mobile/*')) {
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->first() ?? 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $this->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($request->is('api/v1/mobile/*')) {
                $messages = [403 => 'Forbidden.', 404 => 'Not found.'];

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: ($messages[$e->getStatusCode()] ?? 'Request failed.'),
                ], $e->getStatusCode());
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
