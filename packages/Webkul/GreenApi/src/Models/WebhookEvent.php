<?php

namespace Webkul\GreenApi\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\GreenApi\Contracts\WebhookEvent as WebhookEventContract;

class WebhookEvent extends Model implements WebhookEventContract
{
    protected $table = 'green_api_webhook_events';

    protected $fillable = ['event_key', 'instance_id', 'external_id', 'type', 'payload', 'processed_at'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}
