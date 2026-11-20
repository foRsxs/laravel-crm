<?php

namespace Webkul\GreenApi\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\GreenApi\Contracts\WebhookEvent;

class WebhookEventRepository extends Repository
{
    public function model()
    {
        return WebhookEvent::class;
    }
}
