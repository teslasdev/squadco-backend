<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Auth', description: 'Authentication endpoints')]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/login',
        operationId: 'authLogin',
        tags: ['Auth'],
        summary: 'Login and receive a Bearer token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'adamu.bello@ippis.gov.ng'),
                    new OA\Property(property: 'password', type: 'string', example: 'secret'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login successful'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
        ]
    )]
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api-token')->plainTextToken;

        return $this->successResponse([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => [
                'id'   => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ], 'Login successful.');
    }

    #[OA\Post(
        path: '/auth/logout',
        operationId: 'authLogout',
        tags: ['Auth'],
        summary: 'Revoke current access token',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Logged out')]
    )]
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse([], 'Logged out successfully.');
    }

    #[OA\Get(
        path: '/auth/me',
        operationId: 'authMe',
        tags: ['Auth'],
        summary: 'Get authenticated user',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Authenticated user object')]
    )]
    public function me(Request $request)
    {
        return $this->successResponse($request->user()->load('mda'));
    }
}
