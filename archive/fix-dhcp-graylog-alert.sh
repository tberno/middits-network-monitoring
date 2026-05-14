#!/usr/bin/env bash
set -euo pipefail

: "${GL_USER:?Set GL_USER}"
: "${GL_PASS:?Set GL_PASS}"
GL_URL="${GL_URL:-http://127.0.0.1:9000/graylog/api}"
TITLE="Network Services: DHCP Lease Exhaustion"

BACKUP="/tmp/graylog-dhcp-alert-before-fix-$(date +%Y%m%d-%H%M%S).json"

echo "Finding event definition: $TITLE"

curl -sS -u "$GL_USER:$GL_PASS" \
  -H "Accept: application/json" \
  "$GL_URL/events/definitions" \
  > /tmp/graylog-event-definitions.json

ID="$(jq -r --arg title "$TITLE" '.event_definitions[] | select(.title==$title) | .id' /tmp/graylog-event-definitions.json | head -1)"

if [[ -z "$ID" || "$ID" == "null" ]]; then
  echo "Could not find event definition titled: $TITLE"
  exit 1
fi

echo "Found ID: $ID"
echo "Backing up to $BACKUP"

curl -sS -u "$GL_USER:$GL_PASS" \
  -H "Accept: application/json" \
  "$GL_URL/events/definitions/$ID" \
  | jq > "$BACKUP"

jq '
  .config.group_by = ["source"]
  | .notification_settings.backlog_size = 5
  | .notification_settings.grace_period_ms = 300000
' "$BACKUP" > /tmp/graylog-dhcp-alert-fixed.json

echo "Updating DHCP alert..."

curl -sS -u "$GL_USER:$GL_PASS" \
  -X PUT \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Requested-By: fix-dhcp-alert-grouping" \
  "$GL_URL/events/definitions/$ID" \
  --data-binary @/tmp/graylog-dhcp-alert-fixed.json \
  -w "\nHTTP %{http_code}\n"

echo
echo "Verify:"
curl -sS -u "$GL_USER:$GL_PASS" \
  -H "Accept: application/json" \
  "$GL_URL/events/definitions/$ID" \
| jq '{title, query: .config.query, group_by: .config.group_by, backlog_size: .notification_settings.backlog_size, grace_period_ms: .notification_settings.grace_period_ms}'
