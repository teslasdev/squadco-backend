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
 * Signup is the FIRST thing a worker does — before self-enrolment. Two ways
 * in, both resolve to the same worker row and the same outcome:
 *
 *   A) onboarding_token (primary — the QR/link an admin shares right after
 *      onboarding). Worker is still `pending_self_enrol`; signup just claims
 *      the account + sets a password, then the worker continues into the
 *      self-enrol wizard. Status is NOT changed here — the admin-review gate
 *      lives AFTER self-enrol (worker → pending_review → admin → active).
 *
 *   B) activation_code (fallback — admin reissues an 8-char code when a
 *      worker lost access / needs a password reset on an already-active
 *      account). Original behaviour, kept intact.
 *
 * In both cases email must match the worker record (case-insensitive,
 * anti-hijack) and `account_created_at` must be null (not already claimed).
 * Future logins use /workers/login with just email + password.
 */
class WorkerAuthController extends Controller
{
    /**
     * POST /v1/workers-portal/auth/signup
     */
    public function signup(Request $request)
    {
        $data = $request->validate([
            // Exactly one of these identifies the worker. onboarding_token is
            // the primary QR-link path; activation_code is the admin-reissue
            // fallback. required_without_all makes the API reject a request
            // that supplies neither, with a clear message.
            'onboarding_token' => ['required_without_all:activation_code', 'string'],
            'activation_code'  => ['required_without_all:onboarding_token', 'string', 'size:8'],
            'email'            => ['required', 'email'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $usingToken = filled($data['onboarding_token'] ?? null);

        // Resolve the worker by whichever identifier was supplied. Both keys
        // additionally require the account to be unclaimed.
        $worker = $usingToken
            ? Worker::where('onboarding_token', $data['onboarding_token'])
                ->whereNull('account_created_at')
                ->first()
            : Worker::where('activation_code', strtoupper($data['activation_code']))
                ->whereNull('account_created_at')
                ->first();

        // Field the message attaches to drives which input the frontend
        // highlights, so keep it aligned with the path that was used.
        $idField = $usingToken ? 'onboarding_token' : 'activation_code';

        if (!$worker) {
            throw ValidationException::withMessages([
                $idField => $usingToken
                    ? 'This enrolment link is invalid or the account is already claimed.'
                    : 'Invalid or already-used activation code.',
            ]);
        }

        // Activation codes expire after 14 days. The onboarding token does
        // not expire on its own — it dies when the worker completes/abandons
        // self-enrol, which is governed elsewhere.
        if (
            !$usingToken &&
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

        // Token path: the worker is still `pending_self_enrol` and that's
        // expected — signup precedes enrolment. We only block if they're in a
        // dead state (rejected). Code path: keep the original `active` gate
        // (a reissued code only makes sense for an already-active worker).
        if ($usingToken) {
            if (!in_array($worker->status, ['pending_self_enrol', 'pending_review', 'active'], true)) {
                throw ValidationException::withMessages([
                    'onboarding_token' => "This account cannot be claimed (status: {$worker->status}). Contact your administrator.",
                ]);
            }
        } elseif ($worker->status !== 'active') {
            throw ValidationException::withMessages([
                'activation_code' => 'Worker account is not active. Contact your administrator.',
            ]);
        }

        $worker->update([
            'password'                  => $data['password'],
            // Consume the code if one was used; leave onboarding_token alone
            // so the worker can still resume self-enrol with it after signup.
            'activation_code'           => $usingToken ? $worker->activation_code : null,
            'activation_code_issued_at' => $usingToken ? $worker->activation_code_issued_at : null,
            'account_created_at'        => now(),
            'last_login_at'             => now(),
        ]);

        $token = $worker->createToken('worker-portal')->plainTextToken;

        // Tell the frontend whether enrolment still needs doing so it can
        // route into the wizard vs. straight to the dashboard.
        $needsSelfEnrol = $worker->status === 'pending_self_enrol';

        return $this->successResponse([
            'access_token'     => $token,
            'token_type'       => 'Bearer',
            'needs_self_enrol' => $needsSelfEnrol,
            'onboarding_token' => $worker->onboarding_token,
            'worker'           => $this->workerPayload($worker),
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

        // Password-first flow: a worker has a real account (password set) the
        // moment they sign up, but stays pending_self_enrol → pending_review
        // until an admin approves. They must be able to log in through those
        // states so the dashboard can show them their progress / status.
        // Only genuinely dead accounts are refused here.
        $blockedStatuses = ['rejected', 'blocked', 'suspended'];
        if (in_array($worker->status, $blockedStatuses, true)) {
            return $this->errorResponse(
                'Your account is not active. Contact your MDA administrator.',
                403
            );
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
            // Exposed only while self-enrol is still outstanding so the
            // dashboard can deep-link the worker back into the wizard. Null
            // once they're past pending_self_enrol — no longer needed.
            'onboarding_token'    => $worker->status === 'pending_self_enrol'
                ? $worker->onboarding_token
                : null,
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
