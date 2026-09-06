<?php

namespace App\Packages\VmManagerApi;

/**
 * Hardcoded VM Manager API endpoint paths relative to config('vm-manager.api_url').
 */
final class VmManagerEndpoints
{
    public const LOGIN = 'api/auth/login';

    public const LOGOUT = 'api/auth/logout';

    public const TRAINING = 'api/training';

    public const TACTICS = 'api/tactics';

    public const TACTICS_CHANGES = 'api/tactics/changes';
}
