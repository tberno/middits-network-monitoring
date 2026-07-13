import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
    "X-Requested-By": "python-nms-formatter"
}

# The NMS-Style Template
# Note: Graylog doesn't natively calculate "Downtime" in a string format easily, 
# so we'll use the 'Search Within' window as a reference.
nms_template = """
${if event.is_resolved}🟢 RESOLVED${else}🔴 TRIGGERED${end}
*Device:* ${foreach backlog item}${item.source}${end}
*Severity:* ${if event.priority == 3}CRITICAL${else if event.priority == 2}HIGH${else}WARNING${end}
*Fired:* ${event.timerange_start}
${if event.is_resolved}*Resolved:* ${event.timestamp}${end}
*Unique-ID:* ${event.id}
*Rule:* ${event.title}
*Link:* ${event_uri}

*Recent Logs:*
${foreach backlog item}
> ${item.message}
${end}
""".strip()

auth = HTTPBasicAuth(username, password)

# 1. Update the Notification Template
print("1. Updating Notification Template to NMS Style...")
notif_resp = requests.get(f"{graylog_url}/events/notifications", auth=auth, headers=headers)
for n in notif_resp.json().get("notifications", []):
    if "slack" in n.get("title").lower() or "discord" in n.get("title").lower():
        n["config"]["custom_message"] = nms_template
        requests.put(f"{graylog_url}/events/notifications/{n['id']}", auth=auth, headers=headers, data=json.dumps(n))
        print(f"   -> Updated: {n.get('title')}")

# 2. Update Event Definitions (Re-enabling Resolved pings for the 🟢 icon)
print("\n2. Re-enabling Resolution Pings for visual status...")
event_resp = requests.get(f"{graylog_url}/events/definitions", auth=auth, headers=headers)
targets = ["Network: Hardware & Layer 2 Alert", "Network: Routing Protocol Alert", "Network: Configuration Changed", "Firewall: Palo Alto State Change", "Telecom & Systems Alert"]

for ed in event_resp.json().get("event_definitions", []):
    if ed.get("title") in targets:
        ed["notification_settings"]["notify_on_resolved"] = True
        requests.put(f"{graylog_url}/events/definitions/{ed['id']}", auth=auth, headers=headers, data=json.dumps(ed))
        print(f"   -> Resolution Pings ON for: {ed.get('title')}")

print("\nFormatting complete.")
