import os
import requests
import urllib3

# Suppress insecure request warnings since we are using an internal certificate (-k equivalent)
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

EIP_HOST = "https://juno-eip.middlebury.edu"
EIP_USER = os.getenv("EIP_USER", "slack_api")
EIP_PASS = os.getenv("EIP_PASS")

def get_subnet_for_network(dhcp_name: str) -> str | None:
    """Queries the EfficientIP API to find the subnet CIDR for a given DHCP network name."""
    if not EIP_PASS:
        print("Warning: EIP_PASS environment variable is not set.")
        return None

    # EfficientIP uses underscores for endpoints
    url = f"{EIP_HOST}/rest/dhcp_range_list"
    
    # Filter the API request to only return the specific DHCP network we are alerting on
    params = {
        "WHERE": f"dhcp_name='{dhcp_name}'", 
        "limit": "1"
    }

    try:
        response = requests.get(
            url,
            auth=(EIP_USER, EIP_PASS), # requests handles the Basic Auth automatically
            params=params,
            verify=False, # Equivalent to curl -k
            timeout=10
        )
        response.raise_for_status()
        data = response.json()
        
        # Parse the JSON response we proved works via curl
        if data and isinstance(data, list) and data[0].get("errno") == "0":
            net_addr = data[0].get("dhcpscope_net_addr")
            prefix = data[0].get("dhcpscope_prefix")
            
            if net_addr and prefix:
                return f"{net_addr}/{prefix}"
                
    except Exception as e:
        print(f"EIP API Lookup Error: {e}")
    
    return None

def enrich_dhcp_message(payload: dict) -> dict:
    """Injects the actual subnet CIDR into the Graylog alert payload."""
    # Depending on your normalizer, the network name might be in 'device' or 'dhcp_network'
    dhcp_network = payload.get("device") or payload.get("dhcp_network")
    
    if dhcp_network:
        subnet = get_subnet_for_network(dhcp_network)
        if subnet:
            payload["dhcp_subnet"] = subnet
            
    return payload