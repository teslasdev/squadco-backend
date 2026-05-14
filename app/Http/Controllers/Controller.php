<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Alive — Ghost Worker Verification API',
    version: '1.0.0',
    description: 'AI proof-of-life verification system for Nigerian government payroll.',
    contact: new OA\Contact(email: 'api@sentinelbloom.com')
)]
#[OA\Server(url: '/api/v1', description: 'API v1')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Token'
)]
abstract class Controller
{
    protected function successResponse(mixed $data = [], string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    protected function errorResponse(string $message = '', int $status = 400, mixed $data = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
}
