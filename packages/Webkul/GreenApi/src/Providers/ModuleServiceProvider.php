<?php

namespace Webkul\GreenApi\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\GreenApi\Models\Conversation;
use Webkul\GreenApi\Models\Message;
use Webkul\GreenApi\Models\WebhookEvent;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [Conversation::class, Message::class, WebhookEvent::class];
}
