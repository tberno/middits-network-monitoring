import requests
import json
from requests.auth import HTTPBasicAuth

graylog_url = "http://127.0.0.1:9000/api"
notif_id = "69aadfb14e4cd76acf8d76ca" 
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

auth = HTTPBasicAuth(username, password)
headers = {"Content-Type": "application/json", "X-Requested-By": "python-nms-production"}

# 1. Fetch current notification settings
resp = requests.get(f"{graylog_url}/events/notifications/{notif_id}", auth=auth, headers=headers)
if resp.status_code != 200:
    print(f"Failed to fetch: {resp.text}")
    exit()

data = resp.json()

# 2. Change "ALERT" to "FYI" in the custom message template
old_message = data["config"].get("custom_message", "")
new_message = old_message.replace("ALERT", "FYI")
data["config"]["custom_message"] = new_message

# 3. Change color to Middlebury College Blue
data["config"]["color"] = "#002855" 

# 4. Push updates back to Graylog
update_resp = requests.put(
    f"{graylog_url}/events/notifications/{notif_id}", 
    auth=auth, 
    headers=headers, 
    data=json.dumps(data)
)

if update_resp.status_code == 200:
    print("SUCCESS: Graylog Slack notification updated to FYI and Middlebury Blue.")
else:
    print(f"FAILED: {update_resp.text}")
