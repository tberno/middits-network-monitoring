import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-reorder"}

# Reordered Template: Logs pushed up directly under Device. Link buried at the bottom.
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *TRIGGERED*${end}\n"
    "*Device:* ${foreach backlog item}${item.source}${end}\n"
    "*Logs:* ${foreach backlog item}${item.message}${end}\n"
    "*Fired:* ${event.timestamp}\n"
    "${if event.is_resolved}*Resolved:* ${event.timestamp}\n${end}"
    "*Rule:* ${event_definition_title}\n"
    "*Link:* ${event_uri}"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

print("Syncing reordered layout...")
resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1 

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: Layout reordered. Triggering test...")
    import os
    os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Testing reordered layout'")
else:
    print(f"FAILED: {update_resp.text}")
