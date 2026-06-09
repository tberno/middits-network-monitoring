#!/usr/bin/env python3
import json
import os
import sys
import time
from pathlib import Path

import requests

EIP_BASE_URL = os.getenv("EIP_BASE_URL", "https://juno-eip.middlebury.edu").rstrip("/")
EIP_USER = os.getenv("EIP_USER")
EIP_PASS = os.getenv("EIP_PASS")

BROKER_URL = os.getenv("ALERT_BROKER_URL", "http://127.0.0.1:5052/webhook/dns")
STATE_FILE = Path(os.getenv("DNS_WATCH_STATE_FILE", "/var/tmp/dns-rr-watch.state.json"))
LIMIT = int(os.getenv("DNS_WATCH_LIMIT", "1000"))
MAX_PAGES = int(os.getenv("DNS_WATCH_MAX_PAGES", "1"))

WATCH_TYPES = {
    t.strip().upper()
    for t in os.getenv("DNS_WATCH_TYPES", "A,AAAA,CNAME,MX,TXT,NS,PTR,SRV").split(",")
    if t.strip()
}

FIELDS = [
    "rr_full_name",
    "rr_type",
    "rr_all_value",
    "value1",
    "value2",
    "value3",
    "ttl",
    "dnszone_name",
    "dnsview_name",
    "dns_name",
    "delayed_create_time",
    "delayed_delete_time",
]


def die(msg):
    print(f"ERROR: {msg}", file=sys.stderr)
    sys.exit(1)


def load_state():
    if not STATE_FILE.exists():
        return {}
    try:
        return json.loads(STATE_FILE.read_text())
    except Exception:
        return {}


def save_state(state):
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(state, sort_keys=True, indent=2))
    tmp.replace(STATE_FILE)


def fetch_records():
    if not EIP_USER or not EIP_PASS:
        die("EIP_USER and EIP_PASS must be set")

    rows = []
    offset = 0

    pages = 0

    while True:
        pages += 1
        r = requests.get(
            f"{EIP_BASE_URL}/rest/dns_rr_list",
            auth=(EIP_USER, EIP_PASS),
            params={"limit": LIMIT, "offset": offset},
            timeout=60,
        )
        r.raise_for_status()
        chunk = r.json()

        if not isinstance(chunk, list):
            die(f"Unexpected dns_rr_list response: {type(chunk)}")

        real_rows = [x for x in chunk if str(x.get("errno", "0")) == "0"]
        rows.extend(real_rows)

        if len(chunk) < LIMIT:
            break

        if pages >= MAX_PAGES:
            break

        offset += LIMIT

    return rows


def normalize(row):
    rr_type = str(row.get("rr_type") or "").upper()
    if rr_type not in WATCH_TYPES:
        return None

    rr_id = str(row.get("rr_id") or "").strip()
    if not rr_id:
        return None

    item = {field: str(row.get(field) or "") for field in FIELDS}
    item["rr_id"] = rr_id
    return rr_id, item


def describe(item):
    name = item.get("rr_full_name") or "<unknown>"
    rtype = item.get("rr_type") or "<type>"
    value = item.get("rr_all_value") or item.get("value1") or "<empty>"
    zone = item.get("dnszone_name") or "<unknown zone>"
    view = item.get("dnsview_name") or "<unknown view>"
    ttl = item.get("ttl") or ""
    return f"{name} {rtype} {value} | zone={zone} view={view} ttl={ttl}"


def post_dns_event(title, details, severity="info"):
    resp = requests.post(
        BROKER_URL,
        json={
            "title": title,
            "source": "SOLIDserver dns_rr_list",
            "severity": severity,
            "details": details,
            "url": EIP_BASE_URL,
        },
        timeout=20,
    )
    resp.raise_for_status()
    return resp.json()


def main():
    old = load_state()
    records = fetch_records()

    new = {}
    for row in records:
        normalized = normalize(row)
        if normalized:
            rr_id, item = normalized
            new[rr_id] = item

    if not old:
        save_state(new)
        print(f"Initialized snapshot with {len(new)} DNS records. No alerts sent.")
        return

    added = sorted(set(new) - set(old))
    removed = sorted(set(old) - set(new))
    common = set(new) & set(old)

    updated = sorted(
        rr_id for rr_id in common
        if {k: new[rr_id].get(k) for k in FIELDS} != {k: old[rr_id].get(k) for k in FIELDS}
    )

    events = []

    for rr_id in added[:25]:
        events.append(f"ADDED rr_id={rr_id}: {describe(new[rr_id])}")

    for rr_id in removed[:25]:
        events.append(f"REMOVED rr_id={rr_id}: {describe(old[rr_id])}")

    for rr_id in updated[:25]:
        changes = []
        for field in FIELDS:
            before = old[rr_id].get(field, "")
            after = new[rr_id].get(field, "")
            if before != after:
                changes.append(f"{field}: {before!r} -> {after!r}")
        events.append(f"UPDATED rr_id={rr_id}: {describe(new[rr_id])}\n  " + "\n  ".join(changes[:8]))

    total_changes = len(added) + len(removed) + len(updated)

    if total_changes:
        details = "\n\n".join(events)
        if total_changes > len(events):
            details += f"\n\n...and {total_changes - len(events)} more changes."
        post_dns_event(
            title=f"{total_changes} DNS record change(s) detected",
            details=details,
            severity="info",
        )
        print(f"Posted DNS change alert: added={len(added)} updated={len(updated)} removed={len(removed)}")
    else:
        print(f"No DNS record changes. Snapshot size={len(new)}")

    save_state(new)


if __name__ == "__main__":
    main()
