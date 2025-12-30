<?php
namespace ArpHost\OpenVpnAsWhmcs;

use Exception;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * SSH to host and run OpenVPN-AS sacli commands.
 * Supports both Docker and direct (non-Docker) deployments.
 */
class OpenVpnAsWhmcsDockerClient
{
    /** @var SSH2 */
    private $ssh;
    /** @var string */
    private $mode;
    /** @var string */
    private $sacliPath;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $keyPath,
        string $mode = 'docker',
        string $sacliPath = '/usr/local/openvpn_as/scripts/sacli',
        int $timeoutSeconds = 30
    )
    {
        if (!file_exists($keyPath)) {
            throw new Exception("SSH key not found at: {$keyPath}");
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['docker', 'direct'], true)) {
            throw new Exception("Unsupported execution mode: {$mode}");
        }

        $sacliPath = trim($sacliPath);
        if ($sacliPath === '') {
            throw new Exception("sacli path is required.");
        }

        $this->ssh = new SSH2($host, $port);
        $this->ssh->setTimeout($timeoutSeconds);
        $key = PublicKeyLoader::load(file_get_contents($keyPath));

        if (!$this->ssh->login($user, $key)) {
            throw new Exception("SSH login failed for {$user}@{$host}:{$port}");
        }

        $this->mode = $mode;
        $this->sacliPath = $sacliPath;
    }

    private function execSacli(string $container, string $args): string
    {
        if ($this->mode === 'docker') {
            if (trim($container) === '') {
                throw new Exception("Docker container name is required for docker mode.");
            }
            $cmd = "docker exec " . escapeshellarg($container)
                 . " " . escapeshellarg($this->sacliPath) . " " . $args . " 2>&1";
        } else {
            $cmd = escapeshellarg($this->sacliPath) . " " . $args . " 2>&1";
        }

        $out = $this->ssh->exec($cmd);
        $status = $this->ssh->getExitStatus();

        if ($status !== 0) {
            throw new Exception("sacli failed (exit {$status}): {$out}");
        }

        return $out;
    }

    public function createUser(string $container, string $username, string $password): void
    {
        $this->execSacli($container,
            "--user " . escapeshellarg($username) .
            " --key type --value user_connect UserPropPut"
        );

        $this->execSacli($container,
            "--user " . escapeshellarg($username) .
            " --new_pass " . escapeshellarg($password) .
            " SetLocalPassword"
        );
    }

    public function setPassword(string $container, string $username, string $password): void
    {
        $this->execSacli($container,
            "--user " . escapeshellarg($username) .
            " --new_pass " . escapeshellarg($password) .
            " SetLocalPassword"
        );
    }

    public function setDisabled(string $container, string $username, bool $disabled): void
    {
        $this->execSacli($container,
            "--user " . escapeshellarg($username) .
            " --key disabled --value " . ($disabled ? "true" : "false") .
            " UserPropPut"
        );
    }

    public function deleteUser(string $container, string $username): void
    {
        try {
            $this->execSacli($container,
                "--user " . escapeshellarg($username) . " RevokeUser"
            );
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Profiles not found') === false) {
                throw $e;
            }
        }

        $this->execSacli($container,
            "--user " . escapeshellarg($username) . " UserPropDelAll"
        );

        $this->execSacli($container,
            "--user " . escapeshellarg($username) . " RemoveLocalPassword"
        );
    }

    public function refresh(string $container): string
    {
        return $this->execSacli($container, "start");
    }

    public function getUserProfile(string $container, string $username): string
    {
        try {
            $this->execSacli($container,
                "--user " . escapeshellarg($username) . " AutoGenerateOnBehalfOf"
            );
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Unknown command') === false) {
                throw $e;
            }
        }

        $profile = $this->execSacli($container,
            "--user " . escapeshellarg($username) . " GetUserlogin"
        );

        if (stripos($profile, 'client') === false || stripos($profile, 'remote') === false) {
            throw new Exception("Returned profile doesn't look valid.");
        }

        return $profile;
    }

    public function getUserProps(string $container, string $username): array
    {
        $out = $this->execSacli($container,
            "--user " . escapeshellarg($username) . " UserPropGetAll"
        );

        $parsed = $this->parseSacliOutput($out);
        return is_array($parsed) ? $parsed : [];
    }

    private function parseSacliOutput(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $normalized = $raw;
        $normalized = preg_replace('/\bNone\b/', 'null', $normalized);
        $normalized = preg_replace('/\bTrue\b/', 'true', $normalized);
        $normalized = preg_replace('/\bFalse\b/', 'false', $normalized);
        $normalized = preg_replace("/u'([^']*)'/", "'$1'", $normalized);
        $normalized = str_replace("'", '"', $normalized);

        $decoded = json_decode($normalized, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return null;
    }
}
