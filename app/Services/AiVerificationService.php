<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiVerificationService
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai_verification.base_url', ''), '/');
        $this->token   = (string) config('services.ai_verification.token', '');
        $this->timeout = (int) config('services.ai_verification.timeout', 30);
    }

    /**
     * Generate voice embeddings (ECAPA 192-dim + CAM++ 512-dim) from an audio file.
     *
     * @return array{success: bool, data: ?array, message: string, status: ?int}
     */
    public function embedVoice(string $audioPath): array
    {
        return $this->postFile('/embed', [['name' => 'audio', 'path' => $audioPath]], []);
    }

    /**
     * Verify live audio against enrolled voiceprints.
     *
     * @param array<int,float> $ecapaEmbedding 192 floats
     * @param array<int,float> $campplusEmbedding 512 floats
     * @return array{success: bool, data: ?array, message: string, status: ?int}
     */
    public function verifyVoice(string $audioPath, array $ecapaEmbedding, array $campplusEmbedding): array
    {
        return $this->postFile('/verify', [['name' => 'audio', 'path' => $audioPath]], [
            'embedding_ecapa'    => json_encode(array_values($ecapaEmbedding)),
            'embedding_campplus' => json_encode(array_values($campplusEmbedding)),
        ]);
    }

    /**
     * Generate a 512-dim ArcFace face embedding from a single image.
     *
     * @return array{success: bool, data: ?array, message: string, status: ?int}
     */
    public function embedFace(string $imagePath): array
    {
        return $this->postFile('/face/embed', [['name' => 'image', 'path' => $imagePath]], []);
    }

    /**
     * Verify a single face frame against an enrolled embedding (identity + passive liveness).
     *
     * @param array<int,float> $faceEmbedding 512 floats
     * @return array{success: bool, data: ?array, message: string, status: ?int}
     */
    public function verifyFace(string $imagePath, array $faceEmbedding): array
    {
        return $this->postFile('/face/verify', [['name' => 'image', 'path' => $imagePath]], [
            'embedding' => json_encode(array_values($faceEmbedding)),
        ]);
    }

    /**
     * Active-liveness head-turn check between two frames.
     *
     * @return array{success: bool, data: ?array, message: string, status: ?int}
     */
    public function verifyFacePose(string $referenceImagePath, string $poseImagePath, string $direction): array
    {
        return $this->postFile('/face/verify-pose', [
            ['name' => 'reference_image', 'path' => $referenceImagePath],
            ['name' => 'pose_image',      'path' => $poseImagePath],
        ], [
            'expected_direction' => $direction,
        ]);
    }

    public function health(): array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/health');
            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json(), 'message' => 'ok', 'status' => $response->status()];
            }
            return ['success' => false, 'data' => null, 'message' => 'AI service health check failed', 'status' => $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'data' => null, 'message' => 'AI service unreachable: ' . $e->getMessage(), 'status' => null];
        }
    }

    /**
     * Shared POST-with-file-attachments helper. Sends as multipart/form-data
     * with one or more named file fields and any extra string form fields.
     *
     * @param list<array{name: string, path: string}> $files
     * @param array<string,string> $form
     * @return array{success: bool, data: ?array, message: string, status: ?int}
     */
    private function postFile(string $path, array $files, array $form): array
    {
        foreach ($files as $f) {
            if (!is_readable($f['path'])) {
                return ['success' => false, 'data' => null, 'message' => "File not readable: {$f['path']}", 'status' => null];
            }
        }

        try {
            $request = Http::withToken($this->token)
                ->withHeaders(['X-Alive-API-Version' => '1'])
                ->timeout($this->timeout);

            foreach ($files as $f) {
                $request = $request->attach($f['name'], file_get_contents($f['path']), basename($f['path']));
            }
            foreach ($form as $name => $value) {
                $request = $request->attach($name, $value);
            }

            $response = $request->post($this->baseUrl . $path);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                    'message' => 'ok',
                    'status'  => $response->status(),
                ];
            }

            $body = $response->json() ?? [];
            $message = is_array($body) && isset($body['detail'])
                ? (is_string($body['detail']) ? $body['detail'] : json_encode($body['detail']))
                : ('AI service returned ' . $response->status());

            return [
                'success' => false,
                'data'    => $body ?: null,
                'message' => $message,
                'status'  => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('AiVerificationService HTTP failure', ['path' => $path, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'data'    => null,
                'message' => 'AI service unreachable: ' . $e->getMessage(),
                'status'  => null,
            ];
        }
    }
}
