<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Worker portal authentication.
 *
 *   POST /v1/workers-portal/auth/signup   — code + email + password → creates account
 *   POST /v1/workers-portal/auth/login    — email + password → token
 *   GET  /v1/workers-portal/me            — authenticated worker (Sanctum 'worker' guard)
 *   POST /v1/workers-portal/auth/logout   — revoke current token
 *
 * Activation flow:
 *   1. Admin activates a worker via /workers/{id}/activate. The activation
 *      controller generates an `activation_code` and stamps `activation_code_issued_at`.
 *   2. Admin gives the worker the code (printed, QR, SMS — frontend choice).
 *   3. Worker visits /workers/signup, enters: code + email + password.
 *      - Code must match a worker row, be unused, and be < 14 days old.
 *      - Email must match the worker row (case-insensitive).
 *      - On success: password is hashed, code is consumed (set to NULL),
 *        `account_created_at` is timestamped, a token is issued.
 *   4. Future logins use /workers/login with just email + password.
 */
class WorkerAuthController extends Controller
{
    /**
     * POST /v1/workers-portal/auth/signup
     */
    public function signup(Request $request)
    {
        $data = $request->validate([
            'activation_code' => ['required', 'string', 'size:8'],
            'email'           => ['required', 'email'],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $worker = Worker::where('activation_code', strtoupper($data['activation_code']))
            ->whereNull('account_created_at')
            ->first();

        if (!$worker) {
            throw ValidationException::withMessages([
                'activation_code' => 'Invalid or already-used activation code.',
            ]);
        }

        // 14-day expiry
        if (
            $worker->activation_code_issued_at &&
            $worker->activation_code_issued_at->lt(now()->subDays(14))
        ) {
            throw ValidationException::withMessages([
                'activation_code' => 'Activation code expired. Please ask your administrator for a new code.',
            ]);
        }

        if (strcasecmp($worker->email, $data['email']) !== 0) {
            throw ValidationException::withMessages([
                'email' => 'Email does not match the worker record on file.',
            ]);
        }

        if ($worker->status !== 'active') {
            throw ValidationException::withMessages([
                'activation_code' => 'Worker account is not active. Contact your administrator.',
            ]);
        }

        $worker->update([
            'password'                  => $data['password'],
            'activation_code'           => null,
            'activation_code_issued_at' => null,
            'account_created_at'        => now(),
            'last_login_at'             => now(),
        ]);

        $token = $worker->createToken('worker-portal')->plainTextToken;

        return $this->successResponse([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'worker'       => $this->workerPayload($worker),
        ], 'Account created. You are signed in.');
    }

    /**
     * POST /v1/workers-portal/auth/login
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $worker = Worker::where('email', $data['email'])
            ->whereNotNull('account_created_at')
            ->first();

        if (!$worker || !Hash::check($data['password'], $worker->password ?? '')) {
            return $this->errorResponse('Invalid email or password.', 401);
        }

        if ($worker->status !== 'active') {
            return $this->errorResponse('Your account is currently inactive. Contact your administrator.', 403);
        }

        $worker->update(['last_login_at' => now()]);

        $token = $worker->createToken('worker-portal')->plainTextToken;

        return $this->successResponse([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'worker'       => $this->workerPayload($worker),
        ], 'Signed in.');
    }

    /**
     * GET /v1/workers-portal/me
     */
    public function me(Request $request)
    {
        $worker = $request->user('worker')->load(['mda', 'department', 'virtualAccount']);
        return $this->successResponse([
            'worker' => $this->workerPayload($worker),
        ]);
    }

    /**
     * POST /v1/workers-portal/auth/logout
     */
    public function logout(Request $request)
    {
        $request->user('worker')->currentAccessToken()->delete();
        return $this->successResponse([], 'Signed out.');
    }

    /**
     * Shape the worker for the portal response. Excludes biometric embeddings
     * (already hidden on the model) and trims to the fields the worker actually
     * needs to see.
     */
    protected function workerPayload(Worker $worker): array
    {
        return [
            'id'                  => $worker->id,
            'full_name'           => $worker->full_name,
            'ippis_id'            => $worker->ippis_id,
            'email'               => $worker->email,
            'phone'               => $worker->phone,
            'status'              => $worker->status,
            'job_title'           => $worker->job_title,
            'grade_level'         => $worker->grade_level,
            'step'                => $worker->step,
            'state_of_posting'    => $worker->state_of_posting,
            'verification_channel'=> $worker->verification_channel,
            'face_enrolled'       => (bool) $worker->face_enrolled,
            'voice_enrolled'      => (bool) $worker->voice_enrolled,
            'salary_amount'       => $worker->salary_amount,
            'bank_name'           => $worker->bank_name,
            'bank_account_number' => $worker->bank_account_number
                ? '****' . substr($worker->bank_account_number, -4)
                : null,
            'enrolled_at'         => $worker->enrolled_at,
            'last_verified_at'    => $worker->last_verified_at,
            'last_login_at'       => $worker->last_login_at,
            'mda'                 => $worker->mda ? [
                'id'   => $worker->mda->id,
                'name' => $worker->mda->name,
            ] : null,
            'department'          => $worker->department ? [
                'id'   => $worker->department->id,
                'name' => $worker->department->name,
            ] : null,
            'virtual_account'     => $worker->virtualAccount ? [
                'account_number' => $worker->virtualAccount->account_number ?? null,
                'bank_name'      => $worker->virtualAccount->bank_name ?? null,
            ] : null,
        ];
    }
}
