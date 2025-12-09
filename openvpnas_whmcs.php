<?php
/**
 * WHMCS Server Provisioning Module: openvpnas-whmcs (Docker)
 *
 * Fixes:
 * - Username (email) is always written to WHMCS service so admin+client see it
 * - Client-area Change Password updates WHMCS password/password2 + AS password
 * - CreateAccount final-sync keeps AS password == WHMCS password first-login
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$clientFile = __DIR__ . '/lib/OpenVpnAsWhmcsDockerClient.php';
if (!file_exists($clientFile)) {
    throw new Exception("Missing client library file: " . $clientFile);
}
require_once $clientFile;

use ArpHost\OpenVpnAsWhmcs\OpenVpnAsWhmcsDockerClient;

function openvpnas_whmcs_MetaData()
{
    return [
        'DisplayName'    => 'openvpnas-whmcs',
        'APIVersion'     => '1.1',
        'RequiresServer' => true,
    ];
}

function openvpnas_whmcs_ConfigOptions()
{
    return [
        "Docker Host" => [
            "Type"        => "text",
            "Size"        => "40",
            "Default"     => "vpn-docker-host.yourdomain.com",
            "Description" => "Docker host where OpenVPN-AS container runs",
        ],
        "SSH Port" => [
            "Type"    => "text",
            "Size"    => "5",
            "Default" => "22",
        ],
        "SSH User" => [
            "Type"    => "text",
            "Size"    => "20",
            "Default" => "whmcs",
        ],
        "SSH Private Key Path" => [
            "Type"        => "text",
            "Size"        => "80",
            "Default"     => "/root/.ssh/id_rsa",
            "Description" => "Absolute path on WHMCS server",
        ],
        "Docker Container Name" => [
            "Type"        => "text",
            "Size"        => "25",
            "Default"     => "openvpn-as",
            "Description" => "Name of your OpenVPN-AS container",
        ],
        "Username Prefix (unused)" => [
            "Type"        => "text",
            "Size"        => "15",
            "Default"     => "vpn",
            "Description" => "Unused. Username is client email.",
        ],
        "VPN Portal URL" => [
            "Type"        => "text",
            "Size"        => "60",
            "Default"     => "",
            "Description" => "Client VPN login URL (you enter manually)",
        ],
    ];
}

/**
 * Normalize client email into an OpenVPN-AS-safe username.
 */
function _openvpnas_whmcs_emailUsername($email)
{
    $email = strtolower(trim($email));
    $email = preg_replace('/[^a-z0-9@._-]/', '', $email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

/* ---------- Provisioning ---------- */

function openvpnas_whmcs_CreateAccount($params)
{
    try {
        // Username = client email
        $email = $params['clientsdetails']['email'] ?? '';
        $username = _openvpnas_whmcs_emailUsername($email);
        if ($username === null) {
            return "Invalid client email for VPN username.";
        }

        // First-pass password (safe hex)
        $tempPassword = bin2hex(random_bytes(10));

        $ovpn = _openvpnas_whmcs_client($params);
        $container = $params['configoption5'] ?? 'openvpn-as';

        // Create user + set temp password
        $ovpn->createUser($container, $username, $tempPassword);

        // ✅ ALWAYS store username + password into service so admin/client see it
        localAPI('UpdateClientProduct', [
            'serviceid' => $params['serviceid'],
            'username'  => $username,
            'password'  => $tempPassword,
            'password2' => $tempPassword,
        ]);

        // FINAL SYNC: read back WHMCS final stored pw, set AS to match
        $finalPassword = $tempPassword;
        $api = localAPI('GetClientsProducts', [
            'serviceid' => $params['serviceid'],
        ]);
        if (isset($api['result']) && $api['result'] === 'success'
            && !empty($api['products']['product'][0]['password'])) {
            $finalPassword = $api['products']['product'][0]['password'];
        }

        $ovpn->setPassword($container, $username, $finalPassword);
        $ovpn->refresh($container);

        // Re-save username + final password (belt & suspenders)
        localAPI('UpdateClientProduct', [
            'serviceid' => $params['serviceid'],
            'username'  => $username,
            'password'  => $finalPassword,
            'password2' => $finalPassword,
        ]);

        return "success";
    } catch (Exception $e) {
        logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "OpenVPN-AS provisioning failed: " . $e->getMessage();
    }
}

function openvpnas_whmcs_ChangePassword($params)
{
    try {
        $username  = _openvpnas_whmcs_username($params);
        $ovpn      = _openvpnas_whmcs_client($params);
        $container = $params['configoption5'] ?? 'openvpn-as';

        $newPass = $params['password'] ?? '';
        if ($newPass === '') {
            return "No new password provided by WHMCS.";
        }

        $ovpn->setPassword($container, $username, $newPass);
        $ovpn->refresh($container);

        // ✅ keep WHMCS synced
        localAPI('UpdateClientProduct', [
            'serviceid' => $params['serviceid'],
            'username'  => $username,
            'password'  => $newPass,
            'password2' => $newPass,
        ]);

        return "success";
    } catch (Exception $e) {
        logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "ChangePassword failed: " . $e->getMessage();
    }
}

function openvpnas_whmcs_SuspendAccount($params)
{
    try {
        $username  = _openvpnas_whmcs_username($params);
        $ovpn      = _openvpnas_whmcs_client($params);
        $container = $params['configoption5'] ?? 'openvpn-as';

        $ovpn->setDisabled($container, $username, true);
        $ovpn->refresh($container);

        return "success";
    } catch (Exception $e) {
        logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "Suspend failed: " . $e->getMessage();
    }
}

function openvpnas_whmcs_UnsuspendAccount($params)
{
    try {
        $username  = _openvpnas_whmcs_username($params);
        $ovpn      = _openvpnas_whmcs_client($params);
        $container = $params['configoption5'] ?? 'openvpn-as';

        $ovpn->setDisabled($container, $username, false);
        $ovpn->refresh($container);

        return "success";
    } catch (Exception $e) {
        logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "Unsuspend failed: " . $e->getMessage();
    }
}

function openvpnas_whmcs_TerminateAccount($params)
{
    try {
        $username  = _openvpnas_whmcs_username($params);
        $ovpn      = _openvpnas_whmcs_client($params);
        $container = $params['configoption5'] ?? 'openvpn-as';

        $ovpn->deleteUser($container, $username);
        $ovpn->refresh($container);

        return "success";
    } catch (Exception $e) {
        logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "Terminate failed: " . $e->getMessage();
    }
}

/* ---------- Client Area ---------- */

function openvpnas_whmcs_ClientAreaCustomButtonArray($params)
{
    return [
        "Download VPN Profile" => "downloadprofile",
    ];
}

function openvpnas_whmcs_ClientArea($params)
{
    $action = $_REQUEST['customAction'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Download profile
    if ($action === 'downloadprofile') {
        try {
            $username  = _openvpnas_whmcs_username($params);
            $ovpn      = _openvpnas_whmcs_client($params);
            $container = $params['configoption5'] ?? 'openvpn-as';

            $profileText = $ovpn->getUserProfile($container, $username);

            $safeUser = preg_replace('/[^a-zA-Z0-9@._-]/', '_', $username);
            $filename = "ARPHost-VPN-{$safeUser}.ovpn";

            header('Content-Type: application/x-openvpn-profile');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($profileText));
            echo $profileText;
            exit;

        } catch (Exception $e) {
            logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
            return [
                'tabOverviewReplacementTemplate' => 'clientarea.tpl',
                'templateVariables' => [
                    'error' => "Unable to generate profile: " . $e->getMessage(),
                ],
            ];
        }
    }

    $vpnUrl = trim($params['configoption7'] ?? '');
    $successMsg = '';
    $errorMsg   = '';

    // Client-side password change
    if ($action === 'changepassword' && $method === 'POST') {
        $newPass = trim($_POST['new_vpn_password'] ?? '');
        $confirm = trim($_POST['confirm_vpn_password'] ?? '');

        if ($newPass === '' || $confirm === '') {
            $errorMsg = "Please enter and confirm your new VPN password.";
        } elseif ($newPass !== $confirm) {
            $errorMsg = "Passwords do not match.";
        } elseif (strlen($newPass) < 8) {
            $errorMsg = "Password must be at least 8 characters.";
        } else {
            try {
                $username  = _openvpnas_whmcs_username($params);
                $ovpn      = _openvpnas_whmcs_client($params);
                $container = $params['configoption5'] ?? 'openvpn-as';

                // Set password in AS
                $ovpn->setPassword($container, $username, $newPass);
                $ovpn->refresh($container);

                // ✅ Update WHMCS service creds too
                localAPI('UpdateClientProduct', [
                    'serviceid' => $params['serviceid'],
                    'username'  => $username,
                    'password'  => $newPass,
                    'password2' => $newPass,
                ]);

                $successMsg = "VPN password updated successfully.";
            } catch (Exception $e) {
                logModuleCall('openvpnas_whmcs', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
                $errorMsg = "Unable to change VPN password: " . $e->getMessage();
            }
        }
    }

    // Load fresh creds for display
    $vpnUser = '';
    $vpnPass = '';
    $api = localAPI('GetClientsProducts', ['serviceid' => $params['serviceid']]);
    if (isset($api['result']) && $api['result'] === 'success'
        && !empty($api['products']['product'][0])) {
        $p = $api['products']['product'][0];
        $vpnUser = trim($p['username'] ?? '');
        $vpnPass = $p['password'] ?? '';
        if ($vpnPass === '' && !empty($p['password2'])) {
            $vpnPass = $p['password2'];
        }
    }

    if ($vpnUser === '') $vpnUser = _openvpnas_whmcs_username($params);

    return [
        'tabOverviewReplacementTemplate' => 'clientarea.tpl',
        'templateVariables' => [
            'vpnUrl'  => $vpnUrl,
            'vpnUser' => $vpnUser,
            'vpnPass' => $vpnPass,
            'success' => $successMsg,
            'error'   => $errorMsg,
        ],
    ];
}

/* ---------- Helpers ---------- */

function _openvpnas_whmcs_username($params)
{
    // prefer stored service username
    $stored = trim($params['username'] ?? '');
    if ($stored !== '') return $stored;

    $email = $params['clientsdetails']['email'] ?? '';
    $u = _openvpnas_whmcs_emailUsername($email);
    if ($u !== null) return $u;

    return "vpn{$params['clientid']}_{$params['serviceid']}";
}

function _openvpnas_whmcs_client($params)
{
    return new OpenVpnAsWhmcsDockerClient(
        $params['configoption1'],
        (int)$params['configoption2'],
        $params['configoption3'],
        $params['configoption4']
    );
}
