<?php
// Simple CLI smoke test for OpenVPN-AS via SSH + sacli.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$autoloadPaths = [
    $root . '/vendor/autoload.php',
    $root . '/tests/vendor/autoload.php',
];
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

require_once $root . '/lib/OpenVpnAsWhmcsDockerClient.php';

use ArpHost\OpenVpnAsWhmcs\OpenVpnAsWhmcsDockerClient;

loadEnvFile($root . '/tests/local.env');

$host = getenv('OVPNAS_HOST') ?: '';
$port = (int)(getenv('OVPNAS_SSH_PORT') ?: 22);
$user = getenv('OVPNAS_SSH_USER') ?: '';
$key = getenv('OVPNAS_SSH_KEY') ?: '';
$mode = getenv('OVPNAS_MODE') ?: 'docker';
$container = getenv('OVPNAS_CONTAINER') ?: '';
$sacliPath = getenv('OVPNAS_SACLI_PATH') ?: '/usr/local/openvpn_as/scripts/sacli';
$timeout = (int)(getenv('OVPNAS_TIMEOUT') ?: 30);
$testUser = getenv('OVPNAS_TEST_USERNAME') ?: ('smoke_test_' . date('Ymd_His'));
$testProfile = strtolower(getenv('OVPNAS_TEST_PROFILE') ?: 'no') === 'yes';

if ($host === '' || $user === '' || $key === '') {
    fwrite(STDERR, "Missing required env vars: OVPNAS_HOST, OVPNAS_SSH_USER, OVPNAS_SSH_KEY\n");
    exit(1);
}

if (strtolower($mode) === 'docker' && $container === '') {
    fwrite(STDERR, "OVPNAS_CONTAINER is required when OVPNAS_MODE=docker\n");
    exit(1);
}

if ($timeout <= 0) {
    $timeout = 30;
}

$password = bin2hex(random_bytes(8));
$newPassword = bin2hex(random_bytes(8));

echo "Connecting to {$host} as {$user} (mode={$mode})...\n";
$client = new OpenVpnAsWhmcsDockerClient($host, $port, $user, $key, $mode, $sacliPath, $timeout);

try {
    echo "Creating user: {$testUser}\n";
    $client->createUser($container, $testUser, $password);

    echo "Changing password\n";
    $client->setPassword($container, $testUser, $newPassword);

    echo "Suspending user\n";
    $client->setDisabled($container, $testUser, true);

    echo "Unsuspending user\n";
    $client->setDisabled($container, $testUser, false);

    if ($testProfile) {
        echo "Fetching profile\n";
        $client->getUserProfile($container, $testUser);
    }

    echo "Deleting user\n";
    $client->deleteUser($container, $testUser);

    echo "Smoke test completed successfully.\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Smoke test failed: " . $e->getMessage() . "\n");

    // Best-effort cleanup
    try {
        $client->deleteUser($container, $testUser);
    } catch (Exception $cleanup) {
        fwrite(STDERR, "Cleanup failed: " . $cleanup->getMessage() . "\n");
    }

    exit(1);
}

function loadEnvFile(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}
