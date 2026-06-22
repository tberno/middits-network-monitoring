#!/usr/bin/env python3
"""
Create/update the Graylog pipeline rule for EfficientIP/SOLIDserver DHCP
"no free leases" messages, then attach it to the first stage of the chosen
pipeline.

Usage:
  export GRAYLOG_URL="http://127.0.0.1:9000"
  export GRAYLOG_USER="your-graylog-user"
  export GRAYLOG_PASSWORD="your-graylog-password"
  ./scripts/apply_graylog_rule.py

Optional:
  export PIPELINE_TITLE="librenms-source-normalization"
  export RULE_FILE="graylog/rules/parse_efficientip_dhcp_no_free_leases.rule"
"""

from __future__ import annotations

import base64
import getpass
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

GRAYLOG_URL = os.environ.get("GRAYLOG_URL", "http://127.0.0.1:9000").rstrip("/")
GRAYLOG_USER = os.environ.get("GRAYLOG_USER") or input("Graylog username: ")
GRAYLOG_PASSWORD = os.environ.get("GRAYLOG_PASSWORD") or getpass.getpass("Graylog password: ")
PIPELINE_TITLE = os.environ.get("PIPELINE_TITLE", "librenms-source-normalization")
RULE_TITLE = os.environ.get("RULE_TITLE", "parse_efficientip_dhcp_no_free_leases")
RULE_FILE = Path(os.environ.get("RULE_FILE", "graylog/rules/parse_efficientip_dhcp_no_free_leases.rule"))


def request(method: str, path: str, payload: dict[str, Any] | None = None) -> Any:
    url = f"{GRAYLOG_URL}/api{path}"
    data = None
    headers = {
        "Accept": "application/json",
        "X-Requested-By": "graylog-efficientip-dhcp-alerting",
    }
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"

    token = base64.b64encode(f"{GRAYLOG_USER}:{GRAYLOG_PASSWORD}".encode("utf-8")).decode("ascii")
    headers["Authorization"] = f"Basic {token}"

    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            body = resp.read().decode("utf-8")
            if not body:
                return None
            return json.loads(body)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise SystemExit(f"Graylog API {method} {path} failed: HTTP {exc.code}\n{body}") from exc
    except urllib.error.URLError as exc:
        raise SystemExit(f"Graylog API connection failed for {url}: {exc}") from exc


def as_list(response: Any, preferred_keys: list[str]) -> list[dict[str, Any]]:
    if isinstance(response, list):
        return response
    if isinstance(response, dict):
        for key in preferred_keys:
            value = response.get(key)
            if isinstance(value, list):
                return value
    return []


def find_by_title(items: list[dict[str, Any]], title: str) -> dict[str, Any] | None:
    for item in items:
        if item.get("title") == title:
            return item
    return None


def insert_rule_into_pipeline_source(source: str, rule_title: str) -> str:
    rule_line = f'  rule "{rule_title}";'
    if f'rule "{rule_title}"' in source:
        return source

    # Insert into the first stage block before its end.
    match = re.search(r"(stage\s+\d+\s+match\s+(?:either|all)\s*\n)", source, flags=re.IGNORECASE)
    if not match:
        raise SystemExit("Could not find a stage block in pipeline source. Refusing to modify automatically.")

    first_stage_start = match.end()
    end_match = re.search(r"(?m)^end\s*$", source[first_stage_start:])
    if not end_match:
        raise SystemExit("Could not find the end of the first stage block. Refusing to modify automatically.")

    insert_at = first_stage_start + end_match.start()
    before = source[:insert_at]
    after = source[insert_at:]

    if not before.endswith("\n"):
        before += "\n"

    return before + rule_line + "\n" + after


def main() -> None:
    if not RULE_FILE.exists():
        raise SystemExit(f"Rule file not found: {RULE_FILE}")

    rule_source = RULE_FILE.read_text(encoding="utf-8")

    print(f"Graylog URL: {GRAYLOG_URL}")
    print(f"Pipeline title: {PIPELINE_TITLE}")
    print(f"Rule title: {RULE_TITLE}")

    print("Fetching existing pipeline rules...")
    rules_response = request("GET", "/system/pipelines/rule")
    rules = as_list(rules_response, ["rules"])
    existing_rule = find_by_title(rules, RULE_TITLE)

    rule_payload = {
        "title": RULE_TITLE,
        "description": "Parse EfficientIP/SOLIDserver dhcpd no-free-leases syslog messages into fields for alerting.",
        "source": rule_source,
    }

    if existing_rule:
        rule_id = existing_rule.get("id")
        print(f"Updating existing rule: {RULE_TITLE} ({rule_id})")
        request("PUT", f"/system/pipelines/rule/{rule_id}", rule_payload)
    else:
        print(f"Creating rule: {RULE_TITLE}")
        request("POST", "/system/pipelines/rule", rule_payload)

    print("Fetching existing pipelines...")
    pipelines_response = request("GET", "/system/pipelines/pipeline")
    pipelines = as_list(pipelines_response, ["pipelines"])
    pipeline = find_by_title(pipelines, PIPELINE_TITLE)

    if not pipeline:
        available = ", ".join(sorted([p.get("title", "<untitled>") for p in pipelines]))
        raise SystemExit(f"Pipeline not found: {PIPELINE_TITLE}\nAvailable pipelines: {available}")

    pipeline_id = pipeline.get("id")
    pipeline_source = pipeline.get("source", "")
    updated_source = insert_rule_into_pipeline_source(pipeline_source, RULE_TITLE)

    if updated_source == pipeline_source:
        print("Pipeline already contains the rule. No pipeline update needed.")
    else:
        backup_path = Path(f"/tmp/graylog-pipeline-{PIPELINE_TITLE}.backup.source")
        backup_path.write_text(pipeline_source, encoding="utf-8")
        print(f"Backed up old pipeline source to {backup_path}")
        print(f"Updating pipeline: {PIPELINE_TITLE} ({pipeline_id})")

        pipeline_payload = {
            "title": pipeline.get("title", PIPELINE_TITLE),
            "description": pipeline.get("description", ""),
            "source": updated_source,
        }
        request("PUT", f"/system/pipelines/pipeline/{pipeline_id}", pipeline_payload)

    print("")
    print("Done.")
    print("Next: wait for a new 'no free leases' log, then run:")
    print("  ./scripts/test_no_free_leases_query.sh")


if __name__ == "__main__":
    main()
