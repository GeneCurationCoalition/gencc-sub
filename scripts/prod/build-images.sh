#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SEARCH_DIR="${ROOT_DIR}/../gencc-search"

if ! command -v podman >/dev/null 2>&1; then
  echo "podman not found on PATH" >&2
  exit 1
fi

echo "Building gencc-sub image (Dockerfile)..."
podman build -t gencc-sub:local "${ROOT_DIR}"

echo "Building gencc-search image (Dockerfile.prod)..."
podman build -f "${SEARCH_DIR}/Dockerfile.prod" -t gencc-search:local "${SEARCH_DIR}"

echo "Done: built gencc-sub:local and gencc-search:local"
