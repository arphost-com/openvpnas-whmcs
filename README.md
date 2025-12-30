# OpenVPN Access Server (OpenVPN-AS) Module for WHMCS

Provision and manage **OpenVPN Access Server** accounts directly from **WHMCS**.

This module is intended to automate common OpenVPN-AS customer lifecycle tasks from WHMCS, such as:
- Create / suspend / unsuspend / terminate VPN user accounts
- Reset credentials (optional / if supported by your build)
- Push connection profile links or onboarding info to the client area (optional / if supported)

> **Compatibility note**: WHMCS has two common module types:
> - **Server Modules** (`/modules/servers/...`) used for provisioning services
> - **Addon Modules** (`/modules/addons/...`) used for admin/client utility features
>
> This repository ships both:
> - Server module: `openvpnas_whmcs` (required)
> - Addon module: `openvpnas_whmcs_admin` (optional)

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Security Notes](#security-notes)
- [Installation](#installation)
  - [1) Download](#1-download)
  - [2) Upload to WHMCS](#2-upload-to-whmcs)
  - [3) Set File Permissions](#3-set-file-permissions)
  - [4) Activate the Module in WHMCS](#4-activate-the-module-in-whmcs)
- [OpenVPN-AS Setup](#openvpn-as-setup)
  - [Create an API/Admin User](#create-an-apiadmin-user)
  - [Allow API Access](#allow-api-access)
  - [Network / Firewall Requirements](#network--firewall-requirements)
- [WHMCS Configuration](#whmcs-configuration)
  - [Add the Server](#add-the-server)
  - [Create a Server Group (Recommended)](#create-a-server-group-recommended)
  - [Create a Product](#create-a-product)
  - [Provisioning Workflow](#provisioning-workflow)
- [Client Experience](#client-experience)
- [Troubleshooting](#troubleshooting)
- [Logging / Debug](#logging--debug)
- [Updating](#updating)
- [Support / Contributions](#support--contributions)
- [License](#license)

---

## Features

Depending on the version in this repo, the module may support:

- ✅ Create VPN users on order activation
- ✅ Suspend VPN users on overdue/non-payment
- ✅ Unsuspend VPN users on payment
- ✅ Terminate VPN users on cancellation/termination
- ✅ Custom fields for username / plan / limits (if implemented)
- ✅ Module command buttons in WHMCS Admin (Create/Suspend/Unsuspend/Terminate)

If you don’t see a feature working, confirm:
1) You installed the module in the correct WHMCS directory  
2) Your OpenVPN-AS API endpoint + credentials are correct  
3) Your product has the correct **Module Name** selected in WHMCS  

---

## Requirements

### WHMCS
- WHMCS 8.x+ recommended (older versions may work but are not tested)

### PHP
- PHP 8.0+ recommended
- Extensions typically required:
  - `curl`
  - `json`
  - `openssl`

### OpenVPN Access Server
- OpenVPN Access Server (OpenVPN-AS) reachable from the WHMCS server
- Admin/API user credentials for authentication to OpenVPN-AS
- SSH access to the OpenVPN-AS host (Docker or direct install)

---

## Security Notes

- **Use HTTPS** between WHMCS and OpenVPN-AS whenever possible.
- If using a dedicated API/admin account, restrict it:
  - Use the least privilege possible (where supported)
  - Restrict access by IP (WHMCS server IP) at the firewall level
- Treat credentials as secrets:
  - Store them only in WHMCS server configuration fields
  - Do not commit credentials into the repo

---

## Installation

### 1) Download

Option A (recommended): Clone the repo
```bash
git clone https://github.com/arphost-com/openvpnas-whmcs.git
````

Option B: Download ZIP

* Open the repo in GitHub
* Click **Code → Download ZIP**
* Extract locally

---

### 2) Upload to WHMCS

Most WHMCS provisioning modules install here:

```
/path/to/whmcs/modules/servers/<module_folder>/
```

**Example**
Copy the server module folder from this repo to:

```
/path/to/whmcs/modules/servers/openvpnas_whmcs/
```

After upload, you should have something like:

```
modules/
  servers/
    openvpnas_whmcs/
      openvpnas_whmcs.php
      lib/
      clientarea.tpl
      ...
```

Optional addon module:

```
modules/
  addons/
    openvpnas_whmcs_admin/
      openvpnas_whmcs_admin.php
      ...
```

Server modules will not appear in WHMCS unless they are inside `modules/servers/<name>/`.

Full install tree (recommended layout):

```
whmcs/
  modules/
    servers/
      openvpnas_whmcs/
        openvpnas_whmcs.php
        lib/
          OpenVpnAsWhmcsDockerClient.php
        clientarea.tpl
        README.md
        LICENSE
    addons/
      openvpnas_whmcs_admin/
        openvpnas_whmcs_admin.php
```

ZIP layout (extract, then copy the two folders into `modules/`):

```
openvpnas-whmcs/
  openvpnas_whmcs.php
  lib/
  clientarea.tpl
  addons/
    openvpnas_whmcs_admin/
      openvpnas_whmcs_admin.php
```

---

### 3) Set File Permissions

WHMCS needs to read module files.

Typical safe permissions:

* Directories: `755`
* Files: `644`

Example:

```bash
find /path/to/whmcs/modules/servers/openvpnas -type d -exec chmod 755 {} \;
find /path/to/whmcs/modules/servers/openvpnas -type f -exec chmod 644 {} \;
```

If your module writes cache/log files inside its directory (not ideal, but sometimes done), the writable directory should be `755` and owned by the web user, or moved to a safe writable location.

---

### 4) Activate the Module in WHMCS

1. Log into WHMCS Admin
2. Go to: **Configuration → System Settings → Servers**
3. Click **Add New Server**
4. Under **Type**, select the module name: `openvpnas_whmcs`

If you **do not see the module** in the Type dropdown:

* Confirm the folder is in `modules/servers/openvpnas_whmcs/`
* Confirm the main module file name is `openvpnas_whmcs.php`
* Check **Utilities → Logs → Activity Log** for PHP/module load errors

---

## OpenVPN-AS Setup

### Create an API/Admin User

1. Log into OpenVPN-AS Admin UI (usually: `https://YOUR-AS-HOST:943/admin`)
2. Create a dedicated user, for example:

   * Username: `whmcs_provision`
   * Strong password
3. Ensure the account has permissions required to manage users (varies by OpenVPN-AS version)

> Best practice: don’t use the primary `openvpn` admin account.

---

### Allow API Access

OpenVPN-AS versions differ in how they expose management APIs. Common patterns:

* XML-RPC endpoint (often `/RPC2`)
* REST endpoints (varies by release / config)

**What you need from OpenVPN-AS:**

* Hostname/IP
* Management/API port (commonly `943` for admin UI; your API may match)
* API endpoint path (if applicable)
* Credentials (username/password or token)

If your module requires a specific endpoint (e.g. `/RPC2`), ensure it is reachable from your WHMCS server.

---

### Network / Firewall Requirements

From the WHMCS server to OpenVPN-AS:

* Allow outbound TCP to the OpenVPN-AS admin/API port (commonly `943`)
* Allow inbound responses (stateful firewall handles this automatically)

If OpenVPN-AS is behind Cloudflare/WAF:

* Ensure WHMCS server IP is allowed
* Ensure API endpoints are not blocked

---

## WHMCS Configuration

### Add the Server

In **Configuration → System Settings → Servers → Add New Server**:

Fill in:

* **Name**: `OpenVPN-AS 1` (anything)
* **Hostname**: `vpn.example.com` (or IP)
* **IP Address**: optional (WHMCS field; depends on your module)
* **Type**: select your module (example: `openvpnas`)
* **Username / Password**: set to your OpenVPN-AS API/admin user (or use module access key fields if your module uses them)
* **Secure**: enable if your module uses HTTPS calls

> Some modules store API fields inside the “Module Settings” section instead of Username/Password.
> Use whatever your module exposes on this page.

Click **Save Changes**.

---

### Create a Server Group (Recommended)

If you run multiple OpenVPN-AS nodes:

1. Go to **Server Groups**
2. Create a group named `OpenVPN-AS`
3. Add your OpenVPN-AS server(s) to the group
4. Choose an allocation strategy (Fill/Least Used, depending on your setup)

---

### Create a Product

1. Go to **Configuration → System Settings → Products/Services**
2. Create a **New Product**
3. Set **Product Type**: typically “Other” or “Hosting” (your preference)
4. Go to the **Module Settings** tab:

   * **Module Name**: select `openvpnas_whmcs`
   * **Server Group**: select `OpenVPN-AS` (or choose the single server)
   * Fill in the module options:

     * **OpenVPN-AS Host (SSH)**: hostname/IP of the AS host
     * **SSH Port/User/Key**: SSH access used for `sacli`
     * **Execution Mode**:
       * `docker` (default): run `sacli` inside the container
       * `direct`: run `sacli` on the host (non-Docker installs)
     * **Docker Container Name**: required only for `docker` mode
     * **sacli Path**: default `/usr/local/openvpn_as/scripts/sacli`
     * **Apply Changes (sacli start)**: enable only if your AS needs it
     * **SSH Timeout (seconds)**: prevents long-running module actions

#### Recommended Custom Fields

Many VPN modules rely on **Custom Fields** to store the VPN username, plan, or limits.

Go to the product’s **Custom Fields** and add fields like:

* `VPN Username` (Admin Only: ❌) — visible to client if you want them to see it
* `Device Limit` (Admin Only: ✅) — internal
* `Static IP` (Admin Only: ✅) — if supported

> Only add fields that your module actually uses.

---

### Provisioning Workflow

Once configured, WHMCS automation typically works like this:

* **Order Accepted / Payment Captured**

  * WHMCS runs **Create** module command
  * Module creates VPN user in OpenVPN-AS
* **Invoice Overdue**

  * WHMCS runs **Suspend** module command (if enabled in automation settings)
* **Invoice Paid**

  * WHMCS runs **Unsuspend**
* **Service Terminated**

  * WHMCS runs **Terminate**

To test immediately:

1. Open a client’s product/service in WHMCS Admin
2. Click the **Module Commands** dropdown
3. Run:

   * Create
   * Suspend
   * Unsuspend
   * Terminate

---

## Client Experience

Depending on your implementation, clients may receive:

* An email with VPN credentials
* A link to download their connection profile
* Instructions to install OpenVPN Connect client
* Access details in the WHMCS Client Area

If you want a polished onboarding flow, consider adding:

* A “Getting Started” email template
* A client-area panel output (if your module supports it)
* Links to:

  * OpenVPN Connect for Windows/macOS/Linux
  * OpenVPN Connect for iOS/Android

---

## Admin Addon (Client List)

This repo includes an optional WHMCS addon module that lists all services using
this server module in one place.

Install:

1. Copy `addons/openvpnas_whmcs_admin/` to:

   ```
   /path/to/whmcs/modules/addons/openvpnas_whmcs_admin/
   ```

2. In WHMCS Admin, go to **Configuration → System Settings → Addon Modules**.
3. Activate **OpenVPN-AS Clients**.

The addon shows service ID, client, status, VPN username, host, and basic warnings.
Enable **Fetch live OpenVPN-AS data (SSH)** to show last login, last IP, disabled
state, and the latest module log entry per service.

---

## Smoke Tests (CLI)

A simple CLI smoke test is available for quick validation against a live
OpenVPN-AS host. It uses the same SSH + `sacli` approach as the module.

1. Copy the example env file and fill it in:

   ```
   cp tests/local.env.example tests/local.env
   ```

2. Run the test:

   ```
   php tests/smoke.php
   ```

`tests/local.env` is ignored by git. Use `OVPNAS_TEST_PROFILE=yes` if you want
the test to fetch a user profile as part of the run.

### Smoke Tests (Docker)

You can run the smoke test inside a container (no local PHP required).

1. Ensure `tests/local.env` is populated.
2. Run:

   ```
   bash tests/docker/run-smoke.sh
   ```

This builds a small PHP image, installs test-only dependencies, and runs
`php tests/smoke.php` inside the container. The script reads `OVPNAS_SSH_KEY`
from `tests/local.env` and mounts that key into the container.

---

## Troubleshooting

### Module not showing up in WHMCS

* Confirm path: `WHMCS_ROOT/modules/servers/<module_name>/`
* Confirm main PHP file exists and is named correctly
* Check: **Utilities → Logs → Activity Log**
* Enable WHMCS error reporting temporarily (see WHMCS docs)

### “Could not connect” / timeout

* From WHMCS server, test connectivity:

  ```bash
  curl -vk https://vpn.example.com:943/
  ```
* Confirm firewall rules allow WHMCS → OpenVPN-AS on the correct port
* Confirm DNS resolves correctly

### Module command hangs or feels slow

* Set **Apply Changes (sacli start)** to `no` unless your AS requires it.
* Reduce **SSH Timeout (seconds)** to a lower value (e.g., 15–20).
* Check **Utilities → Logs → Module Log** for the last request.

### Authentication errors

* Verify OpenVPN-AS username/password
* Try logging into OpenVPN-AS admin UI with the same credentials
* If using API tokens, confirm token validity and scope (if applicable)

### User not created / wrong username

* Confirm what field the module uses for username:

  * Client email?
  * Service username?
  * A custom field like `VPN Username`?
* Ensure the product includes the required custom field(s)

---

## Logging / Debug

WHMCS logs helpful for module debugging:

* **Utilities → Logs → Activity Log**
* **Utilities → Logs → Module Log** (enable it)

  * Turn on **Module Debug Log**
  * Re-run a module command (Create/Suspend/etc.)
  * Review request/response payloads

> **Important**: Module log may capture credentials. Disable debug logging when finished.

---

## Updating

If installed via git:

```bash
cd /path/to/whmcs/modules/servers/openvpnas_whmcs
git pull
```

If installed via ZIP:

* Download latest ZIP
* Replace files in `modules/servers/openvpnas_whmcs/`
* Re-test a module command

---

## Release Status

* `v1.0.0` is production.
* Other tags/branches are for testing.

## Support / Contributions

Issues and PRs are welcome:

* [https://github.com/arphost-com/openvpnas-whmcs/issues](https://github.com/arphost-com/openvpnas-whmcs/issues)

When reporting a bug, include:

* WHMCS version
* PHP version
* OpenVPN-AS version
* Exact error message(s)
* WHMCS Module Log output (redact secrets)

---
