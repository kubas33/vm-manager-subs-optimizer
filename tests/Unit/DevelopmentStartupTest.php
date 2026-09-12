<?php

it('uses the Docker development launcher', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($projectRoot.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $launcher = (string) file_get_contents($projectRoot.'/scripts/dev.sh');
    $remote = (string) file_get_contents($projectRoot.'/scripts/dev-remote.sh');
    $services = (string) file_get_contents($projectRoot.'/scripts/dev-services.sh');
    $setup = (string) file_get_contents($projectRoot.'/scripts/setup.sh');
    $compose = (string) file_get_contents($projectRoot.'/compose.yaml');

    expect($composer['scripts']['dev'])->toContain('bash scripts/dev.sh')
        ->and($composer['scripts']['setup'])->toContain('bash scripts/setup.sh')
        ->and($launcher)->toContain('docker compose up -d --wait')
        ->and($launcher)->toContain('docker compose exec --user sail -T laravel.test')
        ->and($launcher)->toContain('docker compose exec --user root -T laravel.test chown')
        ->and($launcher)->toContain('php artisan migrate --force --no-interaction')
        ->and($launcher)->not->toContain('--graceful')
        ->and($launcher)->toContain('setsid --wait')
        ->and($launcher)->toContain('stop_remote_process')
        ->and($launcher)->toContain('REMOTE_READY_FILE')
        ->and($launcher)->toContain('REMOTE_CANCEL_FILE')
        ->and($launcher)->toContain('wait_for_remote_registration')
        ->and($launcher)->toContain('cancel_remote_process')
        ->and($launcher)->toContain('touch "${REMOTE_CANCEL_FILE}"')
        ->and($launcher)->toContain('*dev-remote.sh*|*dev-services.sh*')
        ->and($launcher)->toContain('termination_confirmed')
        ->and($launcher)->toContain("trap 'exit 130' INT")
        ->and($launcher)->toContain("trap 'exit 143' TERM")
        ->and($launcher)->not->toContain('kill -0 "$(<"${LOCK_DIRECTORY}/pid")"')
        ->and($services)->toContain('concurrently --raw --kill-others')
        ->and($remote)->toContain('REMOTE_READY_FILE')
        ->and($remote)->toContain('REMOTE_CANCEL_FILE')
        ->and($remote)->toContain('bash "$services_script"')
        ->and($remote)->toContain('trap clear_state EXIT')
        ->and($setup)->toContain('docker compose up -d --wait')
        ->and($setup)->toContain('docker compose exec --user sail -T laravel.test php artisan migrate --force --no-interaction')
        ->and($setup)->toContain('createArrayBacked')
        ->and($compose)->toContain('condition: service_healthy');
});

it('cancels a delayed remote registration before services start', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $suffix = getmypid().'-'.bin2hex(random_bytes(4));
    $pidFile = "/tmp/vm-manager-subs-optimizer-test-{$suffix}.pid";
    $readyFile = "/tmp/vm-manager-subs-optimizer-test-{$suffix}.ready";
    $cancelFile = "/tmp/vm-manager-subs-optimizer-test-{$suffix}.cancel";
    $markerFile = tempnam(sys_get_temp_dir(), 'vm-manager-subs-optimizer-');
    $servicesScript = tempnam(sys_get_temp_dir(), 'vm-manager-subs-optimizer-');
    if (file_exists($markerFile)) {
        unlink($markerFile);
    }

    file_put_contents($servicesScript, <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail
touch "$DEV_REMOTE_TEST_MARKER"
BASH);

    $command = sprintf(
        'sleep 1; exec bash scripts/dev-remote.sh %s',
        escapeshellarg($servicesScript),
    );
    $environment = array_merge($_ENV, [
        'DEV_REMOTE_PID_FILE' => $pidFile,
        'DEV_REMOTE_READY_FILE' => $readyFile,
        'DEV_REMOTE_CANCEL_FILE' => $cancelFile,
        'DEV_REMOTE_TEST_MARKER' => $markerFile,
    ]);
    $process = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        $projectRoot,
        $environment,
    );

    expect($process)->toBeResource();

    usleep(100_000);
    touch($cancelFile);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(143)
        ->and(file_exists($markerFile))->toBeFalse()
        ->and(file_exists($pidFile))->toBeFalse()
        ->and(file_exists($readyFile))->toBeFalse();

    foreach ([$markerFile, $servicesScript, $cancelFile, $pidFile, $readyFile] as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('fails fast when the configured Vite port is already occupied', function (): void {
    $viteConfig = (string) file_get_contents(dirname(__DIR__, 2).'/vite.config.js');

    expect($viteConfig)->toContain('strictPort: true')
        ->and($viteConfig)->toContain('process.env.VITE_PORT ?? env.VITE_PORT');
});
