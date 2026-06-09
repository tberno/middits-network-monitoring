#!/usr/bin/env python3
import base64
import getpass
import json
import os
import sys
from urllib.parse import urlencode

import requests


BASE_URL = os.getenv("EIP_BASE_URL", "https://juno-eip.middlebury.edu").rstrip("/")
VERIFY_TLS = os.getenv("EIP_VERIFY_TLS", "true").lower() not in ("0", "false", "no")


def b64(value):
    return base64.b64encode(value.encode("utf-8")).decode("ascii")


def print_result(name, response):
    print()
    print("=" * 80)
    print(name)
    print("URL:", response.url)
    print("HTTP:", response.status_code)
    print("Content-Type:", response.headers.get("content-type"))
    text = response.text.strip()
    if not text:
        print("Body: <empty>")
        return

    try:
        parsed = response.json()
        print(json.dumps(parsed, indent=2)[:4000])
    except Exception:
        print(text[:4000])


def request_test(name, method, path, headers=None, auth=None, params=None):
    url = BASE_URL + path
    try:
        response = requests.request(
            method,
            url,
            headers=headers or {},
            auth=auth,
            params=params or {},
            timeout=15,
            verify=VERIFY_TLS,
        )
        print_result(name, response)
        return response
    except Exception as exc:
        print()
        print("=" * 80)
        print(name)
        print("ERROR:", repr(exc))
        return None


def main():
    user = os.getenv("EIP_USER") or input("EIP user/token: ").strip()
    password = os.getenv("EIP_PASS") or getpass.getpass("EIP password/secret: ").strip()

    if not user or not password:
        print("ERROR: missing user/token or password/secret")
        sys.exit(1)

    plain_headers = {
        "X-IPM-Username": user,
        "X-IPM-Password": password,
        "Accept": "application/json",
    }

    b64_headers = {
        "X-IPM-Username": b64(user),
        "X-IPM-Password": b64(password),
        "Accept": "application/json",
    }

    objects = [
        "/rest/dhcp_sharednetwork_list",
        "/rest/dhcp_shared_network_list",
        "/rest/dhcp_range_list",
        "/rest/dhcp_scope_list",
        "/rest/dhcp_server_list",
        "/rest/dhcp/range/list",
    ]

    print("Base URL:", BASE_URL)
    print("TLS verify:", VERIFY_TLS)

    # Login attempts
    request_test("POST /rest/login plain X-IPM headers", "POST", "/rest/login", headers=plain_headers)
    request_test("POST /rest/login base64 X-IPM headers", "POST", "/rest/login", headers=b64_headers)

    # Direct object tests
    for path in objects:
        request_test(
            "GET {} with basic auth".format(path),
            "GET",
            path,
            auth=(user, password),
            params={"limit": "2"},
        )

        request_test(
            "GET {} with plain X-IPM headers".format(path),
            "GET",
            path,
            headers=plain_headers,
            params={"limit": "2"},
        )

        request_test(
            "GET {} with base64 X-IPM headers".format(path),
            "GET",
            path,
            headers=b64_headers,
            params={"limit": "2"},
        )


if __name__ == "__main__":
    main()
