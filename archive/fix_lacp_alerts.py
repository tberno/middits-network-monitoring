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
    "X-Requested-By": "python-master-fix"
}

auth = HTTPBasicAuth(username, password)

# 1. Gather Stream and Slack IDs
print("1. Gathering Infrastructure IDs...")
stream_resp = requests.get(f"{graylog_url}/streams", auth=auth, headers=headers)
default_stream_id = next((s["id"] for s in stream_resp.json().get("streams", []) if s.get("is_default")), None)

notif_resp = requests.get(f"{graylog_url}/events/notifications", auth=auth, headers=headers)
slack_notif_id = next((n["id"] for n in notif_resp.json().get("notifications", []) if "slack" in n["title"].lower()), None)

# 2. Define the Alerts
master_alerts = [
    {
        "title": "Network: Hardware & Layer 2 Alert",
        "query": 'message:("TOPO_CH" OR "root changed" OR "BPDU Throttling" OR "loop-protect:" OR "LACP_INTF_DOWN" OR "lacp timeout" OR "000000-000000" OR "Chain" OR "loss of communication" OR "reboot" OR "Core dumped" OR "iccpd" OR "jddosd" OR "resolve:ucast") OR (message:Excessive AND message:cast)'
    },
    {
        "title": "Network: Routing Protocol Alert",
        "query": 'message:("BFDD_TRAP" OR "BFD Session" OR "OSPF" OR "4.16.160.29" OR "216.238.164.73")'
    },
    {
        "title": "Network: Configuration Changed",
        "query": '(message:"requested \'commit\' operation" OR message:"SYS-5-CONFIG_I") AND NOT (message:"by user \'mist\'" OR message:"by user \'root\'")'
    }
]

# 3. Cleanup and Build
print("2. Syncing alerts with Graylog 7.0 schema...")
existing_resp = requests.get(f"{graylog_url}/events/definitions", auth=auth, headers=headers)
existing_defs = existing_resp.json().get("event_definitions", [])

for alert in master_alerts:
    # Delete if exists to avoid duplicates/conflicts
    for ed in existing_defs:
        if ed["title"] == alert["title"]:
            requests.delete(f"{graylog_url}/events/definitions/{ed['id']}", auth=auth, headers=headers)

    # Create fresh with all required fields (group_by, event_limit)
    payload = {
        "entity": {
            "title": alert["title"],
            "description": "Migrated AKiPS Alert",
            "priority": 2,
            "alert": True,
            "config": {
                "type": "aggregation-v1",
                "query": alert["query"],
                "search_within_ms": 60000,
                "execute_every_ms": 60000,
                "group_by": [],        # REQUIRED: Even if empty
                "event_limit": 100,    # REQUIRED
                "series": [{"id": "count-", "type": "count", "field": None}],
                "streams": [default_stream_id] if default_stream_id else [],
                "conditions": {
                    "expression": {
                        "expr": ">",
                        "left": {"expr": "number-ref", "ref": "count-"},
                        "right": {"expr": "number", "value": 0.0}
                    }
                }
            },
            "key_spec": [],
            "notification_settings": {"backlog_size": 5, "grace_period_ms": 0},
            "notifications": [{"notification_id": slack_notif_id}] if slack_notif_id else []
        },
        "share_request": {"selected_grantee_capabilities": {}}
    }
    
    post_resp = requests.post(f"{graylog_url}/events/definitions", auth=auth, headers=headers, data=json.dumps(payload))
    if post_resp.status_code in [200, 201]:
        print(f"   -> SUCCESS: {alert['title']}")
    else:
        print(f"   -> FAILED: {alert['title']} - {post_resp.text}")

print("\nLogic and schema fixed.")
