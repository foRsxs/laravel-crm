<?php

namespace Webkul\GreenApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Contact\Models\PersonProxy;
use Webkul\GreenApi\Contracts\Conversation as ConversationContract;
use Webkul\Lead\Models\LeadProxy;

/**
 * @property int|null $user_id
 */
class Conversation extends Model implements ConversationContract
{
    protected $table = 'green_api_conversations';

    protected $fillable = ['instance_id', 'chat_id', 'name', 'person_id', 'lead_id', 'user_id', 'mode', 'opted_out'];

    protected $casts = ['opted_out' => 'boolean'];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }
}
