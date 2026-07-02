import os
import ipaddress
import functools
import requests

EIP_BASE = os.getenv("EIP_BASE_URL", "")
EIP_USER = os.getenv("EIP_USER", "")
EIP_PASS = os.getenv("EIP_PASS", "")


@functools.lru_cache(maxsize=256)
def get_subnet_for_network(dhcp_name: str):
    try:
        r = requests.get(
            f"{EIP_BASE}/rest/dhcp/range/list",
            params={"WHERE": f"dhcp_name='{dhcp_name}'", "limit": "1"},
            auth=(EIP_USER, EIP_PASS),
            verify=True,
            timeout=5,
        )
        r.raise_for_status()
        data = r.json()
        if not data:
            return None
        row = data[0]
        start = row.get("dhcprange_start_ip_addr") or row.get("start_ip_addr")
        end   = row.get("dhcprange_end_ip_addr")   or row.get("end_ip_addr")
        if start and end:
            nets = list(ipaddress.summarize_address_range(
                ipaddress.ip_address(start), ipaddress.ip_address(end)
            ))
            return str(nets[0]) if nets else f"{start}/?"
        return row.get("subnet_addr") or row.get("dhcp_subnet")
    except Exception:
        return None


def enrich_dhcp_message(payload: dict) -> dict:
    dhcp_network = payload.get("dhcp_network")
    if dhcp_network:
        subnet = get_subnet_for_network(dhcp_network)
        if subnet:
            payload["dhcp_subnet"] = subnet
    return payload
