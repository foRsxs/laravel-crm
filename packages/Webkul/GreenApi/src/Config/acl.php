<?php

return [
    ['key' => 'green_api', 'name' => 'green_api::app.title', 'route' => 'admin.green_api.index', 'sort' => 5],
    ['key' => 'green_api.view', 'name' => 'green_api::app.view', 'route' => ['admin.green_api.show', 'admin.green_api.messages'], 'sort' => 1],
    ['key' => 'green_api.send', 'name' => 'green_api::app.send', 'route' => ['admin.green_api.start', 'admin.green_api.send'], 'sort' => 2],
    ['key' => 'green_api.manage', 'name' => 'green_api::app.manage', 'route' => 'admin.green_api.update', 'sort' => 3],
    ['key' => 'green_api.settings', 'name' => 'green_api::app.settings', 'route' => ['admin.green_api.settings', 'admin.green_api.status'], 'sort' => 4],
];
