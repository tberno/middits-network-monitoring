import requests
import json
import time
import os
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-full"}

# The exact vertical layout of the LibreNMS bot
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *TRIGGERED*${end}\n"
    "*Device:* ${foreach backlog item}${item.source}${end}\n"
    "*Severity:* ${if event.priority == 3}CRITICAL${else}${if event.priority == 2}HIGH${else}WARNING${end}${end}\n"
    "*Fired:* ${event.timestamp}\n"
    "${if event.is_resolved}*Resolved:* ${event.timestamp}\n${end}"
    "*Rule:* ${event_definition_title}\n"
    "*Link:* <https://raccoon.middlebury.edu/graylog/alerts/${event.id}|View in Graylog>\n\n"
    "*Logs:*\n${foreach backlog item}> ${item.message}${end}"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

print("Syncing Full NMS layout...")
resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: Full NMS layout applied.")
    print("Waiting 5 seconds for Graylog to cache the template...")
    time.sleep(5)
    print("Firing test log...")
    os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Final layout match'")
else:
    print(f"FAILED: {update_resp.text}")
