<?php

namespace Webkul\GreenApi\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Webkul\GreenApi\Services\WebhookProcessor;

class ProcessWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId)
    {
        $this->onQueue(config('green-api.queue'));
    }

    public function backoff(): array
    {
        return [5, 15, 60, 120];
    }

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
