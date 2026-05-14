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

print("1. Fetching Default Stream ID...")
streams_response = requests.get(
    f"{graylog_url}/streams",
    auth=HTTPBasicAuth(username, password),
    headers=headers
)

default_stream_id = None
if streams_response.status_code == 200:
    streams = streams_response.json().get("streams", [])
    default_stream_id = next((s["id"] for s in streams if s.get("is_default")), None)
    
if default_stream_id:
    print(f"   -> Found Default Stream ID: {default_stream_id}")
else:
    print("   -> Warning: Could not find Default Stream. Proceeding with empty streams list.")

# The 5 Master Alert Categories from AKiPS
master_alerts = [
    {
        "title": "Network: Hardware & Layer 2 Alert",
        "description": "Catches loops, spanning tree changes, stacking breaks, core dumps, and DDoS protection triggers.",
        "query": 'message:("TOPO_CH" OR "root changed" OR "BPDU Throttling" OR "loop-protect:" OR "000000-000000" OR "Chain" OR "loss of communication" OR "reboot" OR "Core dumped" OR "iccpd" OR "jddosd" OR "resolve:ucast") OR (message:Excessive AND message:cast)'
    },
    {
        "title": "Network: Routing Protocol Alert",
        "description": "Catches OSPF drops, BFD sessions going down, and provider IP issues.",
        "query": 'message:("BFDD_TRAP" OR "BFD Session" OR "OSPF" OR "4.16.160.29" OR "216.238.164.73")'
    },
    {
        "title": "Network: Configuration Changed",
        "description": "Tracks every time someone commits a change on a Juniper, Aruba, or Cisco device.",
        "query": 'message:("requested \'commit\' operation" OR "SYS-5-CONFIG_I" OR "Startup configuration changed by CLI" OR "Running config change")'
    },
    {
        "title": "Firewall: Palo Alto State Change",
        "description": "Monitors firewalls for High Availability (HA) state changes and link monitor failures.",
        "query": 'message:("connect-server-monitor-failure" OR "Chassis Master Alarm" OR "state-change" OR "link-monitor-down" OR "link-monitor-up" OR "link-change")'
    },
    {
        "title": "Telecom & Systems Alert",
        "description": "Catches SIP trunk outages, rejected Monterey calls, and Infoblox lease issues.",
        "query": 'message:("SIPTrunkOOS" OR "no free leases") OR (message:Call AND message:"is rejected.")'
    }
]

print("\nStarting Graylog Event Definition Build...\n")

for alert in master_alerts:
    print(f"Building: {alert['title']}...")
    
    # Graylog 7.0+ Required Wrapper
    payload = {
        "entity": {
            "title": alert["title"],
            "description": alert["description"],
            "priority": 2, 
            "alert": True,
            "config": {
                "type": "aggregation-v1",
                "query": alert["query"],
                "search_within_ms": 60000, 
                "execute_every_ms": 60000, 
                "group_by": [],
                "series": [                                  # <-- NEW FIX
                    {
                        "id": "count()",
                        "function": "count()"
                    }
                ],
                "event_limit": 100,                          # <-- NEW FIX
                "streams": [default_stream_id] if default_stream_id else [], 
                "conditions": {
                    "expression": {
                        "expr": ">",
                        "left": {
                            "expr": "number-ref",
                            "ref": "count()"
                        },
                        "right": {
                            "expr": "number",
                            "value": 0.0
                        }
                    }
                }
            },
            "key_spec": [],
            "notification_settings": {
                "grace_period_ms": 0,
                "backlog_size": 0
            },
            "notifications": [] 
        },
        "share_request": {
            "selected_grantee_capabilities": {}
        }
    }

    response = requests.post(
        f"{graylog_url}/events/definitions",
        auth=HTTPBasicAuth(username, password),
        headers=headers,
        data=json.dumps(payload)
    )

    if response.status_code in [200, 201]:
        print("  -> SUCCESS")
    else:
        print(f"  -> FAILED (Status: {response.status_code})")
        print(f"  -> Error: {response.text}")

print("\nScript Complete!")
