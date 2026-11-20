<?php

namespace Webkul\GreenApi\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\GreenApi\Contracts\Message;

class MessageRepository extends Repository
{
    public function model()
    {
        return Message::class;
    }
}
