# Changelog

## v1.0.2 (2026-02-21)
### Security
- Fixed DS002: Docker container now runs as non-root user (`phpuser` UID 1001)
- Fixed DS026: Added HEALTHCHECK instruction to Dockerfile for container monitoring
- Docker image now passes security scans for production deployment

## v1.0.1
- Default sacli path when blank
- Honor SSH connection timeout to avoid long hangs
