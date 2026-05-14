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
use App\Services\AiVerificationService;
use App\Services\AuditService;
use App\Services\SquadPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Worker Onboarding', description: 'Multi-step worker onboarding flow')]
class OnboardingController extends Controller
{
    public function __construct(
        private SquadPaymentService $squad,
        private AuditService $audit,
        private AiVerificationService $ai
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
        // Workers start in pending_self_enrol — admin has filled employment data,
        // but worker still needs to confirm personal info + capture biometrics
        // (either at a kiosk via the admin, or via the self-enrol QR link).
        $data['status']            = 'pending_self_enrol';
        $data['onboarding_status'] = 'step1';
        $data['onboarding_token']  = (string) Str::uuid();

        $worker = Worker::create($data);

        $this->audit->log('onboarding_step1', 'Worker', $worker->id, [], $data, $request);

        return $this->successResponse([
            'worker_id'         => $worker->id,
            'onboarding_token'  => $worker->onboarding_token,
            'onboarding_status' => $worker->onboarding_status,
            'status'            => $worker->status,
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

    // ─── Step 4: Face biometric (live 3-frame Persona-style capture) ─────────

    #[OA\Post(
        path: '/onboarding/{worker_id}/step4',
        operationId: 'onboardingStep4',
        tags: ['Worker Onboarding'],
        summary: 'Step 4 — Enrol face: live 3-frame capture (look straight, turn right, turn left)',
        description: 'For workers with verification_channel of "web" or "both" the admin captures three live frames at a kiosk. The straight frame produces the enrolment embedding; the right/left frames pass head-turn liveness checks so a printed photo cannot be enrolled. For "phone"-only workers this step is auto-skipped (no body required).',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'worker_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'frame_straight', type: 'string', format: 'binary', description: 'Worker looking directly at camera (required for web/both)'),
                        new OA\Property(property: 'frame_right',    type: 'string', format: 'binary', description: 'Worker turning head to their right'),
                        new OA\Property(property: 'frame_left',     type: 'string', format: 'binary', description: 'Worker turning head to their left'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Face enrolment completed (or skipped for phone-only workers)'),
            new OA\Response(response: 404, description: 'Worker not found'),
            new OA\Response(response: 422, description: 'Identity FAIL, liveness FAIL, or bad image'),
            new OA\Response(response: 503, description: 'AI service unreachable'),
        ]
    )]
    public function step4(Request $request, int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);

        // Phone-only workers don't enrol a face. Mark step done and move on.
        if ($worker->verification_channel === 'phone') {
            $worker->update(['onboarding_status' => 'step4']);
            $this->audit->log('onboarding_step4_skipped', 'Worker', $worker_id, [], [
                'reason' => 'verification_channel=phone',
            ], $request);
            return $this->successResponse([
                'face_enrolled'     => false,
                'skipped'           => true,
                'reason'            => 'Worker channel is phone-only; face enrolment not required.',
                'onboarding_status' => $worker->onboarding_status,
            ], 'Step 4 skipped (phone-only worker).');
        }

        // Web / both: require the 3-frame live capture.
        $request->validate([
            'frame_straight' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'frame_right'    => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'frame_left'     => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $timestamp = now()->format('YmdHis');
        $straightPath = $request->file('frame_straight')->storeAs("biometrics/faces/{$worker_id}", "{$timestamp}_straight.jpg", 'public');
        $rightPath    = $request->file('frame_right')->storeAs("biometrics/faces/{$worker_id}", "{$timestamp}_right.jpg", 'public');
        $leftPath     = $request->file('frame_left')->storeAs("biometrics/faces/{$worker_id}", "{$timestamp}_left.jpg", 'public');

        $straightAbs = Storage::disk('public')->path($straightPath);
        $rightAbs    = Storage::disk('public')->path($rightPath);
        $leftAbs     = Storage::disk('public')->path($leftPath);

        $cleanup = function () use ($straightPath, $rightPath, $leftPath) {
            Storage::disk('public')->delete([$straightPath, $rightPath, $leftPath]);
        };

        // 1. Identity / embedding from the straight frame
        $embedResult = $this->ai->embedFace($straightAbs);
        if (!$embedResult['success']) {
            $cleanup();
            $this->audit->log('onboarding_step4_embed_failed', 'Worker', $worker_id, [], [
                'error'  => $embedResult['message'],
                'status' => $embedResult['status'],
            ], $request);
            $httpStatus = $embedResult['status'] === 422 ? 422 : 503;
            return $this->errorResponse($embedResult['message'], $httpStatus, ['ai_error' => $embedResult]);
        }
        $embedding = data_get($embedResult, 'data.embedding');
        if (!is_array($embedding)) {
            $cleanup();
            return $this->errorResponse('AI service returned an unexpected response.', 503, ['ai_response' => $embedResult['data']]);
        }

        // 2. Right-turn liveness
        $rightResult = $this->ai->verifyFacePose($straightAbs, $rightAbs, 'right');
        if (!$rightResult['success']) {
            $cleanup();
            $this->audit->log('onboarding_step4_pose_right_ai_failed', 'Worker', $worker_id, [], [
                'error' => $rightResult['message'], 'status' => $rightResult['status'],
            ], $request);
            $httpStatus = $rightResult['status'] === 422 ? 422 : 503;
            return $this->errorResponse($rightResult['message'], $httpStatus, ['ai_error' => $rightResult]);
        }
        $rightPassed = (bool) data_get($rightResult, 'data.passed');
        $rightDelta  = (float) data_get($rightResult, 'data.delta_degrees', 0);
        if (!$rightPassed) {
            $cleanup();
            $this->audit->log('onboarding_step4_pose_right_failed', 'Worker', $worker_id, [], [
                'delta_degrees' => $rightDelta,
            ], $request);
            return $this->errorResponse(
                'Right-turn liveness failed — please turn your head clearly to the right and retry.',
                422,
                ['pose_right' => ['passed' => false, 'delta_degrees' => $rightDelta]]
            );
        }

        // 3. Left-turn liveness
        $leftResult = $this->ai->verifyFacePose($straightAbs, $leftAbs, 'left');
        if (!$leftResult['success']) {
            $cleanup();
            $this->audit->log('onboarding_step4_pose_left_ai_failed', 'Worker', $worker_id, [], [
                'error' => $leftResult['message'], 'status' => $leftResult['status'],
            ], $request);
            $httpStatus = $leftResult['status'] === 422 ? 422 : 503;
            return $this->errorResponse($leftResult['message'], $httpStatus, ['ai_error' => $leftResult]);
        }
        $leftPassed = (bool) data_get($leftResult, 'data.passed');
        $leftDelta  = (float) data_get($leftResult, 'data.delta_degrees', 0);
        if (!$leftPassed) {
            $cleanup();
            $this->audit->log('onboarding_step4_pose_left_failed', 'Worker', $worker_id, [], [
                'delta_degrees' => $leftDelta,
            ], $request);
            return $this->errorResponse(
                'Left-turn liveness failed — please turn your head clearly to the left and retry.',
                422,
                ['pose_left' => ['passed' => false, 'delta_degrees' => $leftDelta]]
            );
        }

        // All 3 checks passed — save embedding and frame URLs
        $straightUrl = Storage::url($straightPath);
        $worker->update([
            'face_template_url' => $straightUrl,
            'face_embedding'    => $embedding,
            'face_enrolled'     => true,
            'onboarding_status' => 'step4',
        ]);

        $this->audit->log('onboarding_step4_face', 'Worker', $worker_id, [], [
            'face_template_url'  => $straightUrl,
            'spoof_prob'         => data_get($embedResult, 'data.spoof_prob'),
            'quality'            => data_get($embedResult, 'data.quality'),
            'pose_right_delta'   => $rightDelta,
            'pose_left_delta'    => $leftDelta,
        ], $request);

        return $this->successResponse([
            'face_enrolled'      => true,
            'face_template_url'  => $straightUrl,
            'spoof_prob'         => data_get($embedResult, 'data.spoof_prob'),
            'quality'            => data_get($embedResult, 'data.quality'),
            'pose_right'         => ['passed' => true, 'delta_degrees' => $rightDelta],
            'pose_left'          => ['passed' => true, 'delta_degrees' => $leftDelta],
            'onboarding_status'  => $worker->onboarding_status,
        ], 'Step 4 completed — face enrolment passed identity + liveness checks.');
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
    public function step5(Request $request, int $worker_id): JsonResponse
    {
        $worker = Worker::findOrFail($worker_id);

        // Web-only workers don't enrol a voice. Mark step done and move on.
        if ($worker->verification_channel === 'web') {
            $worker->update(['onboarding_status' => 'step5']);
            $this->audit->log('onboarding_step5_skipped', 'Worker', $worker_id, [], [
                'reason' => 'verification_channel=web',
            ], $request);
            return $this->successResponse([
                'voice_enrolled'    => false,
                'skipped'           => true,
                'reason'            => 'Worker channel is web-only; voice enrolment not required.',
                'onboarding_status' => $worker->onboarding_status,
            ], 'Step 5 skipped (web-only worker).');
        }

        $request->validate([
            'voice_sample' => 'required|file|mimes:wav,mp3,ogg,webm,m4a|max:10240',
        ]);

        $timestamp = now()->format('YmdHis');
        $extension = $request->file('voice_sample')->getClientOriginalExtension() ?: 'wav';
        $filename  = "{$worker_id}_{$timestamp}.{$extension}";
        $path      = $request->file('voice_sample')->storeAs('biometrics/voices', $filename, 'public');
        $url       = Storage::url($path);
        $absPath   = Storage::disk('public')->path($path);

        // Run audio through the AI service to extract ECAPA + CAM++ voiceprints.
        // If the AI rejects the clip (too short, no speech) or is unreachable,
        // we don't keep the file or mark the worker as enrolled.
        $result = $this->ai->embedVoice($absPath);

        if (!$result['success']) {
            Storage::disk('public')->delete($path);
            $this->audit->log('onboarding_step5_voice_ai_failed', 'Worker', $worker_id, [], [
                'error'  => $result['message'],
                'status' => $result['status'],
            ], $request);
            $httpStatus = $result['status'] === 422 ? 422 : 503;
            return $this->errorResponse($result['message'], $httpStatus, ['ai_error' => $result]);
        }

        $ecapa    = data_get($result, 'data.embeddings.ecapa');
        $campplus = data_get($result, 'data.embeddings.campplus');
        if (!is_array($ecapa) || !is_array($campplus)) {
            Storage::disk('public')->delete($path);
            return $this->errorResponse('AI service returned an unexpected response.', 503, ['ai_response' => $result['data']]);
        }

        $worker->update([
            'voice_template_url'       => $url,
            'voice_embedding_ecapa'    => $ecapa,
            'voice_embedding_campplus' => $campplus,
            'voice_enrolled'           => true,
            'onboarding_status'        => 'step5',
        ]);

        $this->audit->log('onboarding_step5_voice', 'Worker', $worker_id, [], [
            'voice_template_url' => $url,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
            'duration_sec'       => data_get($result, 'data.quality.duration_sec'),
        ], $request);

        return $this->successResponse([
            'voice_enrolled'     => true,
            'voice_template_url' => $url,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
            'quality'            => data_get($result, 'data.quality'),
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

        // Channel-aware biometric requirements: phone-only skips face, web-only skips voice.
        $needsFace  = in_array($worker->verification_channel, ['web', 'both'], true);
        $needsVoice = in_array($worker->verification_channel, ['phone', 'both'], true);

        if ($needsFace && !$worker->face_enrolled) {
            return $this->errorResponse('Face biometric not enrolled (step 4 incomplete).', 422);
        }

        if ($needsVoice && !$worker->voice_enrolled) {
            return $this->errorResponse('Voice biometric not enrolled (step 5 incomplete).', 422);
        }

        // Worker now goes to pending_review — admin still has to activate before
        // automated verification kicks in. This applies to both admin-kiosk and
        // worker-self-enrol paths; the gatekeeping happens after biometric capture.
        $worker->update([
            'status'            => 'pending_review',
            'onboarding_status' => 'completed',
        ]);

        $this->audit->log('worker_pending_review', 'Worker', $worker->id, [], [
            'status'            => 'pending_review',
            'onboarding_status' => 'completed',
        ], $request);

        return $this->successResponse([
            'worker_id'   => $worker->id,
            'ippis_id'    => $worker->ippis_id,
            'status'      => $worker->status,
            'next_action' => 'admin_review',
        ], 'Onboarding submitted — awaiting admin review and activation.');
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
