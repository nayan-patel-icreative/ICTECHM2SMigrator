#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -n "${PORT:-}" ]]; then
  TUNNEL_PORT="$PORT"
elif [[ -f "$ROOT_DIR/.dev-port" ]]; then
  TUNNEL_PORT="$(cat "$ROOT_DIR/.dev-port")"
else
  TUNNEL_PORT="8080"
fi

if ! command -v cloudflared >/dev/null 2>&1; then
  echo "Error: cloudflared not found. Install it first: https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/install-and-setup/installation/"
  exit 1
fi

echo "Starting Cloudflare tunnel to http://localhost:$TUNNEL_PORT"
echo "Tunnel logs (look for https://*.trycloudflare.com):"
echo "--------------------------------------------------"

cloudflared tunnel --no-autoupdate --url "http://localhost:$TUNNEL_PORT"
