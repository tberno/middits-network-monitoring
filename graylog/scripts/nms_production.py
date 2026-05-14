import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-production"}

# The final 3-Line NMS Layout
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *TRIGGERED*${end} | *Rule:* ${event_definition_title}\n"
    "*Device:* ${foreach backlog item}${item.source}${end} | *Fired:* ${event.timestamp}\n"
    "*Logs:* ${foreach backlog item}${item.message}${end} | <https://raccoon.middlebury.edu/graylog/alerts/${event.id}|View in Graylog>"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: Production layout synced to Graylog.")
else:
    print(f"FAILED: {update_resp.text}")
