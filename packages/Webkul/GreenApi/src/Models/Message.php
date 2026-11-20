<?php

namespace Webkul\GreenApi\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\GreenApi\Contracts\Message as MessageContract;

/**
 * @property string|null $external_id
 * @property string $status
 */
class Message extends Model implements MessageContract
{
    protected $table = 'green_api_messages';

    protected $fillable = ['conversation_id', 'external_id', 'client_reference', 'direction', 'type', 'body', 'media_url', 'media_name', 'status', 'sent_by', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];
}
