#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="openvpnas-whmcs-smoke"
ENV_FILE="tests/local.env"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}. Copy tests/local.env.example and fill it in."
  exit 1
fi

KEY_PATH=$(grep -E '^OVPNAS_SSH_KEY=' "${ENV_FILE}" | head -n1 | cut -d'=' -f2- | tr -d '\r')
KEY_PATH=$(echo "${KEY_PATH}" | xargs || true)
if [[ -z "${KEY_PATH}" ]]; then
  echo "OVPNAS_SSH_KEY is not set in ${ENV_FILE}."
  exit 1
fi
if [[ ! -f "${KEY_PATH}" ]]; then
  echo "SSH key not found at ${KEY_PATH}."
  exit 1
fi

docker build -t "${IMAGE_NAME}" -f tests/docker/Dockerfile .
docker run --rm \
  --env-file "${ENV_FILE}" \
  -e OVPNAS_SSH_KEY=/ssh_key \
  -v "${KEY_PATH}":/ssh_key:ro \
  -v "$(pwd)":/work \
  -w /work \
  "${IMAGE_NAME}" \
  bash -lc "composer install --working-dir=tests --no-interaction --no-progress && php tests/smoke.php"
