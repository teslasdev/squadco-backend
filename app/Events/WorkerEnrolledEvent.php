<?php

namespace App\Events;

use App\Models\Worker;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerEnrolledEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Worker $worker) {}
}
