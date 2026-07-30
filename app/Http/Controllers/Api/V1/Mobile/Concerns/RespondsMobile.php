<?php

namespace App\Http\Controllers\Api\V1\Mobile\Concerns;

/**
 * Standard mobile response envelope: { success, message, data, [meta|errors] }.
 */
trait RespondsMobile
{
    protected function ok($data = null, string $message = 'OK', int $status = 200, array $extra = [])
    {
        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $extra), $status);
    }

    protected function fail(string $message, int $status = 400, ?array $errors = null)
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Envelope for a paginator: items under data, page info under meta.
     */
    protected function okPaginated($paginator, $items, string $message = 'OK')
    {
        return $this->ok($items, $message, 200, [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
