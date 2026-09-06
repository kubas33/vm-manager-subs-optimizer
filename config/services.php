<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'vm_training_import' => [
        'url' => env('VM_TRAINING_API_URL', 'https://faster.vm-manager.org/api/training'),
        'api_token' => env('VM_TRAINING_API_TOKEN'),
        'timeout' => (int) env('VM_TRAINING_API_TIMEOUT', 20),
        'token' => env('VM_TRAINING_IMPORT_TOKEN'),
    ],

    'vm_auth' => [
        'login_url' => env('VM_AUTH_LOGIN_URL'),
        'timeout' => (int) env('VM_AUTH_API_TIMEOUT', 20),
    ],

    'vm_tactics' => [
        'url' => env('VM_TACTICS_API_URL', 'https://faster.vm-manager.org/api/tactics'),
        'changes_url' => env('VM_TACTICS_CHANGES_URL', 'https://faster.vm-manager.org/api/tactics/changes'),
        'api_token' => env('VM_TACTICS_API_TOKEN'),
        'timeout' => (int) env('VM_TACTICS_API_TIMEOUT', 20),
        'default_blocks' => [
            'block1' => (int) env('VM_TACTICS_BLOCK1', 7),
            'blockPassive1' => (int) env('VM_TACTICS_BLOCK_PASSIVE1', 1),
            'block2' => (int) env('VM_TACTICS_BLOCK2', 7),
            'blockPassive2' => (int) env('VM_TACTICS_BLOCK_PASSIVE2', 1),
            'block3' => (int) env('VM_TACTICS_BLOCK3', 7),
            'blockPassive3' => (int) env('VM_TACTICS_BLOCK_PASSIVE3', 0),
        ],
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
