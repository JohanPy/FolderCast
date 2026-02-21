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
            'name' => 'Feed#cover',
            'url' => '/feed/{token}/cover/{fileId}',
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
            'name' => 'Api#update',
            'url' => '/api/feeds/{id}',
            'verb' => 'PUT',
        ],
        [
            'name' => 'Api#update',
            'url' => '/api/feeds/{id}',
            'verb' => 'PUT',
        ],
        [
            'name' => 'Api#uploadLogo',
            'url' => '/api/feeds/{id}/logo',
            'verb' => 'POST',
        ],
        [
            'name' => 'Api#destroy',
            'url' => '/api/feeds/{id}',
            'verb' => 'DELETE',
        ],
        [
            'name' => 'Feed#logo',
            'url' => '/feed/{token}/logo',
            'verb' => 'GET',
        ],
    ],
];
