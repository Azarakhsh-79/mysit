<?php

use Telegram\Bot\Commands\HelpCommand;
use App\Bots\Mtr\Commands\StartCommand;

return [
    'bots' => [
        'amir' => [
            'token'            => env('BOT_AMIR'),
            'certificate_path' => null,   // ngrok/SSL معتبر → null
            'webhook_url'      => null,   // ما خودمان set می‌کنیم
            'allowed_updates'  => null,
            'commands'         => [
                // فرمان‌های اختصاصی amir
            ],
        ],

        'mtr' => [
            'token'            => env('BOT_MTR'),
            'certificate_path' => null,
            
            'webhook_url'      => null,
            'link'             => 'https://t.me/MTR_stone_bot?start=',
            'allowed_updates'  => null,
            'commands'         => [

                // فرمان‌های اختصاصی mtr
            ],
        ],
    ],

    'default' => 'amir', // هرکدام که خواستی
    'resolve_command_dependencies' => true,
    'commands' => [ /* دستورات سراسری اگر داری (ترجیحاً خالی بماند) */],
    'async_requests' => env('TELEGRAM_ASYNC_REQUESTS', false),
    'http_client_handler' => null,
    'base_bot_url' => null,
    'command_groups' => [
        /* // Group Type: 1
           'commmon' => [
                Acme\Project\Commands\TodoCommand::class,
                Acme\Project\Commands\TaskCommand::class,
           ],
        */

        /* // Group Type: 2
           'subscription' => [
                'start', // Shared Command Name.
                'stop', // Shared Command Name.
           ],
        */

        /* // Group Type: 3
            'auth' => [
                Acme\Project\Commands\LoginCommand::class,
                Acme\Project\Commands\SomeCommand::class,
            ],

            'stats' => [
                Acme\Project\Commands\UserStatsCommand::class,
                Acme\Project\Commands\SubscriberStatsCommand::class,
                Acme\Project\Commands\ReportsCommand::class,
            ],

            'admin' => [
                'auth', // Command Group Name.
                'stats' // Command Group Name.
            ],
        */

        /* // Group Type: 4
           'myBot' => [
                'admin', // Command Group Name.
                'subscription', // Command Group Name.
                'status', // Shared Command Name.
                'Acme\Project\Commands\BotCommand' // Full Path to Command Class.
           ],
        */
    ],
    'shared_commands' => [
        // 'start' => Acme\Project\Commands\StartCommand::class,
        // 'stop' => Acme\Project\Commands\StopCommand::class,
        // 'status' => Acme\Project\Commands\StatusCommand::class,
    ],
];
