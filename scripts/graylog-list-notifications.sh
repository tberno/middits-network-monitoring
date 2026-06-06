#!/usr/bin/env bash
set -euo pipefail

echo
echo "=== Graylog Notification Inspector ==="
echo

GL_BASE="${GL_BASE:-https://127.0.0.1}"

echo "Using Graylog base URL: $GL_BASE"
echo

read -r -p "Graylog API token: " GL_TOKEN
echo

if [ -z "$GL_TOKEN" ]; then
    echo "ERROR: token cannot be empty"
    exit 1
fi

GL_USER="$GL_TOKEN"
GL_PASS="token"

echo
echo "Testing Graylog API..."

if curl -ksS -u "$GL_USER:$GL_PASS" -H "X-Requested-By: cli" "$GL_BASE/api/system" >/tmp/graylog-system.json; then
    GL_API="$GL_BASE/api"
else
    echo "ERROR: Could not reach Graylog API at $GL_BASE/api/system"
    echo
    echo "Try running with a different base URL, for example:"
    echo "  GL_BASE=https://raccoon.middlebury.edu ./scripts/graylog-list-notifications.sh"
    echo
    exit 1
fi

echo "Graylog API found at: $GL_API"
echo

echo "Graylog system info:"
python3 -m json.tool /tmp/graylog-system.json
echo

BACKUP_DIR="graylog-notification-backups-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo
echo "Fetching event notifications..."

curl -ksS -u "$GL_USER:$GL_PASS" \
    -H "X-Requested-By: cli" \
    "$GL_API/events/notifications" \
    > "$BACKUP_DIR/notifications-list.json"

echo
echo "Saved notification list to:"
echo "  $BACKUP_DIR/notifications-list.json"
echo

echo "Notification summary:"
python3 - <<PY
import json

path = "$BACKUP_DIR/notifications-list.json"

with open(path) as f:
    data = json.load(f)

notifications = data.get("notifications") or data.get("event_notifications") or data.get("items") or []

if not notifications:
    print("No notifications found, or response format was unexpected.")
    print("Open the backup JSON file to inspect manually:")
    print(path)
else:
    for n in notifications:
        nid = n.get("id") or n.get("notification_id") or "unknown-id"
        title = n.get("title") or n.get("name") or "unknown-title"
        ntype = n.get("config", {}).get("type") or n.get("type") or "unknown-type"
        print(f"- ID: {nid}")
        print(f"  Title: {title}")
        print(f"  Type: {ntype}")
        print()
PY

echo
echo "Backing up each individual notification..."

python3 - <<PY > /tmp/graylog-notification-ids.txt
import json

with open("$BACKUP_DIR/notifications-list.json") as f:
    data = json.load(f)

notifications = data.get("notifications") or data.get("event_notifications") or data.get("items") or []

for n in notifications:
    nid = n.get("id") or n.get("notification_id")
    if nid:
        print(nid)
PY

while read -r NOTIF_ID; do
    [ -z "$NOTIF_ID" ] && continue

    echo "Backing up notification: $NOTIF_ID"

    curl -ksS -u "$GL_USER:$GL_PASS" \
        -H "X-Requested-By: cli" \
        "$GL_API/events/notifications/$NOTIF_ID" \
        > "$BACKUP_DIR/notification-$NOTIF_ID.json"
done < /tmp/graylog-notification-ids.txt

echo
echo "Done."
echo
echo "Next step:"
echo "Open the notification backup JSON for the Graylog alert-broker notification."
echo "We need the notification ID and JSON shape before safely updating it."
echo
echo "Backup directory:"
echo "  $BACKUP_DIR"
