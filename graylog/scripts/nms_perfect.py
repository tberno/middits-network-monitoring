import requests
import json
import time
import os
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-perfect"}

# 3-Line Template to completely bypass Slack's 5-line folding limit.
# Hardcoding the raccoon URL since the internal event_uri is blank.
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *TRIGGERED*${end} | *Rule:* ${event_definition_title}\n"
    "*Device:* ${foreach backlog item}${item.source}${end} | *Fired:* ${event.timestamp}\n"
    "*Logs:* ${foreach backlog item}${item.message}${end} | <https://raccoon.middlebury.edu/graylog/alerts/${event.id}|View in Graylog>"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

print("Syncing 3-line layout...")
resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: 3-Line layout applied in database.")
    print("Waiting 5 seconds for Graylog to refresh its cache...")
    time.sleep(5)
    print("Firing the log message now...")
    os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Testing the 3-line layout'")
else:
    print(f"FAILED: {update_resp.text}")
