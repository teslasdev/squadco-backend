<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SquadPaymentService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = (string) (config('services.squad.base_url') ?? 'https://api-d.squadco.com');
        $this->apiKey  = (string) (config('services.squad.api_key') ?? '');
    }

    /**
     * Trigger salary disbursement for a worker via Squad.
     * Returns ['success' => bool, 'reference' => string|null, 'message' => string]
     */
    public function disburse(array $payload): array
    {
        // Payload: account_number, bank_code, amount (kobo), narration, currency_id
        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/payout/initiate", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success'   => true,
                    'reference' => $data['data']['transaction_reference'] ?? Str::uuid(),
                    'message'   => 'Disbursement initiated successfully.',
                ];
            }

            return [
                'success'   => false,
                'reference' => null,
                'message'   => $response->json('message', 'Squad API error'),
            ];
        } catch (\Throwable $e) {
            return [
                'success'   => false,
                'reference' => null,
                'message'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a virtual account for a worker via Squad.
     */
    public function createVirtualAccount(array $payload): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/virtual-account", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success'        => true,
                    'account_number' => $data['data']['virtual_account_number'] ?? null,
                    'bank_name'      => $data['data']['bank_name'] ?? 'Squad/GTBank',
                    'message'        => 'Virtual account created.',
                ];
            }

            return [
                'success' => false,
                'message' => $response->json('message', 'Squad API error'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Verify a Squad webhook signature using HMAC-SHA512.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.squad.webhook_secret', '');
        if (empty($secret)) return false;

        $expected = hash_hmac('sha512', $payload, $secret);
        return hash_equals($expected, strtolower($signature));
    }
}
