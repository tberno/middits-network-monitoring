import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-mirror"}

# This template mimics the LibreNMS format exactly
# It handles Fired vs Resolved headers automatically
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *TRIGGERED*${end}\n"
    "*Device:* ${foreach backlog item}${item.source}${end}\n"
    "*Fired:* ${event.timestamp}\n"
    "${if event.is_resolved}*Resolved:* ${event.timestamp}${end}\n"
    "*Rule:* ${event_definition_title}\n"
    "*Link:* ${event_uri}\n\n"
    "*Logs:* ${foreach backlog item}${item.message}${end}"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1 # Keep it expanded

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: NMS-style mirror applied.")
    import os
    os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Final format sync test'")
else:
    print(f"FAILED: {update_resp.text}")
