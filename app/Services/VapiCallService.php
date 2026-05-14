<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VapiCallService
{
    private string $baseUrl;
    private string $apiKey;
    private ?string $phoneNumberId;

    public function __construct()
    {
        $this->baseUrl       = rtrim(config('services.vapi.base_url', 'https://api.vapi.ai'), '/');
        $this->apiKey        = (string) config('services.vapi.api_key', '');
        $this->phoneNumberId = config('services.vapi.phone_number_id');
    }

    /**
     * Trigger an outbound call. Vapi rings $toNumber, runs the assistant flow,
     * and POSTs the recording back to our /webhooks/vapi endpoint at end-of-call.
     * The metadata we pass here is echoed back on every webhook event for this call.
     *
     * $overrides supports Vapi's full assistantOverrides payload — most useful
     * key is `variableValues` (a flat map of {{placeholder}} values referenced
     * by the assistant's system prompt and firstMessage).
     *
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $overrides
     * @return array{success: bool, call_id: ?string, message: string, status: ?int}
     */
    public function dispatchCall(string $toNumber, string $assistantId, array $metadata, array $overrides = []): array
    {
        if ($this->apiKey === '' || !$this->phoneNumberId) {
            return [
                'success' => false,
                'call_id' => null,
                'message' => 'Vapi not configured (missing VAPI_API_KEY or VAPI_PHONE_NUMBER_ID)',
                'status'  => null,
            ];
        }

        $body = [
            'phoneNumberId' => $this->phoneNumberId,
            'customer'      => ['number' => $toNumber],
            'assistantId'   => $assistantId,
            'metadata'      => $metadata,
        ];
        if (!empty($overrides)) {
            $body['assistantOverrides'] = $overrides;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post($this->baseUrl . '/call', $body);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'call_id' => $data['id'] ?? null,
                    'message' => 'Call dispatched.',
                    'status'  => $response->status(),
                ];
            }

            return [
                'success' => false,
                'call_id' => null,
                'message' => $response->json('message') ?? ('Vapi returned ' . $response->status()),
                'status'  => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('VapiCallService dispatch failure', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'call_id' => null,
                'message' => 'Vapi unreachable: ' . $e->getMessage(),
                'status'  => null,
            ];
        }
    }
}
