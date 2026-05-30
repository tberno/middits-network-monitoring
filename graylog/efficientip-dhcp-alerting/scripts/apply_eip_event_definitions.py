#!/usr/bin/env python3
import argparse
import copy
import getpass
import json
import os
import sys
from urllib.parse import urljoin

import requests
from requests.auth import HTTPBasicAuth


DEFAULT_TEMPLATE_TITLE = "Network Services: DHCP Lease Exhaustion"
DEFAULT_CATALOG = "config/eip_graylog_alerts.json"


def api_base(url):
    url = url.rstrip("/")
    if not url.endswith("/api"):
        url += "/api"
    return url + "/"


def request_json(session, method, base, path, **kwargs):
    url = urljoin(base, path.lstrip("/"))
    resp = session.request(method, url, **kwargs)
    if not resp.ok:
        print(f"Graylog API {method} {path} failed: HTTP {resp.status_code}", file=sys.stderr)
        print(resp.text, file=sys.stderr)
        sys.exit(1)
    if resp.text.strip():
        return resp.json()
    return None


def get_collection(session, base, path):
    out = []
    page = 1
    while True:
        sep = "&" if "?" in path else "?"
        data = request_json(session, "GET", base, f"{path}{sep}page={page}&per_page=200")

        if isinstance(data, list):
            out.extend(data)
            return out

        items = (
            data.get("elements")
            or data.get("event_definitions")
            or data.get("notifications")
            or data.get("items")
            or []
        )
        out.extend(items)

        total = data.get("total")
        count = data.get("count") or len(items)

        if not items or total is None or len(out) >= total or count == 0:
            return out

        page += 1


def find_by_title(items, title):
    for item in items:
        if item.get("title") == title:
            return item
    return None


def strip_create_only_fields(obj):
    for key in [
        "id",
        "created_at",
        "updated_at",
        "creator_user_id",
        "creator_user",
        "last_matched_at",
        "last_satisfied_at",
        "last_notified_at",
    ]:
        obj.pop(key, None)


def recursive_replace_query(obj, new_query):
    if isinstance(obj, dict):
        for key, value in list(obj.items()):
            if key == "query" and isinstance(value, str):
                obj[key] = new_query
            else:
                recursive_replace_query(value, new_query)
    elif isinstance(obj, list):
        for value in obj:
            recursive_replace_query(value, new_query)


def recursive_set_group_by(obj, group_by):
    if isinstance(obj, dict):
        for key, value in list(obj.items()):
            if key == "group_by" and isinstance(value, list):
                obj[key] = group_by
            else:
                recursive_set_group_by(value, group_by)
    elif isinstance(obj, list):
        for value in obj:
            recursive_set_group_by(value, group_by)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--catalog", default=DEFAULT_CATALOG)
    parser.add_argument("--template-title", default=DEFAULT_TEMPLATE_TITLE)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    graylog_url = os.environ.get("GRAYLOG_URL", "http://127.0.0.1:9000/graylog")
    graylog_user = os.environ.get("GRAYLOG_USER", "admin")
    graylog_password = os.environ.get("GRAYLOG_PASSWORD") or getpass.getpass("Graylog password: ")

    base = api_base(graylog_url)

    session = requests.Session()
    session.auth = HTTPBasicAuth(graylog_user, graylog_password)
    session.headers.update({
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-By": "cli",
    })

    with open(args.catalog, "r", encoding="utf-8") as f:
        catalog = json.load(f)

    definitions = get_collection(session, base, "/events/definitions")
    template_summary = find_by_title(definitions, args.template_title)
    if not template_summary:
        print(f"Could not find template event definition: {args.template_title}", file=sys.stderr)
        sys.exit(1)

    template = request_json(session, "GET", base, f"/events/definitions/{template_summary['id']}")
    existing_by_title = {d.get("title"): d for d in definitions if d.get("title")}

    print(f"Graylog URL: {graylog_url}")
    print(f"Template: {args.template_title}")
    print(f"Mode: {'APPLY' if args.apply else 'DRY RUN'}")
    print()

    for alert in catalog:
        title = alert["title"]
        query = alert["query"]
        group_by = alert.get("group_by", ["source"])
        priority = alert.get("priority", 3)

        payload = copy.deepcopy(template)
        strip_create_only_fields(payload)

        payload["title"] = title
        payload["description"] = alert.get("description", "")
        payload["priority"] = priority

        if "disabled" in payload:
            payload["disabled"] = True

        recursive_replace_query(payload, query)
        recursive_set_group_by(payload, group_by)

        print(f"- {title}")
        print(f"  priority: {priority}")
        print(f"  group_by: {', '.join(group_by)}")
        print(f"  query: {query}")

        existing = existing_by_title.get(title)
        if not args.apply:
            print("  action: dry-run")
            continue

        if existing:
            current = request_json(session, "GET", base, f"/events/definitions/{existing['id']}")
            current["title"] = payload["title"]
            current["description"] = payload["description"]
            current["priority"] = payload["priority"]
            recursive_replace_query(current, query)
            recursive_set_group_by(current, group_by)
            request_json(session, "PUT", base, f"/events/definitions/{existing['id']}", data=json.dumps(current))
            print(f"  action: updated {existing['id']}")
        else:
            created = request_json(session, "POST", base, "/events/definitions", data=json.dumps(payload))
            print(f"  action: created {created.get('id') if isinstance(created, dict) else 'ok'}")

    if not args.apply:
        print()
        print("Dry-run complete. Re-run with --apply to create/update definitions.")


if __name__ == "__main__":
    main()
