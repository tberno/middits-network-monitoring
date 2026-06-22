# Graylog EfficientIP DHCP Lease Exhaustion Alerting

This repo contains a Graylog pipeline rule and helper scripts for parsing EfficientIP/SOLIDserver raw `dhcpd` syslog messages like:

```text
dhcpd[17498]: DHCPDISCOVER from b4:16:78:bf:03:40 via hn1: network default-netv4: no free leases
```

The parser adds fields that are useful in Graylog event definitions and Slack notifications:

- `alert_type`
- `alert_service`
- `alert_summary`
- `dhcp_pid`
- `dhcp_action`
- `dhcp_client_mac`
- `dhcp_interface`
- `dhcp_network`
- `dhcp_status`
- `dhcp_process`
- `dhcp_server_ip`
- `dhcp_normalized_message`

## Important limitation

The raw `dhcpd` syslog message does **not** contain the actual CIDR/subnet. It only includes the sender IP, interface, network label, client MAC, and no-free-leases status. To show a real scope/subnet, add a Graylog lookup table or enrich through EfficientIP/SOLIDserver API/report data.

## Apply the rule

Run this on the Graylog server:

```bash
export GRAYLOG_URL="http://127.0.0.1:9000"
export GRAYLOG_USER="YOUR_GRAYLOG_USER"
export GRAYLOG_PASSWORD="YOUR_GRAYLOG_PASSWORD"

./scripts/apply_graylog_rule.py
```

Defaults:

```bash
PIPELINE_TITLE="librenms-source-normalization"
RULE_TITLE="parse_efficientip_dhcp_no_free_leases"
RULE_FILE="graylog/rules/parse_efficientip_dhcp_no_free_leases.rule"
```

## Test

Wait for a fresh `no free leases` message, then run:

```bash
./scripts/test_no_free_leases_query.sh
```

You should see fields such as `dhcp_network`, `dhcp_interface`, `dhcp_client_mac`, and `alert_type`.

## Graylog Event Definition

Recommended query while transitioning:

```text
alert_type:dhcp_lease_exhaustion OR message:"no free leases"
```

After the pipeline is confirmed working:

```text
alert_type:dhcp_lease_exhaustion
```

Recommended grouping:

```text
gl2_remote_ip
dhcp_network
```

Do not group primarily by `dhcp_interface`; interface names differ between Hyper-V and Proxmox/KVM.

## Slack

Use `slack/templates/dhcp-lease-exhaustion.md` as the template basis. Prefer sending to the existing alert normalizer if matching Slack colors/formatting is required.
