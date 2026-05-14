#!/usr/bin/env python3
import base64
import copy
import json
import ssl
import sys
import urllib.request
import urllib.error

# ===== ONLY CHANGE THIS =====
API_TOKEN = "1muaihohkp11ei2tp4ou5e4bmgop01qsh9cd5f8dr67ahhhmed4q"
# ============================

GRAYLOG_URL = "https://raccoon.middlebury.edu/graylog"
SEED_EVENT_ID = "69de79fad809e109c11c718a"     # Network: Hardware & Layer 2 Alert
TARGET_STREAM_ID = "69de4f06d809e109c11c0fee"  # Juniper Infrastructure
SLACK_NOTIFICATION_TITLE = "Slack - Unified Infrastructure"

SEARCH_WITHIN_MS = 300000   # 5 minutes
EXECUTE_EVERY_MS = 60000    # 1 minute
VERIFY_TLS = True
DRY_RUN = False

RULES = [
    {
        "title": "Juniper/Mist - UI login",
        "priority": 2,
        "description": "Local/UI login event on a Juniper Mist switch.",
        "patterns": ["UI_LOGIN_EVENT"],
    },
    {
        "title": "Juniper/Mist - ICCP issue",
        "priority": 3,
        "description": "ICCP/VC communication issue detected.",
        "patterns": ["iccpd"],
    },
    {
        "title": "Juniper/Mist - STP topology change",
        "priority": 2,
        "description": "STP topology change detected.",
        "patterns": ["TOPO_CH"],
    },
    {
        "title": "Juniper/Mist - Core dump",
        "priority": 3,
        "description": "Core dump detected on a Juniper switch.",
        "patterns": ["Core dumped"],
    },
    {
        "title": "Juniper/Mist - DDoS or resolve anomaly",
        "priority": 2,
        "description": "Forwarding/DDoS related anomaly detected.",
        "patterns": ["jddosd", "resolve:ucast"],
    },
    {
        "title": "Juniper/Mist - BPDU or loop protect",
        "priority": 3,
        "description": "BPDU throttling or loop-protect event detected.",
        "patterns": ["BPDU Throttling", "loop-protect:"],
    },
    {
        "title": "Juniper/Mist - LACP state change",
        "priority": 3,
        "description": "LACP interface state change detected.",
        "patterns": ["LACP_INTF_DOWN", "KERN_LACP_INTF_STATE_CHANGE"],
    },
    {
        "title": "Juniper/Mist - Excessive cast or root changed",
        "priority": 2,
        "description": "Broadcast storm symptoms or STP root change detected.",
        "patterns": ["(E|e)xcessive.*cast", "(R|r)oot changed"],
    },
    {
        "title": "Juniper/Mist - VC or stack communication issue",
        "priority": 3,
        "description": "VC/stack chain or communication issue detected.",
        "patterns": ["Chain", "(L|l)oss of communication"],
    },
]

BASE = GRAYLOG_URL.rstrip("/") + "/api"

def fail(msg):
    print("ERROR: " + msg, file=sys.stderr)
    sys.exit(1)

def ssl_context():
    ctx = ssl.create_default_context()
    if not VERIFY_TLS:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return ctx

def parse_body(raw):
    raw = raw.decode("utf-8", errors="replace")
    if not raw:
        return {}
    try:
        return json.loads(raw)
    except Exception:
        return raw

def http_request(method, path, payload=None, expected=(200, 201, 202), raise_on_error=True):
    url = BASE + path
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")

    req = urllib.request.Request(url, data=data, method=method)
    auth = base64.b64encode((API_TOKEN + ":token").encode("utf-8")).decode("ascii")
    req.add_header("Authorization", "Basic " + auth)
    req.add_header("Accept", "application/json")
    req.add_header("X-Requested-By", "graylog-bootstrap")
    if payload is not None:
        req.add_header("Content-Type", "application/json")

    try:
        with urllib.request.urlopen(req, context=ssl_context(), timeout=30) as resp:
            return resp.getcode(), parse_body(resp.read())
    except urllib.error.HTTPError as e:
        body = parse_body(e.read())
        if raise_on_error:
            fail("%s %s -> %s\n%s" % (method, path, e.code, json.dumps(body, indent=2) if isinstance(body, (dict, list)) else str(body)))
        return e.code, body

def get_collection(path):
    _, data = http_request("GET", path)
    if isinstance(data, list):
        return data
    if isinstance(data, dict):
        for key in ("event_definitions", "notifications", "elements", "streams"):
            if key in data and isinstance(data[key], list):
                return data[key]
    return []

def find_notification_id(title):
    for item in get_collection("/events/notifications?per_page=200"):
        if item.get("title") == title:
            return item["id"]
    fail("Notification titled %r was not found." % title)

def get_event_definition(event_id):
    _, data = http_request("GET", "/events/definitions/" + event_id)
    if not isinstance(data, dict):
        fail("Seed event definition lookup did not return a JSON object.")
    return data

def event_exists(title):
    for item in get_collection("/events/definitions?per_page=200"):
        if item.get("title") == title:
            return True
    return False

def regex_query(pattern):
    return "(message:/%s/ OR full_message:/%s/)" % (pattern, pattern)

def build_query(patterns):
    return " OR ".join(regex_query(p) for p in patterns)

def build_notification_variants(seed_notifications, notification_id):
    variants = []
    seen = set()

    def add_variant(v):
        key = json.dumps(v, sort_keys=True)
        if key not in seen:
            seen.add(key)
            variants.append(v)

    if isinstance(seed_notifications, list) and seed_notifications:
        shaped = []
        for item in seed_notifications:
            if isinstance(item, dict):
                x = copy.deepcopy(item)
                x["notification_id"] = notification_id
                shaped.append(x)
        if shaped:
            add_variant(shaped)

    add_variant([{"notification_id": notification_id}])
    add_variant([{"notification_id": notification_id, "notification_parameters": {}}])
    add_variant([notification_id])

    return variants

def build_payload(seed, rule):
    if "config" not in seed or not isinstance(seed["config"], dict):
        fail("Seed event definition does not contain a usable config block.")

    config = copy.deepcopy(seed["config"])
    config["query"] = build_query(rule["patterns"])
    config["search_within_ms"] = SEARCH_WITHIN_MS
    config["execute_every_ms"] = EXECUTE_EVERY_MS
    config["streams"] = [TARGET_STREAM_ID]

    payload = {
        "title": rule["title"],
        "description": rule["description"],
        "priority": rule["priority"],
        "alert": True,
        "config": config,
        "field_spec": copy.deepcopy(seed.get("field_spec", {})),
        "key_spec": copy.deepcopy(seed.get("key_spec", [])),
        "notification_settings": copy.deepcopy(seed.get("notification_settings", {"grace_period_ms": 0, "backlog_size": 5})),
        "state": "ENABLED",
    }

    if "storage" in seed:
        payload["storage"] = copy.deepcopy(seed["storage"])

    return payload

def create_event_definition(seed, rule, notification_id):
    payload_base = build_payload(seed, rule)
    notification_variants = build_notification_variants(seed.get("notifications", []), notification_id)
    attempts = []

    for keep_storage in (True, False):
        for notifications in notification_variants:
            payload = copy.deepcopy(payload_base)
            payload["notifications"] = copy.deepcopy(notifications)
            if not keep_storage:
                payload.pop("storage", None)

            if DRY_RUN:
                print("\n--- DRY RUN: %s ---" % rule["title"])
                print(json.dumps(payload, indent=2))
                return True

            create_request = {
                "entity": payload,
                "share_request": {
                    "selected_grantee_capabilities": {}
                }
            }

            status, body = http_request(
                "POST",
                "/events/definitions",
                payload=create_request,
                expected=(200, 201, 202),
                raise_on_error=False
            )

            if status in (200, 201, 202):
                return True

            body_text = json.dumps(body, sort_keys=True) if isinstance(body, (dict, list)) else str(body)
            attempts.append("storage=%s notifications=%s -> %s %s" % (
                "keep" if keep_storage else "drop",
                json.dumps(notifications, sort_keys=True),
                status,
                body_text
            ))

            retryable = (
                status == 400 and (
                    "notifications" in body_text or
                    "notification" in body_text or
                    "Builder" in body_text or
                    "storage" in body_text or
                    "entity cannot be null" in body_text
                )
            )
            if not retryable:
                fail("POST /events/definitions failed for %r\n%s" % (rule["title"], body_text))

    fail("Could not create %r after trying payload variants:\n%s" % (rule["title"], "\n".join(attempts)))

def main():
    if API_TOKEN in ("", "PASTE_RAW_GRAYLOG_API_TOKEN_HERE"):
        fail("Edit API_TOKEN at the top of the script first.")
    if ":" in API_TOKEN:
        fail("API_TOKEN must be the raw token only, without ':token'.")

    # quick auth check
    http_request("GET", "/events/notifications?per_page=1")

    notification_id = find_notification_id(SLACK_NOTIFICATION_TITLE)
    seed = get_event_definition(SEED_EVENT_ID)

    created = 0
    skipped = 0

    for rule in RULES:
        if event_exists(rule["title"]):
            print("SKIP   %s (already exists)" % rule["title"])
            skipped += 1
            continue

        create_event_definition(seed, rule, notification_id)
        if not DRY_RUN:
            print("CREATE %s" % rule["title"])
            created += 1

    print("\nDone. Created=%s Skipped=%s DryRun=%s" % (created, skipped, DRY_RUN))

if __name__ == "__main__":
    main()
