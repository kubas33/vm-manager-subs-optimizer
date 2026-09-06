<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VM Manager API
    |--------------------------------------------------------------------------
    |
    | Only the API origin lives in the environment. Endpoint paths are defined
    | in App\Packages\VmManagerApi\VmManagerEndpoints.
    |
    */

    'api_url' => rtrim((string) env('VM_MANAGER_API_URL', 'https://faster.vm-manager.org'), '/'),

    'timeout' => (int) env('VM_MANAGER_API_TIMEOUT', 20),

    // Optional legacy bearer fallback when the browser session has no login token.
    'api_token' => env('VM_MANAGER_API_TOKEN'),

    // Shared secret for the local userscript import webhook.
    'import_token' => env('VM_TRAINING_IMPORT_TOKEN'),

    'default_blocks' => [
        'block1' => (int) env('VM_TACTICS_BLOCK1', 7),
        'blockPassive1' => (int) env('VM_TACTICS_BLOCK_PASSIVE1', 1),
        'block2' => (int) env('VM_TACTICS_BLOCK2', 7),
        'blockPassive2' => (int) env('VM_TACTICS_BLOCK_PASSIVE2', 1),
        'block3' => (int) env('VM_TACTICS_BLOCK3', 7),
        'blockPassive3' => (int) env('VM_TACTICS_BLOCK_PASSIVE3', 0),
    ],
];
