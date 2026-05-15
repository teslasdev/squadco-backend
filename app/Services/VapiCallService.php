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
            'customer'      => ['number' => $this->normalizeE164($toNumber)],
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

    /**
     * Normalise a phone number to E.164, defaulting to Nigeria (+234).
     *
     * Workers are registered in local format ("07026702294",
     * "08012345678") or with spaces/dashes. Vapi requires strict E.164
     * ("+2347026702294") or the call is rejected / mis-dialled. Rules:
     *   - already +<digits>            → kept as-is (any country)
     *   - leading 0 + 10 digits        → Nigerian local: 0X… → +234X…
     *   - 234XXXXXXXXXX (13 digits)    → add the leading '+'
     *   - bare 10-digit subscriber no. → assume Nigerian → +234…
     * Anything else is returned digit-cleaned with a '+' so Vapi can at
     * least try, and the failure surfaces in the dispatch result.
     */
    private function normalizeE164(string $raw): string
    {
        $n = trim($raw);

        // Keep an explicit international number untouched (just strip spaces).
        if (str_starts_with($n, '+')) {
            return '+' . preg_replace('/\D/', '', $n);
        }

        $digits = preg_replace('/\D/', '', $n);

        // 0XXXXXXXXXX → Nigerian local format (0 + 10-digit subscriber).
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '+234' . substr($digits, 1);
        }

        // 234XXXXXXXXXX → already country-coded, just missing the '+'.
        if (strlen($digits) === 13 && str_starts_with($digits, '234')) {
            return '+' . $digits;
        }

        // Bare 10-digit subscriber number → assume Nigerian.
        if (strlen($digits) === 10) {
            return '+234' . $digits;
        }

        // Fallback: prefix '+' so Vapi gets a best-effort E.164 attempt.
        return '+' . $digits;
    }
}
