#!/usr/bin/env python3
"""
LibreNMS/Nagios service checks for EfficientIP Solid Server data.

The script prints a single Nagios-compatible status line and exits with:
    0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN

It can read directly from the Solid Server REST API or from a saved JSON file,
which keeps local testing independent from the production API.
"""

import argparse
import base64
import ipaddress
import ssl
import json
import os
import sys
from typing import Any, Dict, Iterable, List, Optional, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


OK = 0
WARNING = 1
CRITICAL = 2
UNKNOWN = 3

DEFAULT_BASE_URL = "https://juno-eip.middlebury.edu"


def finish(code: int, message: str) -> int:
    labels = {
        OK: "OK",
        WARNING: "WARNING",
        CRITICAL: "CRITICAL",
        UNKNOWN: "UNKNOWN",
    }
    print("{} - {}".format(labels.get(code, "UNKNOWN"), message))
    return code


def as_number(value: Any) -> Optional[float]:
    if value is None:
        return None

    if isinstance(value, (int, float)):
        return float(value)

    cleaned = str(value).strip().replace(",", "")
    if not cleaned:
        return None

    try:
        return float(cleaned)
    except ValueError:
        return None


def first_number(row: Dict[str, Any], names: Iterable[str]) -> Optional[float]:
    for name in names:
        value = as_number(row.get(name))
        if value is not None:
            return value
    return None


def first_text(row: Dict[str, Any], names: Iterable[str]) -> Optional[str]:
    for name in names:
        value = row.get(name)
        if value is not None and str(value).strip():
            return str(value).strip()
    return None


def load_rows_from_file(path: str) -> List[Dict[str, Any]]:
    with open(path, "r", encoding="utf-8") as handle:
        data = json.load(handle)

    if isinstance(data, dict):
        for key in ("data", "rows", "result", "results"):
            if isinstance(data.get(key), list):
                data = data[key]
                break
        else:
            data = [data]

    if not isinstance(data, list):
        raise ValueError("JSON input must be a list or an object containing a list")

    return [row for row in data if isinstance(row, dict)]


def fetch_rows(args: argparse.Namespace, endpoint: str, where: Optional[str]) -> List[Dict[str, Any]]:
    if args.data_file:
        return load_rows_from_file(args.data_file)

    user = args.user or os.getenv("EIP_USER")
    password = args.password or os.getenv("EIP_PASS")

    if not user or not password:
        raise RuntimeError("EIP_USER and EIP_PASS must be set, or pass --user and --password")

    base_url = (args.base_url or os.getenv("EIP_BASE_URL") or DEFAULT_BASE_URL).rstrip("/")
    url = base_url + endpoint

    rows: List[Dict[str, Any]] = []
    limit = args.limit
    offset = 0

    while True:
        params = {
            "limit": str(limit),
            "offset": str(offset),
        }
        if where:
            params["WHERE"] = where

        page_url = "{}?{}".format(url, urlencode(params))
        token = base64.b64encode("{}:{}".format(user, password).encode("utf-8")).decode("ascii")
        request = Request(
            page_url,
            headers={
                "Authorization": "Basic {}".format(token),
                "Accept": "application/json",
            },
        )
        context = ssl._create_unverified_context() if args.insecure else None

        try:
            with urlopen(request, timeout=args.timeout, context=context) as response:
                body = response.read().decode("utf-8")
                if response.status == 204:
                    break
        except HTTPError as exc:
            if exc.code == 204:
                break
            body = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(
                "Solid Server API returned HTTP {} from {}: {}".format(
                    exc.code,
                    endpoint,
                    body[:300],
                )
            )

        page = json.loads(body)
        if isinstance(page, dict):
            for key in ("data", "rows", "result", "results"):
                if isinstance(page.get(key), list):
                    page = page[key]
                    break

        if not isinstance(page, list):
            raise RuntimeError("Solid Server API response from {} was not a list".format(endpoint))

        dict_rows = [row for row in page if isinstance(row, dict)]
        rows.extend(dict_rows)

        if len(dict_rows) < limit:
            break

        offset += limit

    return rows


def row_matches(row: Dict[str, Any], value: str, fields: Iterable[str]) -> bool:
    needle = value.strip().lower()
    for field in fields:
        candidate = row.get(field)
        if candidate is not None and str(candidate).strip().lower() == needle:
            return True
    return False


def row_cidr(row: Dict[str, Any]) -> Optional[str]:
    cidr = first_text(
        row,
        (
            "dhcpsn_name",
            "dhcpscope_cidr",
            "cidr",
            "subnet",
            "network",
        ),
    )
    if cidr:
        try:
            return str(ipaddress.ip_network(cidr, strict=False))
        except ValueError:
            pass

    net_addr = first_text(row, ("dhcpscope_net_addr", "net_addr"))
    prefix = first_text(row, ("dhcpscope_prefix", "prefix"))
    if net_addr and prefix:
        try:
            return str(ipaddress.ip_network("{}/{}".format(net_addr, prefix), strict=False))
        except ValueError:
            return None

    return None


def ip_in_row_network(row: Dict[str, Any], ip_text: str) -> bool:
    cidr = row_cidr(row)
    if not cidr:
        return False

    try:
        return ipaddress.ip_address(ip_text) in ipaddress.ip_network(cidr, strict=False)
    except ValueError:
        return False


def find_dhcp_scope(rows: List[Dict[str, Any]], scope: str) -> Optional[Dict[str, Any]]:
    fields = (
        "dhcpscope_name",
        "dhcpscope_id",
        "dhcpscope_net_addr",
        "dhcpscope_range",
        "dhcp_name",
        "dhcprange_name",
        "name",
    )

    for row in rows:
        if row_matches(row, scope, fields):
            return row

    for row in rows:
        cidr = first_text(row, ("dhcpscope_cidr", "cidr", "subnet"))
        if cidr and cidr == scope:
            return row

        net_addr = first_text(row, ("dhcpscope_net_addr", "network", "net_addr"))
        prefix = first_text(row, ("dhcpscope_prefix", "prefix"))
        if net_addr and prefix and "{}/{}".format(net_addr, prefix) == scope:
            return row

    return None


def find_shared_network_rows(args: argparse.Namespace, rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    if args.ip:
        return [row for row in rows if ip_in_row_network(row, args.ip)]

    value = args.shared_network
    fields = (
        "dhcpsn_name",
        "dhcpsn_id",
        "shared_network",
        "shared_network_id",
        "dhcp_shared_network",
        "dhcpscope_name",
        "dhcpscope_id",
        "name",
    )

    matches = [row for row in rows if row_matches(row, value, fields)]
    if matches:
        return matches

    try:
        target = ipaddress.ip_network(value, strict=False)
    except ValueError:
        return []

    return [
        row
        for row in rows
        if row_cidr(row) and ipaddress.ip_network(row_cidr(row), strict=False).subnet_of(target)
    ]


def dhcp_capacity(row: Dict[str, Any]) -> Tuple[Optional[float], Optional[float], Optional[float]]:
    total = first_number(
        row,
        (
            "dhcpscope_size",
            "dhcpscope_total",
            "dhcpscope_addr_total",
            "dhcprange_size",
            "total",
            "size",
        ),
    )
    used = first_number(
        row,
        (
            "dhcpscope_used",
            "dhcpscope_addr_used",
            "dhcprange_used",
            "dhcprange_lease_count",
            "used",
            "leases_used",
        ),
    )
    free = first_number(
        row,
        (
            "dhcpscope_free",
            "dhcpscope_addr_free",
            "dhcprange_free",
            "free",
            "leases_free",
            "available",
        ),
    )

    if total is None and used is not None and free is not None:
        total = used + free
    if free is None and total is not None and used is not None:
        free = total - used
    if used is None and total is not None and free is not None:
        used = total - free

    return total, used, free


def aggregate_capacity(rows: List[Dict[str, Any]]) -> Tuple[Optional[float], Optional[float], Optional[float], int]:
    total_sum = 0.0
    used_sum = 0.0
    free_sum = 0.0
    counted = 0

    for row in rows:
        total, used, free = dhcp_capacity(row)
        if total is None or free is None or total <= 0:
            continue

        total_sum += total
        free_sum += free
        used_sum += used or (total - free)
        counted += 1

    if counted == 0:
        return None, None, None, 0

    return total_sum, used_sum, free_sum, counted


def check_dhcp_scope(args: argparse.Namespace) -> int:
    where = args.where
    rows = fetch_rows(args, args.endpoint or "/rest/dhcp_range_list", where)

    row = find_dhcp_scope(rows, args.scope)
    if not row:
        return finish(CRITICAL, "DHCP scope {} was not found in Solid Server".format(args.scope))

    total, used, free = dhcp_capacity(row)
    scope_name = first_text(row, ("dhcpscope_name", "dhcp_name", "name")) or args.scope

    if total is None or free is None or total <= 0:
        return finish(
            UNKNOWN,
            "DHCP scope {} found, but capacity fields were missing".format(scope_name),
        )

    free_pct = (free / total) * 100
    perf = "'free_pct'={:.2f}%;{};{};0;100 'free'={:.0f};;;0;{:.0f} 'used'={:.0f};;;0;{:.0f}".format(
        free_pct,
        args.warning,
        args.critical,
        free,
        total,
        used or 0,
        total,
    )
    detail = "DHCP scope {} has {:.2f}% free ({:.0f}/{:.0f} addresses) | {}".format(
        scope_name,
        free_pct,
        free,
        total,
        perf,
    )

    if free_pct <= args.critical:
        return finish(CRITICAL, detail)
    if free_pct <= args.warning:
        return finish(WARNING, detail)
    return finish(OK, detail)


def check_dhcp_shared_network(args: argparse.Namespace) -> int:
    rows = fetch_rows(args, args.endpoint or "/rest/dhcp_range_list", args.where)
    matches = find_shared_network_rows(args, rows)
    label = args.ip or args.shared_network

    if not matches:
        return finish(CRITICAL, "DHCP shared network {} was not found in Solid Server range data".format(label))

    total, used, free, counted = aggregate_capacity(matches)
    if total is None or free is None or total <= 0:
        return finish(
            UNKNOWN,
            "DHCP shared network {} matched {} rows, but capacity fields were missing".format(
                label,
                len(matches),
            ),
        )

    cidrs = sorted({cidr for cidr in (row_cidr(row) for row in matches) if cidr})
    display_name = first_text(
        matches[0],
        ("dhcpsn_name", "shared_network", "dhcp_shared_network", "name"),
    ) or label

    free_pct = (free / total) * 100
    perf = "'free_pct'={:.2f}%;{};{};0;100 'free'={:.0f};;;0;{:.0f} 'used'={:.0f};;;0;{:.0f}".format(
        free_pct,
        args.warning,
        args.critical,
        free,
        total,
        used or 0,
        total,
    )
    detail = (
        "DHCP shared network {} has {:.2f}% free ({:.0f}/{:.0f} addresses across {} ranges"
        .format(display_name, free_pct, free, total, counted)
    )
    if cidrs:
        detail += "; cidrs={}".format(",".join(cidrs[:5]))
        if len(cidrs) > 5:
            detail += "+{}".format(len(cidrs) - 5)
    detail += ") | {}".format(perf)

    if free_pct <= args.critical:
        return finish(CRITICAL, detail)
    if free_pct <= args.warning:
        return finish(WARNING, detail)
    return finish(OK, detail)


def find_dns_zone(rows: List[Dict[str, Any]], zone: str) -> Optional[Dict[str, Any]]:
    fields = (
        "dnszone_name",
        "dnszone_name_utf",
        "dnszone_fqdn",
        "zone",
        "name",
        "fqdn",
    )

    for row in rows:
        if row_matches(row, zone, fields):
            return row

    normalized = zone.rstrip(".").lower()
    for row in rows:
        for field in fields:
            value = row.get(field)
            if value and str(value).rstrip(".").lower() == normalized:
                return row

    return None


def check_dns_zone(args: argparse.Namespace) -> int:
    where = args.where
    rows = fetch_rows(args, args.endpoint or "/rest/dns_zone_list", where)

    row = find_dns_zone(rows, args.zone)
    if not row:
        return finish(CRITICAL, "DNS zone {} was not found in Solid Server".format(args.zone))

    zone_name = first_text(row, ("dnszone_name", "dnszone_name_utf", "dnszone_fqdn", "name")) or args.zone
    zone_id = first_text(row, ("dnszone_id", "id"))
    status = first_text(row, ("dnszone_status", "status", "state"))

    bad_states = {"error", "disabled", "inactive", "invalid", "0"}
    if status and status.strip().lower() in bad_states:
        return finish(CRITICAL, "DNS zone {} is in status {}".format(zone_name, status))

    details = "DNS zone {} exists".format(zone_name)
    if zone_id:
        details += " (id {})".format(zone_id)
    if status:
        details += " status={}".format(status)

    return finish(OK, details)


def check_api(args: argparse.Namespace) -> int:
    rows = fetch_rows(args, args.endpoint or "/rest/dhcp_range_list", args.where)
    return finish(OK, "Solid Server API returned {} rows from {}".format(len(rows), args.endpoint or "/rest/dhcp_range_list"))


def build_parser() -> argparse.ArgumentParser:
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--base-url", help="Solid Server base URL, default EIP_BASE_URL or {}".format(DEFAULT_BASE_URL))
    common.add_argument("--user", help="Solid Server user, default EIP_USER")
    common.add_argument("--password", help="Solid Server password, default EIP_PASS")
    common.add_argument("--timeout", type=int, default=20)
    common.add_argument("--limit", type=int, default=500)
    common.add_argument("--insecure", action="store_true", help="Disable TLS verification for internal certificates")
    common.add_argument("--data-file", help="Read Solid Server JSON from a file instead of calling the API")
    common.add_argument("--endpoint", help="Override the REST endpoint for the selected check")
    common.add_argument("--where", help="Solid Server WHERE filter, for example dhcp_name='default-netv4'")

    parser = argparse.ArgumentParser(
        description="LibreNMS service checks for Solid Server",
        parents=[common],
    )

    subparsers = parser.add_subparsers(dest="command", required=True)

    api = subparsers.add_parser(
        "api",
        help="Check that a Solid Server REST endpoint returns data",
        parents=[common],
    )
    api.set_defaults(func=check_api)

    dhcp = subparsers.add_parser(
        "dhcp-scope",
        help="Check DHCP scope free capacity",
        parents=[common],
    )
    dhcp.add_argument("--scope", required=True, help="Scope name, id, network address, or CIDR")
    dhcp.add_argument("--warning", type=float, default=20.0, help="Warning threshold: percent free at or below this value")
    dhcp.add_argument("--critical", type=float, default=10.0, help="Critical threshold: percent free at or below this value")
    dhcp.set_defaults(func=check_dhcp_scope)

    shared = subparsers.add_parser(
        "dhcp-shared-network",
        help="Check aggregate DHCP capacity for a shared network",
        parents=[common],
    )
    selector = shared.add_mutually_exclusive_group(required=True)
    selector.add_argument("--shared-network", help="Shared network id, name, or CIDR")
    selector.add_argument("--ip", help="Find the shared network containing this IP address")
    shared.add_argument("--warning", type=float, default=20.0, help="Warning threshold: percent free at or below this value")
    shared.add_argument("--critical", type=float, default=10.0, help="Critical threshold: percent free at or below this value")
    shared.set_defaults(func=check_dhcp_shared_network)

    dns = subparsers.add_parser(
        "dns-zone",
        help="Check that a DNS zone exists and is active",
        parents=[common],
    )
    dns.add_argument("--zone", required=True, help="DNS zone name/FQDN")
    dns.set_defaults(func=check_dns_zone)

    return parser


def main(argv: Optional[List[str]] = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    try:
        return args.func(args)
    except (HTTPError, URLError) as exc:
        return finish(UNKNOWN, "Solid Server request failed: {}".format(exc))
    except Exception as exc:
        return finish(UNKNOWN, str(exc))


if __name__ == "__main__":
    sys.exit(main())
