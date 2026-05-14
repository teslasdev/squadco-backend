<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WorkerEnrolledEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Onboarding\Step1Request;
use App\Http\Requests\Api\V1\Onboarding\Step2Request;
use App\Http\Requests\Api\V1\Onboarding\Step3Request;
use App\Http\Requests\Api\V1\Onboarding\Step4Request;
use App\Http\Requests\Api\V1\Onboarding\Step5Request;
use App\Models\VirtualAccount;
use App\Models\Worker;
use App\Services\AuditService;
use App\Services\SquadPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Worker Onboarding', description: 'Multi-step worker onboarding flow')]
class OnboardingController extends Controller
{
    public function __construct(
        private SquadPaymentService $squad,
        private AuditService $audit
    ) {}

    // ─── Step 1: Employment details ───────────────────────────────────────────

    #[OA\Post(
        path: '/onboarding/step1',
        operationId: 'onboardingStep1',
        tags: ['Worker Onboarding'],
        summary: 'Step 1 — Employment details: creates a draft worker record',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['full_name', 'date_of_birth', 'gender', 'ippis_id', 'mda_id', 'department_id',
                            'job_title', 'grade_level', 'step', 'employment_date', 'employment_type',
                            'state_of_posting', 'lga', 'office_address'],
                properties: [
                    new OA\Property(property: 'full_name',        type: 'string',  example: 'Adamu Bello'),
                    new OA\Property(property: 'date_of_birth',    type: 'string',  format: 'date', example: '1985-03-15'),
                    new OA\Property(property: 'gender',           type: 'string',  enum: ['male', 'female']),
                    new OA\Property(property: 'ippis_id',         type: 'string',  example: 'IPPIS-001'),
                    new OA\Property(property: 'mda_id',           type: 'integer', example: 1),
                    new OA\Property(property: 'department_id',    type: 'integer', example: 2),
                    new OA\Property(property: 'job_title',        type: 'string',  example: 'Senior Accountant'),
                    new OA\Property(property: 'grade_level',      type: 'integer', example: 10),
                    new OA\Property(property: 'step',             type: 'integer', example: 3),
                    new OA\Property(property: 'employment_date',  type: 'string',  format: 'date', example: '2010-06-01'),
                    new OA\Property(property: 'employment_type',  type: 'string',  enum: ['permanent', 'contract', 'secondment', 'casual']),
                    new OA\Property(property: 'state_of_posting', type: 'string',  example: 'Lagos'),
                    new OA\Property(property: 'lga',              type: 'string',  example: 'Ikeja'),
                    new OA\Property(property: 'office_address',   type: 'string',  example: '1 Treasury Road, Lagos'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Draft worker created — returns worker_id and onboarding_token'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function step1(Step1Request $request): JsonResponse
    {
        $data = $request->validated();
        $data['status']            = 'draft';
        $data['onboarding_status'] = 'step1';
        $data['onboarding_token']  = (string) Str::uuid();

        $worker = Worker::create($data);

        $this->audit->log('onboarding_step1', 'Worker', $worker->id, [], $data, $request);

        return $this->successResponse([
            'worker_id'         => $worker->id,
            'onboarding_token'  => $worker->onboarding_token,
            'onboarding_status' => $worker->onboarding_status,
        ], 'Step 1 completed.', 201);
    }

    // ─── Step 2: Personal / identity details ─────────────────────────────────

    #[OA\Put(
        path: '/onboarding/{worker_id}/step2',
        operationId: 'onboardingStep2',
        tags: ['Worker Onboarding'],
        summary: 'Step 2 — Personal / identity details',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['nin', 'bvn', 'phone', 'email', 'home_address',
                            'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship'],
                properties: [
                    new OA\Property(property: 'nin',                      type: 'string', example: '12345678901'),
                    new OA\Property(property: 'bvn',                      type: 'string', example: '12345678901'),
                    new OA\Property(property: 'phone',                    type: 'string', example: '08012345678'),
                    new OA\Property(property: 'email',                    type: 'string', example: 'worker@gov.ng'),
                    new OA\Property(property: 'home_address',             type: 'string', example: '5 Bode Thomas St, Lagos'),
                    new OA\Property(property: 'next_of_kin_name',         type: 'string', example: 'Fatima Bello'),
                    new OA\Property(property: 'next_of_kin_phone',        type: 'string', example: '08098765432'),
                    new OA\Property(property: 'next_of_kin_relationship', type: 'string', example: 'Spouse'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Step 2 completed'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function step2(Step2Request $request, int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);
        $data   = $request->validated();
        $data['onboarding_status'] = 'step2';

        $worker->update($data);

        $this->audit->log('onboarding_step2', 'Worker', $worker_id, [], $data, $request);

        return $this->successResponse([
            'worker_id'         => $worker->id,
            'onboarding_token'  => $worker->onboarding_token,
            'onboarding_status' => $worker->onboarding_status,
        ], 'Step 2 completed.');
    }

    // ─── Step 3: Bank / salary details ───────────────────────────────────────

    #[OA\Put(
        path: '/onboarding/{worker_id}/step3',
        operationId: 'onboardingStep3',
        tags: ['Worker Onboarding'],
        summary: 'Step 3 — Salary and bank account details',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['salary_amount', 'bank_name', 'bank_code', 'bank_account_number', 'bank_account_name'],
                properties: [
                    new OA\Property(property: 'salary_amount',       type: 'number',  example: 85000),
                    new OA\Property(property: 'bank_name',           type: 'string',  example: 'First Bank'),
                    new OA\Property(property: 'bank_code',           type: 'string',  example: '011'),
                    new OA\Property(property: 'bank_account_number', type: 'string',  example: '1234567890'),
                    new OA\Property(property: 'bank_account_name',   type: 'string',  example: 'Adamu Bello'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Step 3 completed'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function step3(Step3Request $request, int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);
        $data   = $request->validated();
        $data['onboarding_status'] = 'step3';

        $worker->update($data);

        $this->audit->log('onboarding_step3', 'Worker', $worker_id, [], $data, $request);

        return $this->successResponse([
            'worker_id'         => $worker->id,
            'onboarding_token'  => $worker->onboarding_token,
            'onboarding_status' => $worker->onboarding_status,
        ], 'Step 3 completed.');
    }

    // ─── Step 4: Face biometric ───────────────────────────────────────────────

    #[OA\Post(
        path: '/onboarding/{worker_id}/step4',
        operationId: 'onboardingStep4',
        tags: ['Worker Onboarding'],
        summary: 'Step 4 — Upload face biometric image',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['face_image'],
                    properties: [
                        new OA\Property(
                            property: 'face_image',
                            type: 'string',
                            format: 'binary',
                            description: 'Face photo — jpg/jpeg/png, max 5 MB'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Face image stored, face_enrolled set to true'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function step4(Step4Request $request, int $worker_id): JsonResponse
    {
        $worker    = Worker::findOrFail($worker_id);
        $timestamp = now()->format('YmdHis');
        $filename  = "{$worker_id}_{$timestamp}.jpg";
        $path      = $request->file('face_image')->storeAs('biometrics/faces', $filename, 'public');
        $url       = \Illuminate\Support\Facades\Storage::url($path);

        $worker->update([
            'face_template_url' => $url,
            'face_enrolled'     => true,
            'onboarding_status' => 'step4',
        ]);

        $this->audit->log('onboarding_step4_face', 'Worker', $worker_id, [], ['face_template_url' => $url], $request);

        return $this->successResponse([
            'face_template_url' => $url,
            'face_enrolled'     => true,
            'onboarding_status' => $worker->onboarding_status,
        ], 'Step 4 completed.');
    }

    // ─── Step 5: Voice biometric ──────────────────────────────────────────────

    #[OA\Post(
        path: '/onboarding/{worker_id}/step5',
        operationId: 'onboardingStep5',
        tags: ['Worker Onboarding'],
        summary: 'Step 5 — Upload voice biometric sample',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['voice_sample'],
                    properties: [
                        new OA\Property(
                            property: 'voice_sample',
                            type: 'string',
                            format: 'binary',
                            description: 'Voice recording — wav/mp3/ogg, max 10 MB'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Voice sample stored, voice_enrolled set to true'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function step5(Step5Request $request, int $worker_id): JsonResponse
    {
        $worker    = Worker::findOrFail($worker_id);
        $timestamp = now()->format('YmdHis');
        $filename  = "{$worker_id}_{$timestamp}.wav";
        $path      = $request->file('voice_sample')->storeAs('biometrics/voices', $filename, 'public');
        $url       = \Illuminate\Support\Facades\Storage::url($path);

        $worker->update([
            'voice_template_url' => $url,
            'voice_enrolled'     => true,
            'onboarding_status'  => 'step5',
        ]);

        $this->audit->log('onboarding_step5_voice', 'Worker', $worker_id, [], ['voice_template_url' => $url], $request);

        return $this->successResponse([
            'voice_template_url' => $url,
            'voice_enrolled'     => true,
            'onboarding_status'  => $worker->onboarding_status,
        ], 'Step 5 completed.');
    }

    // ─── Step 6: Create Squad virtual account ────────────────────────────────

    #[OA\Post(
        path: '/onboarding/{worker_id}/step6',
        operationId: 'onboardingStep6',
        tags: ['Worker Onboarding'],
        summary: 'Step 6 — Create Squad virtual account for the worker',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Virtual account created and saved'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Squad API error'),
        ]
    )]
    public function step6(Request $request, int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);

        $result = $this->squad->createVirtualAccount([
            'customer_identifier' => $worker->ippis_id,
            'first_name'          => explode(' ', $worker->full_name)[0] ?? 'Worker',
            'last_name'           => explode(' ', $worker->full_name)[1] ?? '',
            'mobile_num'          => $worker->phone ?? '08000000000',
            'bvn'                 => $worker->bvn ?? '',
        ]);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 422, ['squad_error' => $result['message']]);
        }

        $account = VirtualAccount::create([
            'worker_id'      => $worker->id,
            'account_number' => $result['account_number'],
            'bank_name'      => $result['bank_name'],
            'provider'       => 'squad',
            'is_active'      => true,
        ]);

        $worker->update(['onboarding_status' => 'step6']);

        $this->audit->log('onboarding_step6_virtual_account', 'Worker', $worker_id, [], [
            'account_number' => $account->account_number,
        ], $request);

        return $this->successResponse([
            'virtual_account_number' => $account->account_number,
            'bank_name'              => $account->bank_name,
            'bank_code'              => '058',
            'customer_identifier'    => $worker->ippis_id,
            'onboarding_status'      => $worker->onboarding_status,
        ], 'Step 6 completed. Virtual account created.');
    }

    // ─── Complete: Finalise onboarding ───────────────────────────────────────

    #[OA\Post(
        path: '/onboarding/{worker_id}/complete',
        operationId: 'onboardingComplete',
        tags: ['Worker Onboarding'],
        summary: 'Complete onboarding — activates the worker record',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker successfully enrolled'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Onboarding steps not fully completed'),
        ]
    )]
    public function complete(Request $request, int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);

        if ($worker->onboarding_status !== 'step6') {
            return $this->errorResponse(
                'All onboarding steps must be completed before finalising. Current status: ' . $worker->onboarding_status,
                422
            );
        }

        if (!$worker->face_enrolled) {
            return $this->errorResponse('Face biometric not enrolled (step 4 incomplete).', 422);
        }

        if (!$worker->voice_enrolled) {
            return $this->errorResponse('Voice biometric not enrolled (step 5 incomplete).', 422);
        }

        $worker->update([
            'status'            => 'active',
            'onboarding_status' => 'completed',
            'enrolled_at'       => now(),
        ]);

        WorkerEnrolledEvent::dispatch($worker);

        $this->audit->log('worker_enrolled', 'Worker', $worker->id, [], [
            'status'            => 'active',
            'onboarding_status' => 'completed',
        ], $request);

        return $this->successResponse([
            'worker_id'   => $worker->id,
            'ippis_id'    => $worker->ippis_id,
            'status'      => $worker->status,
            'enrolled_at' => $worker->enrolled_at,
        ], 'Worker successfully enrolled.');
    }

    // ─── Status: Check progress ───────────────────────────────────────────────

    #[OA\Get(
        path: '/onboarding/{worker_id}/status',
        operationId: 'onboardingStatus',
        tags: ['Worker Onboarding'],
        summary: 'Get current onboarding status and completed steps',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Onboarding status with step completion flags'),
            new OA\Response(response: 404, description: 'Worker not found'),
        ]
    )]
    public function status(int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);

        $stepOrder = ['draft', 'step1', 'step2', 'step3', 'step4', 'step5', 'step6', 'completed'];
        $current   = array_search($worker->onboarding_status, $stepOrder, true);

        return $this->successResponse([
            'worker_id'         => $worker->id,
            'onboarding_status' => $worker->onboarding_status,
            'steps_completed'   => [
                'step1'    => $current >= array_search('step1', $stepOrder, true),
                'step2'    => $current >= array_search('step2', $stepOrder, true),
                'step3'    => $current >= array_search('step3', $stepOrder, true),
                'step4'    => (bool) $worker->face_enrolled,
                'step5'    => (bool) $worker->voice_enrolled,
                'step6'    => $worker->virtualAccount()->exists(),
                'complete' => $worker->onboarding_status === 'completed',
            ],
        ]);
    }

    // ─── Resume: Look up by token (public) ────────────────────────────────────

    #[OA\Get(
        path: '/onboarding/resume/{token}',
        operationId: 'onboardingResume',
        tags: ['Worker Onboarding'],
        summary: 'Resume onboarding by token (public — no auth required)',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker data and current onboarding status'),
            new OA\Response(response: 404, description: 'Token not found'),
        ]
    )]
    public function resume(string $token): JsonResponse
    {
        $worker = Worker::where('onboarding_token', $token)->firstOrFail();

        return $this->successResponse([
            'worker_id'         => $worker->id,
            'onboarding_token'  => $worker->onboarding_token,
            'onboarding_status' => $worker->onboarding_status,
            'worker'            => $worker,
        ]);
    }
}
