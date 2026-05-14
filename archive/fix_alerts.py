import requests
import json
from requests.auth import HTTPBasicAuth

# Graylog API details
graylog_url = "https://raccoon.middlebury.edu/graylog/api"
username = "1dm6907012hl4njj9kk82dn3hmkrik3jjvosigakqg4sue3jet1t"
password = "token"

headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
    "X-Requested-By": "python-automation"
}

# 1. Fetch current Event Definitions to get their IDs
print("1. Fetching existing alerts...")
resp = requests.get(f"{graylog_url}/events/definitions", auth=HTTPBasicAuth(username, password), headers=headers)
if resp.status_code != 200:
    print(f"Failed to fetch alerts: {resp.text}")
    exit()

all_alerts = resp.json().get("event_definitions", [])

# 2. Loop through and fix each one
for alert in all_alerts:
    title = alert.get("title")
    alert_id = alert.get("id")
    
    if title not in [
        "Network: Hardware & Layer 2 Alert", 
        "Network: Routing Protocol Alert", 
        "Network: Configuration Changed", 
        "Firewall: Palo Alto State Change", 
        "Telecom & Systems Alert"
    ]:
        continue

    print(f"Fixing: {title} ({alert_id})...")
    
    new_config = alert.get("config").copy()
    
    # Ensure count() is defined in series
    new_config["series"] = [{"id": "count-ref", "function": "count()"}]
    
    # Link condition to that specific series ID
    new_config["conditions"] = {
        "expression": {
            "expr": ">",
            "left": {"expr": "number-ref", "ref": "count-ref"},
            "right": {"expr": "number", "value": 0.0}
        }
    }
    
    # Update Configuration alert to ignore Mist/Root template pushes
    if title == "Network: Configuration Changed":
        new_config["query"] = '(message:"requested \'commit\' operation" OR message:"SYS-5-CONFIG_I") AND NOT (message:"by user \'mist\'" OR message:"by user \'root\'")'

    # THE FIX: Body must contain 'id' to match the URL ID
    payload = {
        "id": alert_id,
        "title": title,
        "description": alert.get("description"),
        "priority": alert.get("priority"),
        "alert": True,
        "config": new_config,
        "key_spec": [],
        "notification_settings": alert.get("notification_settings"),
        "notifications": alert.get("notifications")
    }

    update_resp = requests.put(
        f"{graylog_url}/events/definitions/{alert_id}",
        auth=HTTPBasicAuth(username, password),
        headers=headers,
        data=json.dumps(payload)
    )

    if update_resp.status_code == 200:
        print("  -> Fixed Successfully!")
    else:
        print(f"  -> FAILED to update: {update_resp.text}")

print("\nAll targeted alerts have been updated and fixed.")
