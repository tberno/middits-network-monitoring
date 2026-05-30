# DHCP Lease Exhaustion Alert Notes

## Current data available from raw syslog

Example:

```text
<27>May 30 16:55:28 dhcpd[17498]: DHCPDISCOVER from b4:16:78:bf:03:40 via hn1: network default-netv4: no free leases
```

Available:
- Graylog sender IP: `gl2_remote_ip`
- DHCP process: `dhcpd[17498]`
- DHCP action: `DHCPDISCOVER`
- Client MAC
- VM/appliance interface
- DHCP network label
- Status: `no free leases`

Not available:
- actual CIDR/subnet
- VLAN
- site
- scope name

Those require a lookup table or EfficientIP/SOLIDserver-native alert/report/API enrichment.

## Next enrichment options

1. Graylog lookup table keyed by `gl2_remote_ip|dhcp_interface|dhcp_network`
2. Alert normalizer enrichment on raccoon
3. EfficientIP/SOLIDserver API enrichment
4. Native EIP alert feed if it includes scope/subnet data
