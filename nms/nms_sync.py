import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-clean-nms"}

# NMS-Style: Rule at top, No Unique-ID, Concise for Slack
clean_template = """
🔴 *${event_definition_title}*
*Device:* ${foreach backlog item}${item.source}${end}
*Severity:* ${if event.priority == 3}CRITICAL${else}${if event.priority == 2}HIGH${else}WARNING${end}${end}
*Time:* ${event.timestamp}
*Link:* ${event_uri}

*Logs:*
${foreach backlog item}> ${item.message}${end}
""".strip()

notif_id = "69aadfb14e4cd76acf8d76ca"

resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
data = resp.json()

data["config"]["custom_message"] = clean_template
data["config"]["backlog_size"] = 1  # Keeping backlog small helps prevent Slack "Show More"

update_resp = requests.put(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers, data=json.dumps(data))

if update_resp.status_code == 200:
    print("SUCCESS: Concise template applied.")
    import os
    os.system("logger -n 127.0.0.1 -P 514 'LACP_INTF_DOWN: ae77: Testing expanded view'")
else:
    print(f"FAILED: {update_resp.text}")
