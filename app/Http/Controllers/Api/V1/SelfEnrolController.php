<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\AiVerificationService;
use App\Services\AuditService;
use App\Services\SquadPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

/**
 * Public, token-authenticated self-enrolment endpoints.
 *
 * A worker scans a QR code printed at a government office which contains a URL
 * pointing at /api/v1/self-enrol/{token}. From there they can confirm personal
 * info, capture face frames, record a voice sample, and submit for review. No
 * Sanctum auth — the unguessable UUID token in the URL is the auth.
 *
 * After /submit, the token is invalidated by flipping the worker status to
 * pending_review; admins re-issue a fresh token via the activation controller
 * if a worker needs to redo the flow.
 */
#[OA\Tag(name: 'Self Enrolment', description: 'Worker-driven onboarding via QR-code token (no auth)')]
class SelfEnrolController extends Controller
{
    public function __construct(
        private AiVerificationService $ai,
        private AuditService $audit,
        private SquadPaymentService $squad
    ) {}

    /**
     * Find the worker for a given token, or return a 404/410 response.
     * Returns Worker on success, JsonResponse on error.
     */
    private function findWorkerByToken(string $token): Worker|JsonResponse
    {
        $worker = Worker::with('mda', 'department')->where('onboarding_token', $token)->first();
        if (!$worker) {
            return $this->errorResponse('Invalid or expired enrolment link.', 404);
        }
        // Once status moves past pending_self_enrol the token can no longer be used.
        if ($worker->status !== 'pending_self_enrol') {
            return $this->errorResponse('This enrolment link has already been used or is no longer active.', 410);
        }
        return $worker;
    }

    // ─── GET /self-enrol/{token} ────────────────────────────────────────────

    #[OA\Get(
        path: '/self-enrol/{token}',
        operationId: 'selfEnrolShow',
        tags: ['Self Enrolment'],
        summary: 'Worker view — what info to confirm, which biometric captures are pending',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Worker context for the self-enrol UI'),
            new OA\Response(response: 404, description: 'Invalid token'),
            new OA\Response(response: 410, description: 'Token already used'),
        ]
    )]
    public function show(string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        $needsFace = in_array($worker->verification_channel, ['web'], true);

        $pendingSteps = [];
        // employment (just job title — admin may have left blank)
        if (empty($worker->job_title)) {
            $pendingSteps[] = 'employment';
        }
        // personal identity (NIN/BVN/phone) — always required from worker
        if (empty($worker->nin) || empty($worker->bvn) || empty($worker->phone)) {
            $pendingSteps[] = 'step2';
        }
        if ($needsFace && !$worker->face_enrolled) {
            $pendingSteps[] = 'step4';
        }
        // NOTE: phone-channel workers do NOT do a self-enrol voice step.
        // step5 captures voice via the browser mic (wideband), but phone
        // workers are VERIFIED over a narrowband phone call — enrolling on
        // the wrong channel makes genuine workers fail verification. Their
        // voiceprint is enrolled separately by an admin-triggered phone call
        // (POST /workers/{id}/enrol-voice-phone), same channel as verify.
        // So step5 is never a self-enrol pending step.
        // bank account (worker fills) — salary amount is set by admin before activation
        if (empty($worker->bank_account_number) || empty($worker->bank_name)) {
            $pendingSteps[] = 'bank';
        }

        return $this->successResponse([
            'worker_id'            => $worker->id,
            'full_name'            => $worker->full_name,
            'email'                => $worker->email,
            'ippis_id'             => $worker->ippis_id,
            'mda_name'             => $worker->mda?->name,
            'mda_id'               => $worker->mda_id,
            'department_name'      => $worker->department?->name,
            'department_id'        => $worker->department_id,
            'job_title'            => $worker->job_title,
            'grade_level'          => $worker->grade_level,
            'step'                 => $worker->step,
            'employment_date'      => $worker->employment_date,
            'employment_type'      => $worker->employment_type,
            'state_of_posting'     => $worker->state_of_posting,
            'lga'                  => $worker->lga,
            'office_address'       => $worker->office_address,
            'verification_channel' => $worker->verification_channel,
            'onboarding_status'    => $worker->onboarding_status,
            'pending_steps'        => $pendingSteps,
            'can_submit'           => empty($pendingSteps),
        ], 'Self-enrol context loaded.');
    }

    // ─── PUT /self-enrol/{token}/employment ─────────────────────────────────
    //
    // Worker fills in their own employment details (department, job title,
    // grade level, step, employment date, employment type, state of posting,
    // LGA, office address) that the admin opted to leave blank at /step1.

    #[OA\Put(
        path: '/self-enrol/{token}/employment',
        operationId: 'selfEnrolEmployment',
        tags: ['Self Enrolment'],
        summary: 'Worker fills in their own employment details (was admin step1 fields)',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Employment details saved'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function employment(Request $request, string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        $data = $request->validate([
            'job_title' => 'required|string|max:255',
        ]);

        $worker->update($data);

        $this->audit->log('self_enrol_employment', 'Worker', $worker->id, [], $data, $request);

        return $this->successResponse([
            'onboarding_status' => $worker->onboarding_status,
        ], 'Employment details saved.');
    }

    // ─── PUT /self-enrol/{token}/bank ───────────────────────────────────────
    //
    // Worker fills their salary account (bank name, code, account number,
    // account name from Squad lookup). Salary AMOUNT is NOT collected here —
    // admin sets it during activation, since it's HR/budget data the worker
    // shouldn't be able to set themselves.

    #[OA\Put(
        path: '/self-enrol/{token}/bank',
        operationId: 'selfEnrolBank',
        tags: ['Self Enrolment'],
        summary: 'Worker submits their salary bank account (admin sets the salary amount on activation)',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Bank details saved'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function bank(Request $request, string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        $data = $request->validate([
            'bank_name'           => 'required|string',
            'bank_code'           => 'required|string',
            'bank_account_number' => 'required|string|size:10',
            'bank_account_name'   => 'required|string',
        ]);

        $worker->update($data);

        $this->audit->log('self_enrol_bank', 'Worker', $worker->id, [], $data, $request);

        return $this->successResponse([
            'onboarding_status' => $worker->onboarding_status,
        ], 'Bank details saved.');
    }

    // ─── PUT /self-enrol/{token}/step2 ──────────────────────────────────────

    #[OA\Put(
        path: '/self-enrol/{token}/step2',
        operationId: 'selfEnrolStep2',
        tags: ['Self Enrolment'],
        summary: 'Worker submits personal/identity details',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['nin', 'bvn', 'phone', 'email', 'home_address', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship'],
                properties: [
                    new OA\Property(property: 'nin',                      type: 'string'),
                    new OA\Property(property: 'bvn',                      type: 'string'),
                    new OA\Property(property: 'phone',                    type: 'string'),
                    new OA\Property(property: 'email',                    type: 'string'),
                    new OA\Property(property: 'home_address',             type: 'string'),
                    new OA\Property(property: 'next_of_kin_name',         type: 'string'),
                    new OA\Property(property: 'next_of_kin_phone',        type: 'string'),
                    new OA\Property(property: 'next_of_kin_relationship', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Personal info saved'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function step2(Request $request, string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        $data = $request->validate([
            'nin'                      => ['required', 'string', 'size:11', \Illuminate\Validation\Rule::unique('workers', 'nin')->ignore($worker->id)],
            'bvn'                      => ['required', 'string', 'size:11', \Illuminate\Validation\Rule::unique('workers', 'bvn')->ignore($worker->id)],
            'phone'                    => 'required|string|size:11',
            'email'                    => ['required', 'email', \Illuminate\Validation\Rule::unique('workers', 'email')->ignore($worker->id)],
            'home_address'             => 'required|string',
            'next_of_kin_name'         => 'required|string',
            'next_of_kin_phone'        => 'required|string|size:11',
            'next_of_kin_relationship' => 'required|string',
        ]);

        $worker->update($data + ['onboarding_status' => 'step2']);

        $this->audit->log('self_enrol_step2', 'Worker', $worker->id, [], $data, $request);

        return $this->successResponse([
            'onboarding_status' => $worker->onboarding_status,
        ], 'Step 2 saved.');
    }

    // ─── POST /self-enrol/{token}/step4 ─────────────────────────────────────

    #[OA\Post(
        path: '/self-enrol/{token}/step4',
        operationId: 'selfEnrolStep4',
        tags: ['Self Enrolment'],
        summary: 'Worker captures 3 face frames from their phone (or skipped for phone-only)',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Face enrolment completed or skipped'),
            new OA\Response(response: 422, description: 'Identity or liveness FAIL'),
            new OA\Response(response: 503, description: 'AI service unreachable'),
        ]
    )]
    public function step4(Request $request, string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        if ($worker->verification_channel === 'phone') {
            $worker->update(['onboarding_status' => 'step4']);
            return $this->successResponse([
                'face_enrolled'     => false,
                'skipped'           => true,
                'reason'            => 'Worker channel is phone-only; face enrolment not required.',
                'onboarding_status' => $worker->onboarding_status,
            ], 'Step 4 skipped (phone-only worker).');
        }

        $request->validate([
            'frame_straight' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'frame_right'    => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'frame_left'     => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $timestamp = now()->format('YmdHis');
        $straightPath = $request->file('frame_straight')->storeAs("biometrics/faces/{$worker->id}", "{$timestamp}_straight.jpg", 'public');
        $rightPath    = $request->file('frame_right')->storeAs("biometrics/faces/{$worker->id}", "{$timestamp}_right.jpg", 'public');
        $leftPath     = $request->file('frame_left')->storeAs("biometrics/faces/{$worker->id}", "{$timestamp}_left.jpg", 'public');

        $straightAbs = Storage::disk('public')->path($straightPath);
        $rightAbs    = Storage::disk('public')->path($rightPath);
        $leftAbs     = Storage::disk('public')->path($leftPath);

        $cleanup = function () use ($straightPath, $rightPath, $leftPath) {
            Storage::disk('public')->delete([$straightPath, $rightPath, $leftPath]);
        };

        // 1. Identity embedding from the straight frame
        $embedResult = $this->ai->embedFace($straightAbs);
        if (!$embedResult['success']) {
            $cleanup();
            $this->audit->log('self_enrol_step4_embed_failed', 'Worker', $worker->id, [], [
                'error' => $embedResult['message'], 'status' => $embedResult['status'],
            ], $request);
            return $this->errorResponse($embedResult['message'], $embedResult['status'] === 422 ? 422 : 503, ['ai_error' => $embedResult]);
        }
        $embedding = data_get($embedResult, 'data.embedding');
        if (!is_array($embedding)) {
            $cleanup();
            return $this->errorResponse('AI service returned an unexpected response.', 503);
        }

        // ── Enrolment quality gate ──────────────────────────────────────────
        // A blurry / dark / low-confidence enrolment face becomes the
        // permanent template every future verification is compared against —
        // it's the root cause of genuine matches scoring borderline. Reject
        // it now and ask for a better capture, rather than baking it in.
        $quality   = data_get($embedResult, 'data.quality', []);
        $spoofProb = (float) data_get($embedResult, 'data.spoof_prob', 0.0);
        $reasons   = [];

        if (data_get($quality, 'face_detected') === false) {
            $reasons[] = 'no clear face detected';
        }
        if (data_get($quality, 'blur_ok') === false) {
            $reasons[] = 'image is too blurry';
        }
        if (data_get($quality, 'brightness_ok') === false) {
            $reasons[] = 'lighting is too dark';
        }
        $conf    = data_get($quality, 'confidence');
        $minConf = (float) config('services.ai_verification.enrol_min_face_confidence', 0.80);
        if ($conf !== null && (float) $conf < $minConf) {
            $reasons[] = 'face not clearly visible (low detector confidence)';
        }
        $maxSpoof = (float) config('services.ai_verification.enrol_max_spoof_prob', 0.85);
        if ($spoofProb > $maxSpoof) {
            $reasons[] = 'capture failed the liveness/spoof check';
        }

        if (!empty($reasons)) {
            $cleanup();
            $this->audit->log('self_enrol_step4_quality_rejected', 'Worker', $worker->id, [], [
                'reasons'    => $reasons,
                'confidence' => $conf,
                'spoof_prob' => $spoofProb,
                'quality'    => $quality,
            ], $request);

            return $this->errorResponse(
                'Face capture quality too low for enrolment — please retake in good, even lighting, '
                . 'facing the camera directly, with a sharp (non-blurry) image.',
                422,
                [
                    'reasons'    => $reasons,
                    'quality'    => $quality,
                    'spoof_prob' => $spoofProb,
                ]
            );
        }

        // 2. Right-turn liveness
        $rightResult = $this->ai->verifyFacePose($straightAbs, $rightAbs, 'right');
        if (!$rightResult['success'] || !(bool) data_get($rightResult, 'data.passed')) {
            $cleanup();
            $rightDelta = (float) data_get($rightResult, 'data.delta_degrees', 0);
            return $this->errorResponse(
                'Right-turn liveness failed — please turn your head clearly to the right and retry.',
                422,
                ['pose_right' => ['passed' => false, 'delta_degrees' => $rightDelta]]
            );
        }
        $rightDelta = (float) data_get($rightResult, 'data.delta_degrees', 0);

        // 3. Left-turn liveness
        $leftResult = $this->ai->verifyFacePose($straightAbs, $leftAbs, 'left');
        if (!$leftResult['success'] || !(bool) data_get($leftResult, 'data.passed')) {
            $cleanup();
            $leftDelta = (float) data_get($leftResult, 'data.delta_degrees', 0);
            return $this->errorResponse(
                'Left-turn liveness failed — please turn your head clearly to the left and retry.',
                422,
                ['pose_left' => ['passed' => false, 'delta_degrees' => $leftDelta]]
            );
        }
        $leftDelta = (float) data_get($leftResult, 'data.delta_degrees', 0);

        $straightUrl = Storage::url($straightPath);
        $worker->update([
            'face_template_url' => $straightUrl,
            'face_embedding'    => $embedding,
            'face_enrolled'     => true,
            'onboarding_status' => 'step4',
        ]);

        $this->audit->log('self_enrol_step4_face', 'Worker', $worker->id, [], [
            'face_template_url' => $straightUrl,
            'spoof_prob'        => data_get($embedResult, 'data.spoof_prob'),
            'pose_right_delta'  => $rightDelta,
            'pose_left_delta'   => $leftDelta,
        ], $request);

        return $this->successResponse([
            'face_enrolled'     => true,
            'face_template_url' => $straightUrl,
            'spoof_prob'        => data_get($embedResult, 'data.spoof_prob'),
            'quality'           => data_get($embedResult, 'data.quality'),
            'pose_right'        => ['passed' => true, 'delta_degrees' => $rightDelta],
            'pose_left'         => ['passed' => true, 'delta_degrees' => $leftDelta],
            'onboarding_status' => $worker->onboarding_status,
        ], 'Step 4 completed — face enrolment passed identity + liveness checks.');
    }

    // ─── POST /self-enrol/{token}/step5 ─────────────────────────────────────

    #[OA\Post(
        path: '/self-enrol/{token}/step5',
        operationId: 'selfEnrolStep5',
        tags: ['Self Enrolment'],
        summary: 'Worker uploads a voice sample (or skipped for web-only)',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Voice enrolment completed or skipped'),
            new OA\Response(response: 422, description: 'Audio rejected'),
            new OA\Response(response: 503, description: 'AI service unreachable'),
        ]
    )]
    public function step5(Request $request, string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        // Voice is NOT self-enrolled via the browser for ANY channel here:
        //  - web-channel workers don't need a voiceprint at all.
        //  - phone-channel workers ARE verified over the phone, so their
        //    voiceprint must be captured on the SAME phone channel (admin
        //    triggers POST /workers/{id}/enrol-voice-phone). Web-mic
        //    (wideband) enrolment + phone (narrowband) verify is the exact
        //    channel mismatch that makes genuine workers fail.
        // Either way, step5 is a no-op skip and never web-records audio.
        if (in_array($worker->verification_channel, ['web', 'phone'], true)) {
            $worker->update(['onboarding_status' => 'step5']);
            return $this->successResponse([
                'voice_enrolled'    => false,
                'skipped'           => true,
                'reason'            => $worker->verification_channel === 'web'
                    ? 'Worker channel is web-only; voice enrolment not required.'
                    : 'Phone-channel voice is enrolled by an admin phone call, not in the browser.',
                'onboarding_status' => $worker->onboarding_status,
            ], 'Step 5 skipped.');
        }

        $request->validate([
            'voice_sample' => 'required|file|mimes:wav,mp3,ogg,webm,m4a|max:10240',
        ]);

        $timestamp = now()->format('YmdHis');
        $extension = $request->file('voice_sample')->getClientOriginalExtension() ?: 'wav';
        $filename  = "{$worker->id}_{$timestamp}.{$extension}";
        $path      = $request->file('voice_sample')->storeAs('biometrics/voices', $filename, 'public');
        $absPath   = Storage::disk('public')->path($path);

        $result = $this->ai->embedVoice($absPath);
        if (!$result['success']) {
            Storage::disk('public')->delete($path);
            return $this->errorResponse($result['message'], $result['status'] === 422 ? 422 : 503, ['ai_error' => $result]);
        }
        $ecapa    = data_get($result, 'data.embeddings.ecapa');
        $campplus = data_get($result, 'data.embeddings.campplus');
        if (!is_array($ecapa) || !is_array($campplus)) {
            Storage::disk('public')->delete($path);
            return $this->errorResponse('AI service returned an unexpected response.', 503);
        }

        $url = Storage::url($path);
        $worker->update([
            'voice_template_url'       => $url,
            'voice_embedding_ecapa'    => $ecapa,
            'voice_embedding_campplus' => $campplus,
            'voice_enrolled'           => true,
            'onboarding_status'        => 'step5',
        ]);

        $this->audit->log('self_enrol_step5_voice', 'Worker', $worker->id, [], [
            'voice_template_url' => $url,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
        ], $request);

        return $this->successResponse([
            'voice_enrolled'     => true,
            'voice_template_url' => $url,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
            'quality'            => data_get($result, 'data.quality'),
            'onboarding_status'  => $worker->onboarding_status,
        ], 'Step 5 completed.');
    }

    // ─── POST /self-enrol/{token}/submit ────────────────────────────────────

    #[OA\Post(
        path: '/self-enrol/{token}/submit',
        operationId: 'selfEnrolSubmit',
        tags: ['Self Enrolment'],
        summary: 'Worker finalises submission — moves status to pending_review',
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Submitted for admin review'),
            new OA\Response(response: 422, description: 'Some required steps still pending'),
        ]
    )]
    public function submit(Request $request, string $token): JsonResponse
    {
        $worker = $this->findWorkerByToken($token);
        if ($worker instanceof JsonResponse) return $worker;

        // Check the worker has filled everything required for their channel.
        // Phone-channel voice is NOT a self-enrol step (enrolled later via
        // the admin phone call — see show()), so it never gates submission.
        $needsFace = in_array($worker->verification_channel, ['web'], true);

        $missing = [];
        if (empty($worker->job_title)) {
            $missing[] = 'employment';
        }
        if (empty($worker->nin) || empty($worker->bvn) || empty($worker->phone)) {
            $missing[] = 'step2';
        }
        if ($needsFace && !$worker->face_enrolled) {
            $missing[] = 'step4';
        }
        if (empty($worker->bank_account_number) || empty($worker->bank_name)) {
            $missing[] = 'bank';
        }

        if (!empty($missing)) {
            return $this->errorResponse(
                'Cannot submit — the following steps are still pending: ' . implode(', ', $missing),
                422,
                ['pending_steps' => $missing]
            );
        }

        $worker->update([
            'status'            => 'pending_review',
            'onboarding_status' => 'completed',
        ]);

        $this->audit->log('self_enrol_submitted', 'Worker', $worker->id, [], [
            'status' => 'pending_review',
        ], $request);

        if ((bool) config('services.squad.sms_on_submit', true) && !empty($worker->phone)) {
            $organization = (string) ($worker->mda?->name ?: config('app.name', 'your organization'));
            $smsMessage = "Hello {$worker->full_name}, you have been onboarded to {$organization}. Your profile is pending admin review.";

            $smsResult = $this->squad->sendInstantSms((string) $worker->phone, $smsMessage);

            $this->audit->log(
                $smsResult['success'] ? 'self_enrol_submit_sms_sent' : 'self_enrol_submit_sms_failed',
                'Worker',
                $worker->id,
                [],
                [
                    'phone' => $worker->phone,
                    'sms_message' => $smsMessage,
                    'result' => $smsResult['message'] ?? null,
                ],
                $request
            );
        }

        return $this->successResponse([
            'worker_id'   => $worker->id,
            'status'      => $worker->status,
            'next_action' => 'admin_review',
        ], 'Submitted for review. An admin will activate your account within 24 hours.');
    }
}
