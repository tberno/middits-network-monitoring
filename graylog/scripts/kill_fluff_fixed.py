import requests
import json
import os
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-fluff-killer-fixed"}

# The exact LibreNMS vertical layout (Math removed)
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *TRIGGERED*${end}\n"
    "*Device:* ${foreach backlog item}${item.source}${end}\n"
    "*Severity:* Priority ${event.priority}\n"
    "*Fired:* ${event.timestamp}\n"
    "${if event.is_resolved}*Resolved:* ${event.timestamp}\n${end}"
    "*Rule:* ${event_definition_title}\n"
    "*Link:* <https://raccoon.middlebury.edu/graylog/alerts/${event.id}|View in Graylog>\n\n"
    "*Logs:*\n${foreach backlog item}> ${item.message}${end}"
)

# Your specific Slack Notification ID
notif_id = "69aadfb14e4cd76acf8d76ca"

resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1
data["config"]["include_title"] = False # Kills the Graylog title
data["config"]["color"] = "#ff0000" # Sets the attachment bar to Red

requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

print("SUCCESS: Fixed layout applied. Triggering test...")
os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Testing fixed template'")
