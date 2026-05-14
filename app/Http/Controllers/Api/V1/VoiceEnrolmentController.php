<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\AiVerificationService;
use App\Services\AuditService;
use App\Services\VapiCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Voice Enrolment', description: 'Voice enrolment (web upload) + verification (Vapi outbound call)')]
class VoiceEnrolmentController extends Controller
{
    public function __construct(
        private AiVerificationService $ai,
        private VapiCallService $vapi,
        private AuditService $audit
    ) {}

    #[OA\Post(
        path: '/workers/{id}/enrol-voice',
        operationId: 'workerEnrolVoice',
        tags: ['Voice Enrolment'],
        summary: 'Upload a voice sample to enrol the worker (web upload, no phone call)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
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
                            description: 'WAV / MP3 / OGG / WebM audio, at least 1.5s of speech'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Enrolment completed, embeddings stored'),
            new OA\Response(response: 422, description: 'Validation error or audio rejected by AI'),
            new OA\Response(response: 503, description: 'AI service unreachable'),
        ]
    )]
    public function enrol(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'voice_sample' => 'required|file|mimes:wav,mp3,ogg,webm,m4a|max:10240',
        ]);

        $worker = Worker::findOrFail($id);

        $file       = $request->file('voice_sample');
        $timestamp  = now()->format('YmdHis');
        $extension  = $file->getClientOriginalExtension() ?: 'wav';
        $filename   = "{$id}_{$timestamp}.{$extension}";
        $storedPath = $file->storeAs('biometrics/voices', $filename, 'public');
        $publicUrl  = Storage::url($storedPath);

        $absolutePath = Storage::disk('public')->path($storedPath);

        $result = $this->ai->embedVoice($absolutePath);

        if (!$result['success']) {
            // Don't keep the file if we can't enrol the worker against it.
            Storage::disk('public')->delete($storedPath);

            $this->audit->log('voice_enrol_ai_failed', 'Worker', $id, [], [
                'error'  => $result['message'],
                'status' => $result['status'],
            ], $request);

            $httpStatus = $result['status'] === 422 ? 422 : 503;
            return $this->errorResponse($result['message'], $httpStatus, ['ai_error' => $result]);
        }

        $ecapa    = data_get($result, 'data.embeddings.ecapa');
        $campplus = data_get($result, 'data.embeddings.campplus');

        if (!is_array($ecapa) || !is_array($campplus)) {
            Storage::disk('public')->delete($storedPath);
            return $this->errorResponse('AI service returned an unexpected response.', 503, ['ai_response' => $result['data']]);
        }

        $worker->update([
            'voice_template_url'       => $publicUrl,
            'voice_embedding_ecapa'    => $ecapa,
            'voice_embedding_campplus' => $campplus,
            'voice_enrolled'           => true,
        ]);

        $this->audit->log('voice_enrol_completed', 'Worker', $id, [], [
            'voice_template_url' => $publicUrl,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
            'duration_sec'       => data_get($result, 'data.quality.duration_sec'),
        ], $request);

        return $this->successResponse([
            'worker_id'          => $worker->id,
            'voice_enrolled'     => true,
            'voice_template_url' => $publicUrl,
            'spoof_prob'         => data_get($result, 'data.spoof_prob'),
            'quality'            => data_get($result, 'data.quality'),
        ], 'Voice enrolment completed.');
    }

    #[OA\Post(
        path: '/workers/{id}/verify-voice',
        operationId: 'workerVerifyVoice',
        tags: ['Voice Enrolment'],
        summary: 'Trigger a Vapi outbound call to verify the worker\'s voice',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 202, description: 'Call dispatched'),
            new OA\Response(response: 422, description: 'Worker not enrolled or has no phone'),
            new OA\Response(response: 503, description: 'Vapi unreachable'),
        ]
    )]
    public function verify(Request $request, int $id): JsonResponse
    {
        $worker = Worker::with('mda')->findOrFail($id);

        if ($worker->verification_channel === 'web') {
            return $this->errorResponse(
                'Worker is enrolled for web verification only — use POST /api/v1/face-verification/start instead.',
                422
            );
        }

        if (empty($worker->phone)) {
            return $this->errorResponse('Worker has no phone number on file.', 422);
        }

        if (empty($worker->full_name) || empty($worker->ippis_id)) {
            return $this->errorResponse('Worker is missing required identity fields (full_name, ippis_id) needed to personalise the call.', 422);
        }

        if (!$worker->voice_enrolled || empty($worker->voice_embedding_ecapa)) {
            return $this->errorResponse('Worker is not enrolled for voice verification.', 422);
        }

        $assistantId = config('services.vapi.assistant_verify_id');
        if (!$assistantId) {
            return $this->errorResponse('Vapi verification assistant not configured.', 503);
        }

        $firstName = trim(explode(' ', $worker->full_name)[0]) ?: $worker->full_name;
        $now       = now();
        $variableValues = [
            'worker_first_name' => $firstName,
            'worker_full_name'  => $worker->full_name,
            'ippis_id'          => $worker->ippis_id,
            'mda_name'          => $worker->mda?->name ?? 'your ministry',
            'today_date'        => $now->format('l, F j, Y'),     // Thursday, May 14, 2026
            'today_short'       => $now->format('F j'),            // May 14
        ];

        $result = $this->vapi->dispatchCall($worker->phone, $assistantId, [
            'worker_id' => $worker->id,
            'intent'    => 'verify',
        ], [
            'variableValues' => $variableValues,
        ]);

        if (!$result['success']) {
            $this->audit->log('voice_verify_dispatch_failed', 'Worker', $id, [], ['error' => $result['message']], $request);
            return $this->errorResponse($result['message'], 503);
        }

        $this->audit->log('voice_verify_dispatched', 'Worker', $id, [], ['call_id' => $result['call_id']], $request);

        return $this->successResponse([
            'call_id' => $result['call_id'],
            'status'  => 'dispatched',
        ], 'Verification call dispatched.', 202);
    }
}
