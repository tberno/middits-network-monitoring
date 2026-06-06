#!/usr/bin/env python3
import argparse
import getpass
import ipaddress
import os
import sys
import time

import requests


EIP_BASE_URL = os.getenv("EIP_BASE_URL", "https://juno-eip.middlebury.edu").rstrip("/")
EIP_USER = os.getenv("EIP_USER")
EIP_PASS = os.getenv("EIP_PASS")

BROKER_URL = os.getenv("ALERT_BROKER_URL", "http://127.0.0.1:5052/webhook/nms")

CACHE_TTL_SECONDS = int(os.getenv("EIP_CACHE_TTL_SECONDS", "600"))


def get_secret(prompt):
    value = getpass.getpass(prompt)
    return value.strip()


def fetch_eip_shared_networks():
    if not EIP_USER or not EIP_PASS:
        raise RuntimeError("EIP_USER and EIP_PASS must be set in the environment")

    rows = []
    limit = 500
    offset = 0

    while True:
        resp = requests.get(
            EIP_BASE_URL + "/rest/dhcp_shared_network_list",
            auth=(EIP_USER, EIP_PASS),
            params={"limit": str(limit), "offset": str(offset)},
            timeout=30,
        )

        if resp.status_code == 204:
            break

        if resp.status_code != 200:
            raise RuntimeError(
                "EIP shared network lookup failed: HTTP {} body={!r}".format(
                    resp.status_code,
                    resp.text[:500],
                )
            )

        page = resp.json()
        if not page:
            break

        rows.extend(page)

        if len(page) < limit:
            break

        offset += limit

    return rows


def parse_network_rows(rows):
    networks = []

    for row in rows:
        name = (row.get("dhcpsn_name") or "").strip()
        server = (row.get("dhcp_name") or "").strip()
        shared_id = row.get("dhcpsn_id")

        try:
            net = ipaddress.ip_network(name, strict=False)
        except ValueError:
            continue

        networks.append(
            {
                "network": net,
                "cidr": str(net),
                "shared_id": shared_id,
                "dhcp_server": server,
                "raw_name": name,
            }
        )

    return networks


def find_best_match(ip_text, networks):
    ip = ipaddress.ip_address(ip_text)
    matches = [item for item in networks if ip in item["network"]]

    if not matches:
        return None

    matches.sort(key=lambda item: item["network"].prefixlen, reverse=True)
    return matches[0]


def post_alert(ip_text, match, reason, severity):
    if match:
        hostname = match["cidr"]
        title = "DHCP watch - {}".format(reason)
        faults = (
            "Reason: {reason}\n"
            "Source IP: {ip}\n"
            "Shared Network: {cidr}\n"
            "EIP DHCP Server: {server}\n"
            "EIP Shared Network ID: {sid}"
        ).format(
            reason=reason,
            ip=ip_text,
            cidr=match["cidr"],
            server=match["dhcp_server"],
            sid=match["shared_id"],
        )
    else:
        hostname = ip_text
        title = "DHCP watch - no EIP shared network match"
        faults = (
            "Reason: {reason}\n"
            "Source IP: {ip}\n"
            "Shared Network: no match found in EIP"
        ).format(reason=reason, ip=ip_text)

    data = {
        "id": "dhcp-watch-{}".format(hostname),
        "uid": "dhcp-watch-{}".format(hostname),
        "state": "1",
        "hostname": hostname,
        "sysname": hostname,
        "ip": ip_text,
        "severity": severity,
        "name": title,
        "title": title,
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
        "url": "https://raccoon.middlebury.edu/graylog/search",
        "faults": faults,
    }

    resp = requests.post(BROKER_URL, data=data, timeout=15)

    print("Broker HTTP:", resp.status_code)
    print(resp.text[:2000])

    if resp.status_code >= 300:
        raise RuntimeError("Broker post failed")


def main():
    parser = argparse.ArgumentParser(description="DHCP watcher / EIP shared-network mapper")
    parser.add_argument("--ip", required=True, help="IP address to map to an EIP shared network")
    parser.add_argument("--reason", default="DHCP issue detected", help="Alert reason")
    parser.add_argument("--severity", default="critical", choices=["critical", "warning", "info"])
    parser.add_argument("--dry-run", action="store_true", help="Only print match; do not alert")
    args = parser.parse_args()

    rows = fetch_eip_shared_networks()
    networks = parse_network_rows(rows)
    match = find_best_match(args.ip, networks)

    print("EIP rows fetched:", len(rows))
    print("CIDR networks parsed:", len(networks))

    if match:
        print("Best match:")
        print("  CIDR:", match["cidr"])
        print("  EIP ID:", match["shared_id"])
        print("  DHCP Server:", match["dhcp_server"])
    else:
        print("No EIP shared-network match for", args.ip)

    if args.dry_run:
        return

    post_alert(args.ip, match, args.reason, args.severity)


if __name__ == "__main__":
    main()
