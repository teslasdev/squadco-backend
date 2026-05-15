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
        $this->baseUrl = (string) (config('services.squad.base_url') ?? 'https://sandbox-api-d.squadco.com');
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
     * Verify Squad's x-squad-encrypted-body webhook signature using HMAC-SHA512.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = (string) (config('services.squad.webhook_secret') ?: config('services.squad.api_key', ''));
        $signature = trim($signature);

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $payload, $secret);
        return hash_equals(strtolower($expected), strtolower($signature));
    }

    /**
     * Verify payment details via Squad.
     */
    public function verifyPaymentDetails(string $accountNumber, string $bankCode): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/payout/account/lookup", [
                    'account_number' => $accountNumber,
                    'bank_code'      => $bankCode,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'account_name' => $data['data']['account_name'] ?? null,
                    'message' => 'Payment details verified.',
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
     * Fetch Squad mandate bank list.
     */
    public function fetchMandateBankList(): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->get("{$this->baseUrl}/transaction/mandate/banklists");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'banks' => $data['data'] ?? [],
                    'message' => $data['message'] ?? 'Mandate bank list fetched.',
                    'raw' => $data,
                ];
            }

            return [
                'success' => false,
                'banks' => [],
                'message' => $response->json('message', 'Squad API error'),
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'banks' => [],
                'message' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }

    /**
     * Create a mandate on Squad.
     */
    public function createMandate(array $payload): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->post("{$this->baseUrl}/transaction/mandate/create", $payload);

            $data = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'mandate_reference' => $data['data']['mandate_reference']
                        ?? $data['data']['reference']
                        ?? null,
                    'message' => $data['message'] ?? 'Mandate created successfully.',
                    'raw' => $data,
                ];
            }

            return [
                'success' => false,
                'mandate_reference' => null,
                'message' => $data['message'] ?? 'Squad API error',
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'mandate_reference' => null,
                'message' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }

    /**
     * Debit an approved/ready mandate on Squad.
     */
    public function debitMandate(array $payload): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->post("{$this->baseUrl}/transaction/mandate/debit", $payload);

            $data = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'reference' => $data['data']['transaction_ref']
                        ?? $payload['transaction_reference']
                        ?? null,
                    'message' => $data['message'] ?? 'Mandate debit initiated.',
                    'raw' => $data,
                ];
            }

            return [
                'success' => false,
                'reference' => $payload['transaction_reference'] ?? null,
                'message' => $data['message'] ?? 'Squad API error',
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'reference' => $payload['transaction_reference'] ?? null,
                'message' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }
}
