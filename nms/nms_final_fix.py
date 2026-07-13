import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-final"}

# Using the concatenated string format that succeeded at 9:45 AM
# Removed Unique-ID and moved the Rule Name to the top header
nms_template = (
    "🔴 *${event.title}*\n"
    "*Device:* ${foreach backlog item}${item.source}${end}\n"
    "*Fired:* ${event.timerange_start}\n"
    "*Link:* ${event_uri}\n\n"
    "*Logs:*\n"
    "${foreach backlog item}${item.message}${end}"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

print(f"Syncing stable layout to {notif_id}...")
resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1 # Forces Slack to keep it expanded

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: Stable layout applied. Triggering...")
    import os
    os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Final layout verification'")
else:
    print(f"FAILED: {update_resp.text}")
