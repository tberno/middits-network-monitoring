import requests
import json
import os
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-final-polish"}

# Swapped TRIGGERED for ALERT
nms_template = (
    "${if event.is_resolved}🟢 *RESOLVED*${else}🔴 *ALERT*${end} | *Severity:* Priority ${event.priority}\n"
    "*Device:* ${foreach backlog item}${item.source}${end} | *Fired:* ${event.timestamp}\n"
    "*Rule:* ${event_definition_title}\n"
    "*Logs:* ${foreach backlog item}${item.message}${end}\n"
    "*Link:* <https://raccoon.middlebury.edu/graylog/alerts/${event.id}|View in Graylog>"
)

notif_id = "69aadfb14e4cd76acf8d76ca"

resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = nms_template
data["config"]["backlog_size"] = 1
data["config"]["include_title"] = False 
data["config"]["color"] = "#ff0000" 

requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

print("SUCCESS: Changed to ALERT. Triggering test...")
os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Testing the ALERT swap'")
