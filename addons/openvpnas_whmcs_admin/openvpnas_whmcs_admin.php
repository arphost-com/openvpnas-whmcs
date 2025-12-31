<?php
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function openvpnas_whmcs_admin_config()
{
    return [
        "name"        => "OpenVPN-AS Clients",
        "description" => "Admin view for OpenVPN-AS services managed by this module.",
        "author"      => "ARPHost, LLC",
        "version"     => "1.0.0",
        "language"    => "english",
        "fields"      => [
            "server_module_folder" => [
                "FriendlyName" => "Server Module Folder",
                "Type"         => "text",
                "Size"         => "30",
                "Default"      => "openvpnas_whmcs",
                "Description"  => "Folder name under modules/servers/",
            ],
        ],
    ];
}

function openvpnas_whmcs_admin_activate()
{
    return ["status" => "success", "description" => "Addon activated"];
}

function openvpnas_whmcs_admin_deactivate()
{
    return ["status" => "success", "description" => "Addon deactivated"];
}

function openvpnas_whmcs_admin_output($vars)
{
    $moduleName = 'openvpnas_whmcs';
    $moduleFolder = $vars['moduleconfig']['server_module_folder'] ?? 'openvpnas_whmcs';
    $moduleFolder = trim($moduleFolder) !== '' ? trim($moduleFolder) : 'openvpnas_whmcs';

    $live = isset($_GET['live']) && $_GET['live'] === '1';
    $liveErrors = [];

    if ($live) {
        $clientPath = ROOTDIR . '/modules/servers/' . $moduleFolder . '/lib/OpenVpnAsWhmcsDockerClient.php';
        if (!file_exists($clientPath)) {
            $liveErrors[] = "Client library not found at {$clientPath}.";
            $live = false;
        } else {
            require_once $clientPath;
        }
    }

    $services = Capsule::table('tblhosting')
        ->join('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->select(
            'tblhosting.id as service_id',
            'tblhosting.domainstatus as service_status',
            'tblhosting.username as vpn_username',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email',
            'tblproducts.name as product_name',
            'tblproducts.configoption1 as host',
            'tblproducts.configoption2 as ssh_port',
            'tblproducts.configoption3 as ssh_user',
            'tblproducts.configoption4 as ssh_key',
            'tblproducts.configoption5 as container_name',
            'tblproducts.configoption8 as mode',
            'tblproducts.configoption9 as sacli_path',
            'tblproducts.configoption11 as ssh_timeout'
        )
        ->where('tblproducts.servertype', $moduleName)
        ->orderBy('tblhosting.id', 'desc')
        ->get();

    echo '<h2>OpenVPN-AS Clients</h2>';
    echo '<p>Services using the <strong>openvpnas_whmcs</strong> server module.</p>';

    echo '<form method="get" style="margin: 10px 0;">'
        . '<input type="hidden" name="module" value="openvpnas_whmcs_admin" />'
        . '<label style="margin-right: 8px;">'
        . '<input type="checkbox" name="live" value="1"' . ($live ? ' checked' : '') . '> '
        . 'Fetch live OpenVPN-AS data (SSH)'
        . '</label>'
        . '<button type="submit" class="btn btn-sm btn-default">Refresh</button>'
        . '</form>';

    if ($liveErrors) {
        foreach ($liveErrors as $msg) {
            echo '<div class="alert alert-warning">' . htmlspecialchars($msg) . '</div>';
        }
    }

    if ($services->isEmpty()) {
        echo '<div class="alert alert-info">No services found for this module.</div>';
        return;
    }

    echo '<table class="table table-striped table-bordered">';
    echo '<thead><tr>'
        . '<th>Service ID</th>'
        . '<th>Client</th>'
        . '<th>Email</th>'
        . '<th>Product</th>'
        . '<th>Status</th>'
        . '<th>VPN Username</th>'
        . '<th>Host</th>'
        . '<th>Mode</th>'
        . '<th>Last Login</th>'
        . '<th>Last IP</th>'
        . '<th>Disabled</th>'
        . '<th>Last Module Log</th>'
        . '<th>Warnings</th>'
        . '</tr></thead><tbody>';

    foreach ($services as $service) {
        $warnings = [];
        if (trim((string)$service->vpn_username) === '') {
            $warnings[] = 'Missing VPN username';
        }
        if (trim((string)$service->host) === '') {
            $warnings[] = 'Missing host';
        }
        if (trim((string)$service->mode) === '') {
            $warnings[] = 'Mode not set';
        }

        $lastLogin = 'n/a';
        $lastIp = 'n/a';
        $disabled = 'n/a';
        $moduleLog = 'n/a';

        if ($live && class_exists('ArpHost\\OpenVpnAsWhmcs\\OpenVpnAsWhmcsDockerClient')) {
            try {
                $mode = strtolower(trim((string)$service->mode));
                $mode = $mode !== '' ? $mode : 'docker';
                $sacliPath = trim((string)$service->sacli_path) ?: '/usr/local/openvpn_as/scripts/sacli';
                $timeout = (int)($service->ssh_timeout ?: 30);
                if ($timeout <= 0) {
                    $timeout = 30;
                }

                $client = new ArpHost\OpenVpnAsWhmcs\OpenVpnAsWhmcsDockerClient(
                    (string)$service->host,
                    (int)($service->ssh_port ?: 22),
                    (string)$service->ssh_user,
                    (string)$service->ssh_key,
                    $mode,
                    $sacliPath,
                    $timeout
                );

                $container = (string)$service->container_name;
                $username = trim((string)$service->vpn_username);
                if ($username !== '') {
                    $props = $client->getUserProps($container, $username);
                    $lastLogin = $props['last_successful_login'] ?? ($props['last_login'] ?? 'n/a');
                    $lastIp = $props['last_successful_login_ip'] ?? ($props['last_login_ip'] ?? 'n/a');
                    $disabled = isset($props['disabled']) ? (string)$props['disabled'] : 'n/a';
                }
            } catch (Exception $e) {
                $warnings[] = 'Live fetch failed';
            }
        }

        try {
            $log = Capsule::table('tblmodulelog')
                ->select('action', 'response', 'date')
                ->where('module', $moduleName)
                ->where('relid', (int)$service->service_id)
                ->orderBy('id', 'desc')
                ->first();
            if ($log) {
                $moduleLog = trim((string)$log->action);
                $resp = trim((string)$log->response);
                if ($resp !== '') {
                    $moduleLog .= ' - ' . $resp;
                }
            }
        } catch (Exception $e) {
            $moduleLog = 'n/a';
        }

        $clientName = trim($service->firstname . ' ' . $service->lastname);
        $warningText = $warnings ? implode('; ', $warnings) : 'OK';

        echo '<tr>'
            . '<td>' . (int)$service->service_id . '</td>'
            . '<td>' . htmlspecialchars($clientName) . '</td>'
            . '<td>' . htmlspecialchars($service->email) . '</td>'
            . '<td>' . htmlspecialchars($service->product_name) . '</td>'
            . '<td>' . htmlspecialchars($service->service_status) . '</td>'
            . '<td>' . htmlspecialchars($service->vpn_username) . '</td>'
            . '<td>' . htmlspecialchars($service->host) . '</td>'
            . '<td>' . htmlspecialchars($service->mode) . '</td>'
            . '<td>' . htmlspecialchars((string)$lastLogin) . '</td>'
            . '<td>' . htmlspecialchars((string)$lastIp) . '</td>'
            . '<td>' . htmlspecialchars((string)$disabled) . '</td>'
            . '<td>' . htmlspecialchars((string)$moduleLog) . '</td>'
            . '<td>' . htmlspecialchars($warningText) . '</td>'
            . '</tr>';
    }

    echo '</tbody></table>';
    echo '<p>For provisioning errors, check <strong>Utilities → Logs → Module Log</strong>.</p>';
}
