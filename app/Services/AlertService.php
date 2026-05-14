<?php

namespace App\Services;

use App\Models\GhostWorkerAlert;
use App\Models\Worker;
use App\Models\Verification;
use App\Models\AgentDispatch;
use App\Models\FieldAgent;
use Illuminate\Support\Facades\DB;

class AlertService
{
    /**
     * Auto-create a ghost worker alert from a FAIL or INCONCLUSIVE verification.
     */
    public function createFromVerification(Verification $verification): GhostWorkerAlert
    {
        $worker   = $verification->worker;
        $verdict  = $verification->verdict;

        $alertType = $verdict === 'FAIL' ? 'biometric_mismatch' : 'consecutive_no_show';
        $severity  = $verdict === 'FAIL' ? 'high' : 'medium';

        $alert = GhostWorkerAlert::create([
            'worker_id'       => $worker->id,
            'verification_id' => $verification->id,
            'alert_type'      => $alertType,
            'severity'        => $severity,
            'ai_confidence'   => $verification->trust_score,
            'salary_at_risk'  => $worker->salary_amount,
            'status'          => 'open',
            'raised_at'       => now(),
        ]);

        if ($verdict === 'INCONCLUSIVE') {
            $this->dispatchNearestAgent($alert, $worker);
        }

        return $alert;
    }

    /**
     * Block the salary for a worker (update alert + worker status).
     */
    public function blockSalary(GhostWorkerAlert $alert): void
    {
        DB::transaction(function () use ($alert) {
            $alert->update(['status' => 'blocked']);
            $alert->worker()->update(['status' => 'blocked']);
        });
    }

    /**
     * Dispatch the nearest available field agent to a worker.
     */
    public function dispatchNearestAgent(GhostWorkerAlert $alert, Worker $worker): ?AgentDispatch
    {
        $agent = FieldAgent::where('status', 'active')
            ->where('state', $worker->state_of_posting)
            ->orderBy('assignments_count', 'asc')
            ->first();

        if (!$agent) {
            $agent = FieldAgent::where('status', 'active')
                ->orderBy('assignments_count', 'asc')
                ->first();
        }

        if (!$agent) return null;

        $dispatch = AgentDispatch::create([
            'alert_id'      => $alert->id,
            'agent_id'      => $agent->id,
            'worker_id'     => $worker->id,
            'status'        => 'pending',
            'dispatched_at' => now(),
        ]);

        $agent->increment('assignments_count');
        $agent->update(['last_assignment_at' => now()]);
        $alert->update(['status' => 'agent_dispatched']);

        return $dispatch;
    }

    /**
     * Resolve an alert.
     */
    public function resolve(GhostWorkerAlert $alert, int $resolvedBy, string $notes = ''): void
    {
        $alert->update([
            'status'      => 'resolved',
            'resolved_by' => $resolvedBy,
            'resolved_at' => now(),
            'notes'       => $notes,
        ]);
    }
}
