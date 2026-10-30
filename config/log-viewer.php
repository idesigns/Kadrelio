<?php

use App\Http\Middleware\PrivateResponse;
use Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer;

return [
    'route_path' => 'log-viewer',
    'back_to_system_label' => 'Wróć do Kadrelio',
    'middleware' => ['web', 'auth', PrivateResponse::class, AuthorizeLogViewer::class],
    'api_middleware' => ['web', 'auth', PrivateResponse::class, AuthorizeLogViewer::class],
    'include_files' => ['*.log', '**/*.log'],
];
