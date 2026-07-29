<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'usage#get',
            'url' => '/api/v1/usage',
            'verb' => 'GET',
        ],
        [
            'name' => 'folder_settings#browse',
            'url' => '/admin/folders',
            'verb' => 'GET',
        ],
        [
            'name' => 'folder_settings#save',
            'url' => '/admin/folder-settings',
            'verb' => 'PUT',
        ],
    ],
];
