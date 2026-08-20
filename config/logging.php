<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily', 'errors'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'tap' => [\App\Logging\ExcludeErrors::class],
        ],

        // Errors-only triage channel — receives error+ from both 'daily' and 'api'
        // via stack membership. Filters at the Monolog level: info/warning never
        // land here, so an empty file means nothing is broken.
        'errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/errors.log'),
            'level' => 'error',
            'days' => 30,
            'replace_placeholders' => true,
        ],

        // One structured line per external HTTP call (see App\Listeners\LogOutgoingHttpCall).
        'api' => [
            'driver' => 'stack',
            'channels' => ['api_daily', 'errors'],
            'ignore_exceptions' => false,
        ],

        'api_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/api.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'tap' => [\App\Logging\ExcludeErrors::class],
            'replace_placeholders' => true,
        ],

        // Per-subsystem activity channels: stack ['{name}_daily', 'errors'].
        // info/warning land in {name}-*.log (via the ExcludeErrors tap on
        // the _daily member); error+ land in errors-*.log only. Invariant:
        // every pair below must keep 'errors' as a stack member.
        'zoho' => [
            'driver' => 'stack',
            'channels' => ['zoho_daily', 'errors'],
            'ignore_exceptions' => false,
        ],

        'zoho_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/zoho.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'tap' => [\App\Logging\ExcludeErrors::class],
            'replace_placeholders' => true,
        ],

        'discovery' => [
            'driver' => 'stack',
            'channels' => ['discovery_daily', 'errors'],
            'ignore_exceptions' => false,
        ],

        'discovery_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/discovery.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'tap' => [\App\Logging\ExcludeErrors::class],
            'replace_placeholders' => true,
        ],

        'campaign' => [
            'driver' => 'stack',
            'channels' => ['campaign_daily', 'errors'],
            'ignore_exceptions' => false,
        ],

        'campaign_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/campaign.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'tap' => [\App\Logging\ExcludeErrors::class],
            'replace_placeholders' => true,
        ],

        'gemini' => [
            'driver' => 'stack',
            'channels' => ['gemini_daily', 'errors'],
            'ignore_exceptions' => false,
        ],

        'gemini_daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/gemini.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'tap' => [\App\Logging\ExcludeErrors::class],
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],

];
