<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkerMandate extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'settlement_id',
        'transaction_reference',
        'debit_transaction_reference',
        'last_webhook_event',
        'squad_mandate_reference',
        'mandate_type',
        'amount',
        'bank_code',
        'account_number',
        'account_name',
        'customer_email',
        'start_date',
        'end_date',
        'status',
        'approved',
        'ready_to_debit',
        'description',
        'request_payload',
        'response_payload',
        'initiated_at',
        'failed_at',
        'debited_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'approved' => 'boolean',
        'ready_to_debit' => 'boolean',
        'initiated_at' => 'datetime',
        'failed_at' => 'datetime',
        'debited_at' => 'datetime',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }
}
