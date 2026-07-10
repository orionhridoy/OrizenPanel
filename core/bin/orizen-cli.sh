#!/usr/bin/env bash
#
# orizen - command-line tool for Orizen Panel (calls the REST API with a token).
#
#   export ORIZEN_TOKEN=ozk_xxxxxxxx      # create one in the panel: System > API & Tokens
#   orizen <action> [key=value ...]
#
# Examples:
#   orizen backup_list
#   orizen site_add domain=example.com mode=new
#   orizen dash_stats
#
# The token can also live in /opt/orizen/data/cli_token (chmod 600) and the URL
# in ORIZEN_URL (default https://localhost:1337).
set -uo pipefail

URL="${ORIZEN_URL:-https://localhost:1337}"
TOKEN="${ORIZEN_TOKEN:-}"
[ -z "$TOKEN" ] && [ -r /opt/orizen/data/cli_token ] && TOKEN="$(cat /opt/orizen/data/cli_token)"

ACTION="${1:-}"
if [ -z "$ACTION" ] || [ "$ACTION" = "-h" ] || [ "$ACTION" = "--help" ]; then
  echo "usage: orizen <action> [key=value ...]"
  echo "       set ORIZEN_TOKEN (create a token in the panel: System > API & Tokens)"
  echo "examples: orizen dash_stats | orizen backup_list | orizen site_add domain=example.com mode=new"
  exit 0
fi
if [ -z "$TOKEN" ]; then
  echo "error: no API token. Create one in the panel (System > API & Tokens) then:  export ORIZEN_TOKEN=ozk_..." >&2
  exit 1
fi
shift || true

# token is sent both as a header (standard) and as a param (robust if Apache strips Authorization)
ARGS=(--data-urlencode "action=$ACTION" --data-urlencode "api_token=$TOKEN")
for kv in "$@"; do ARGS+=(--data-urlencode "$kv"); done

curl -k -sS -X POST -H "Authorization: Bearer ${TOKEN}" "${ARGS[@]}" "${URL%/}/"
echo
