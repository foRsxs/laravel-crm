<?php

namespace Webkul\GreenApi\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\GreenApi\Contracts\Conversation;

class ConversationRepository extends Repository
{
    public function model()
    {
        return Conversation::class;
    }
}
