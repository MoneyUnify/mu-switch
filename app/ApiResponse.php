<?php

namespace App;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success($message = 'Success', $data = [], $statusCode = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    public static function error($message = 'Error', $statusCode = 500, $data = []): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Build a response whose top-level `status` reflects an actual outcome
     * (e.g. a transaction's `success` / `failed` / `pending` state) rather than
     * just whether the API call worked. The HTTP code stays 2xx because the
     * request itself succeeded — the body conveys the business outcome.
     */
    public static function status(string $status, string $message, array $data = [], int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
}
