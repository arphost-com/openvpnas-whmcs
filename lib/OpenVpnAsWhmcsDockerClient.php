<?php
namespace ArpHost\OpenVpnAsWhmcs;

use Exception;

/**
 * SSH to host and run OpenVPN-AS sacli commands.
 * Supports both Docker and direct (non-Docker) deployments.
 *
 * Uses the phpseclib that WHMCS bundles, picked at runtime: phpseclib 3
 * (namespace phpseclib3) on WHMCS 9.x and phpseclib 2 (namespace phpseclib)
 * on WHMCS 8.x, so the module loads on either.
 */
class OpenVpnAsWhmcsDockerClient
{
    private const DEFAULT_SACLI_PATH = '/usr/local/openvpn_as/scripts/sacli';
    private const SSH2_V3 = '\\phpseclib3\\Net\\SSH2';
    private const KEY_LOADER_V3 = '\\phpseclib3\\Crypt\\PublicKeyLoader';
    private const PRIVATE_KEY_V3 = '\\phpseclib3\\Crypt\\Common\\PrivateKey';
    private const SSH2_V2 = '\\phpseclib\\Net\\SSH2';
    private const RSA_V2 = '\\phpseclib\\Crypt\\RSA';
    /** @var object phpseclib SSH2 */
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
        string $sacliPath = self::DEFAULT_SACLI_PATH,
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
            $sacliPath = self::DEFAULT_SACLI_PATH;
        }

        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = 30;
        }

        $version = self::phpseclibVersion();

        $keyContents = is_readable($keyPath) ? file_get_contents($keyPath) : false;
        if ($keyContents === false || trim($keyContents) === '') {
            throw new Exception("SSH key at {$keyPath} is not readable by the WHMCS PHP user.");
        }
        $key = self::loadPrivateKey($version, $keyContents, $keyPath);

        $sshClass = $version === 3 ? self::SSH2_V3 : self::SSH2_V2;

        // phpseclib 3 throws on connection and auth problems, phpseclib 2
        // returns false. Both end up as one clear Exception.
        try {
            $this->ssh = new $sshClass($host, $port, $timeoutSeconds);
            $this->ssh->setTimeout($timeoutSeconds);
            $loggedIn = $this->ssh->login($user, $key);
        } catch (\Throwable $e) {
            throw new Exception("SSH connection failed for {$user}@{$host}:{$port}: " . $e->getMessage(), 0, $e);
        }

        if (!$loggedIn) {
            throw new Exception("SSH login failed for {$user}@{$host}:{$port}");
        }

        $this->mode = $mode;
        $this->sacliPath = $sacliPath;
    }

    /**
     * Which bundled phpseclib major version is available (3 preferred, then 2).
     */
    public static function phpseclibVersion(): int
    {
        if (class_exists(self::SSH2_V3) && class_exists(self::KEY_LOADER_V3)) {
            return 3;
        }
        if (class_exists(self::SSH2_V2) && class_exists(self::RSA_V2)) {
            return 2;
        }
        throw new Exception('phpseclib is not available. This module needs the phpseclib 3 (WHMCS 9) or phpseclib 2 (WHMCS 8) library from the WHMCS vendor directory.');
    }

    /**
     * Load the SSH private key. phpseclib 3 accepts RSA, ECDSA and ed25519 keys; phpseclib 2 is RSA only.
     *
     * @return object key accepted by SSH2::login()
     */
    private static function loadPrivateKey(int $version, string $keyContents, string $keyPath)
    {
        if ($version === 3) {
            $loader = self::KEY_LOADER_V3;
            $privateKeyClass = self::PRIVATE_KEY_V3;
            try {
                $key = $loader::load($keyContents);
            } catch (\Throwable $e) {
                throw new Exception("Failed to load SSH key from: {$keyPath} (" . $e->getMessage() . ')', 0, $e);
            }
            if (!($key instanceof $privateKeyClass)) {
                throw new Exception("SSH key at {$keyPath} is not a private key.");
            }
            return $key;
        }

        $rsaClass = self::RSA_V2;
        $key = new $rsaClass();
        if ($key->loadKey($keyContents) === false) {
            throw new Exception("Failed to load SSH key from: {$keyPath}. phpseclib 2 (WHMCS 8) only supports RSA keys.");
        }
        return $key;
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

        $out = (string) $this->ssh->exec($cmd);
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
