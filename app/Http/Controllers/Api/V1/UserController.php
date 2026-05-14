<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Users', description: 'Admin user management')]
class UserController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/users',
        operationId: 'userIndex',
        tags: ['Users'],
        summary: 'List all users',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated user list')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(User::with('mda')->paginate(25));
    }

    #[OA\Post(
        path: '/users',
        operationId: 'userStore',
        tags: ['Users'],
        summary: 'Create a new user',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'role'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Fatima Aliyu'),
                    new OA\Property(property: 'email', type: 'string', example: 'fatima@ippis.gov.ng'),
                    new OA\Property(property: 'password', type: 'string', example: 'secret123'),
                    new OA\Property(property: 'role', type: 'string', enum: ['super_admin', 'mda_admin', 'field_agent', 'payroll_officer']),
                    new OA\Property(property: 'mda_id', type: 'integer', example: 2),
                    new OA\Property(property: 'phone', type: 'string', example: '08012345678'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'User created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:super_admin,mda_admin,field_agent,payroll_officer',
            'mda_id'   => 'nullable|exists:mdas,id',
            'phone'    => 'nullable|string',
        ]);

        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        $this->audit->log('user_created', 'User', $user->id, [], array_except($data, ['password']), $request);
        return $this->successResponse($user->makeHidden('password'), 'User created.', 201);
    }

    #[OA\Get(
        path: '/users/{id}',
        operationId: 'userShow',
        tags: ['Users'],
        summary: 'Get a single user',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'User detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(User::with('mda')->findOrFail($id));
    }

    #[OA\Put(
        path: '/users/{id}',
        operationId: 'userUpdate',
        tags: ['Users'],
        summary: 'Update a user',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
                    new OA\Property(property: 'role', type: 'string', enum: ['super_admin', 'mda_admin', 'field_agent', 'payroll_officer']),
                    new OA\Property(property: 'mda_id', type: 'integer'),
                    new OA\Property(property: 'phone', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'User updated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $old  = $user->toArray();
        $data = $request->validate([
            'name'     => 'sometimes|string',
            'email'    => "sometimes|email|unique:users,email,{$id}",
            'password' => 'sometimes|string|min:8',
            'role'     => 'sometimes|in:super_admin,mda_admin,field_agent,payroll_officer',
            'mda_id'   => 'sometimes|nullable|exists:mdas,id',
            'phone'    => 'sometimes|string',
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);
        $this->audit->log('user_updated', 'User', $id, $old, array_except($data, ['password']), $request);
        return $this->successResponse($user->fresh('mda')->makeHidden('password'));
    }

    #[OA\Delete(
        path: '/users/{id}',
        operationId: 'userDestroy',
        tags: ['Users'],
        summary: 'Delete a user',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'User deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $this->audit->log('user_deleted', 'User', $id, $user->toArray(), [], $request);
        $user->delete();
        return $this->successResponse([], 'User deleted.');
    }
}
