{if $success}
    <div class="alert alert-success mb-3">{$success}</div>
{/if}

{if $error}
    <div class="alert alert-danger mb-3">{$error}</div>
{/if}

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">VPN Access Details</h5>
    </div>

    <div class="card-body">
        <div class="row g-3">

            <div class="col-12">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <div class="text-muted small">VPN Portal</div>
                        {if $vpnUrl neq ""}
                            <a href="{$vpnUrl}" target="_blank" rel="noopener noreferrer">
                                {$vpnUrl}
                            </a>
                        {else}
                            <span class="text-muted">(Not set yet)</span>
                        {/if}
                    </div>

                    {if $vpnUrl neq ""}
                        <a class="btn btn-outline-primary btn-sm"
                           href="{$vpnUrl}" target="_blank" rel="noopener noreferrer">
                            Open VPN Portal
                        </a>
                    {/if}
                </div>
            </div>

            <div class="col-md-6">
                <div class="text-muted small mb-1">Username</div>
                <input type="text"
                       class="form-control font-monospace"
                       readonly
                       value="{$vpnUser}">
            </div>

            <div class="col-md-6">
                <div class="text-muted small mb-1">Password</div>
                <input type="text"
                       class="form-control font-monospace"
                       readonly
                       value="{$vpnPass}">
            </div>

            <div class="col-12">
                <hr class="my-2">
            </div>

            <div class="col-12">
                <div class="text-muted small mb-2">VPN Profile (.ovpn)</div>
                <p class="mb-3">
                    Download this profile and import it into <strong>OpenVPN Connect</strong>.
                </p>

                <a class="btn btn-primary"
                   href="clientarea.php?action=productdetails&id={$serviceid}&customAction=downloadprofile">
                    Download VPN Profile
                </a>
            </div>

        </div>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Change VPN Password</h5>
    </div>

    <div class="card-body">
        <form method="post"
              action="clientarea.php?action=productdetails&id={$serviceid}&customAction=changepassword">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">New Password</label>
                    <input type="password"
                           name="new_vpn_password"
                           class="form-control"
                           minlength="8"
                           required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password"
                           name="confirm_vpn_password"
                           class="form-control"
                           minlength="8"
                           required>
                </div>

                <div class="col-12">
                    <button class="btn btn-outline-primary" type="submit">
                        Update VPN Password
                    </button>
                    <div class="text-muted small mt-2">
                        Password must be at least 8 characters. Use only standard characters.
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
