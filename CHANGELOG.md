# Changelog

## v1.0.3 (2026-11-22)
### Branding
- The server module shows as OpenVPN Access Server, and the addon as OpenVPN Access Server Admin (1.0.1).
- whmcs.json and logo.png for both, so the cards in WHMCS Apps and Integrations show the ARPHost logo and a description.
### Fixed
- Loads on WHMCS 8 (phpseclib 2) as well as WHMCS 9 (phpseclib 3). It used to need phpseclib 3 and fatal on WHMCS 8.
- An unreadable SSH key now gives a clear error instead of a PHP type error.
- Module logs mask the service password, server password and any password typed in the client area.
- Errors like a PHP type error are caught and logged instead of breaking the page.
- The client area password change form and the admin sync form check the WHMCS CSRF token.
- Client area output is escaped, and the VPN portal link only allows http and https.
- Clients see a plain message when something fails, and the SSH or sacli detail goes to the module log.
- The admin addon only accepts a plain folder name for the server module setting, escapes every cell, and opens one SSH connection per server when fetching live data.

## v1.0.2 (2026-02-21)
### Security
- Fixed DS002: Docker container now runs as non-root user (`phpuser` UID 1001)
- Fixed DS026: Added HEALTHCHECK instruction to Dockerfile for container monitoring
- Docker image now passes security scans for production deployment

## v1.0.1
- Default sacli path when blank
- Honor SSH connection timeout to avoid long hangs
