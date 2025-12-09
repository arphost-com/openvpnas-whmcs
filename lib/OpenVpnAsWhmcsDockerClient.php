<?php
namespace ArpHost\OpenVpnAsWhmcs;

use Exception;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * SSH to Docker host, run docker exec openvpn-as sacli commands.
 * Compatible with OpenVPN-AS 3.0.2.
 */
class OpenVpnAsWhmcsDockerClient
{
    private SSH2 $ssh;

    public function __construct(string $host, int $port, string $user, string $keyPath)
    {
        if (!file_exists($keyPath)) {
            throw new Exception("SSH key not found at: {$keyPath}");
        }

        $this->ssh = new SSH2($host, $port);
        $key = PublicKeyLoader::load(file_get_contents($keyPath));

        if (!$this->ssh->login($user, $key)) {
            throw new Exception("SSH login failed for {$user}@{$host}:{$port}");
        }
    }

    private function execSacli(string $container, string $args): string
    {
        $cmd = "docker exec " . escapeshellarg($container)
             . " /usr/local/openvpn_as/scripts/sacli " . $args . " 2>&1";

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
}
