<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Settings', description: 'System configuration key-value settings')]
class SettingsController extends Controller
{
    public function __construct(private AuditService $audit) {}

    #[OA\Get(
        path: '/settings',
        operationId: 'settingsIndex',
        tags: ['Settings'],
        summary: 'Get all system settings',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Key-value map of all settings')]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(Setting::all()->keyBy('key'));
    }

    #[OA\Put(
        path: '/settings',
        operationId: 'settingsUpdate',
        tags: ['Settings'],
        summary: 'Update one or more system settings',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['settings'],
                properties: [
                    new OA\Property(
                        property: 'settings',
                        type: 'object',
                        example: ['trust_score_threshold' => 70, 'auto_block_on_fail' => true]
                    ),
                ]
            )
        ),
        responses: [new OA\Response(response: 200, description: 'Settings updated')]
    )]
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => 'required|array']);

        foreach ($data['settings'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->audit->log('settings_updated', 'Setting', null, [], $data['settings'], $request);
        return $this->successResponse(['settings' => Setting::all()->keyBy('key')], 'Settings updated.');
    }
}
