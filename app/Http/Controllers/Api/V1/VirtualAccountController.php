<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VirtualAccount;
use App\Models\Worker;
use App\Services\SquadPaymentService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Virtual Accounts', description: 'Squad virtual accounts for workers')]
class VirtualAccountController extends Controller
{
    public function __construct(private SquadPaymentService $squad, private AuditService $audit) {}

    #[OA\Get(
        path: '/virtual-accounts',
        operationId: 'virtualAccountIndex',
        tags: ['Virtual Accounts'],
        summary: 'List all virtual accounts',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated virtual accounts')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(VirtualAccount::with('worker')->paginate(25));
    }

    #[OA\Get(
        path: '/virtual-accounts/{id}',
        operationId: 'virtualAccountShow',
        tags: ['Virtual Accounts'],
        summary: 'Get a single virtual account',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Virtual account detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return $this->successResponse(VirtualAccount::with('worker')->findOrFail($id));
    }

    #[OA\Post(
        path: '/virtual-accounts',
        operationId: 'virtualAccountStore',
        tags: ['Virtual Accounts'],
        summary: 'Create a Squad virtual account for a worker',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['worker_id'],
                properties: [new OA\Property(property: 'worker_id', type: 'integer', example: 1)]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Virtual account created via Squad API'),
            new OA\Response(response: 422, description: 'Squad API error or validation failure'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data   = $request->validate(['worker_id' => 'required|exists:workers,id']);
        $worker = Worker::findOrFail($data['worker_id']);

        $result = $this->squad->createVirtualAccount([
            'customer_identifier' => $worker->ippis_id,
            'first_name'          => explode(' ', $worker->full_name)[0] ?? 'Worker',
            'last_name'           => explode(' ', $worker->full_name)[1] ?? '',
            'mobile_num'          => $worker->phone ?? '08000000000',
            'bvn'                 => $worker->bvn ?? '',
            'dob'                  => $worker->date_of_birth?->format('m/d/Y') ?? '01/01/1990',
            'address'              => $worker->home_address ?? 'N/A',
            'gender'               => $worker->gender == 'male' ? '1' : ($worker->gender == 'female' ? '2' : 'N/A'),
            'email'                => $worker->email ?? 'N/A',
            "beneficiary_account"  => $worker->bank_account ?? "4920299492"
        ]);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 422);
        }

        $account = VirtualAccount::create([
            'worker_id'      => $worker->id,
            'account_number' => $result['account_number'],
            'bank_name'      => $result['bank_name'],
            'provider'       => 'squad',
            'is_active'      => true,
        ]);

        $this->audit->log('virtual_account_created', 'VirtualAccount', $account->id, [], $data, $request);
        return $this->successResponse($account, 'Virtual account created.', 201);
    }

    #[OA\Delete(
        path: '/virtual-accounts/{id}',
        operationId: 'virtualAccountDestroy',
        tags: ['Virtual Accounts'],
        summary: 'Deactivate a virtual account',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Virtual account deactivated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = VirtualAccount::findOrFail($id);
        $account->update(['is_active' => false]);
        $this->audit->log('virtual_account_deactivated', 'VirtualAccount', $id, [], ['is_active' => false], $request);
        return $this->successResponse([], 'Virtual account deactivated.');
    }
}
