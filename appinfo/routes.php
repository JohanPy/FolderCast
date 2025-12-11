<?php

return [
    'routes' => [
        [
            'name' => 'Feed#show',
            'url' => '/feed/{token}',
            'verb' => 'GET',
        ],
        [
            'name' => 'Feed#download',
            'url' => '/feed/{token}/audio/{fileId}',
            'verb' => 'GET',
        ],
        [
            'name' => 'Api#index',
            'url' => '/api/feeds',
            'verb' => 'GET',
        ],
        [
            'name' => 'Api#create',
            'url' => '/api/feeds',
            'verb' => 'POST',
        ],
        [
            'name' => 'Api#destroy',
            'url' => '/api/feeds/{id}',
            'verb' => 'DELETE',
        ],
    ],
];
