# Solid Server checks for LibreNMS

`check_solidserver.py` turns EfficientIP Solid Server REST API data into
Nagios-style service checks. LibreNMS can run those checks and then use its
normal alert rules and Slack transport.

## Flow

```text
Solid Server REST API
  -> nms/check_solidserver.py
  -> LibreNMS service check result
  -> LibreNMS alert rule
  -> Slack transport
```

This keeps Solid Server credentials on the LibreNMS host and avoids adding
Solid Server-specific code to the Slack alert broker.

The Solid Server integration is strictly read-only. Use an API account with
list/read permissions only; the checker only performs GET requests.

## Environment

Set these on the LibreNMS host or in the service command wrapper:

```bash
export EIP_BASE_URL="https://juno-eip.middlebury.edu"
export EIP_USER="solidserver-api-user"
export EIP_PASS="solidserver-api-password"
```

Use `--insecure` only if the Solid Server certificate chain is not trusted by
the LibreNMS host yet.

## Checks

Check API reachability:

```bash
python3 /opt/librenms/checks/check_solidserver.py api --endpoint /rest/dhcp_range_list
```

Check aggregate DHCP capacity for a shared network:

```bash
python3 /opt/librenms/checks/check_solidserver.py dhcp-shared-network \
  --shared-network 10.32.4.0/22 \
  --warning 20 \
  --critical 10
```

Or let the checker find the shared network containing a problem IP:

```bash
python3 /opt/librenms/checks/check_solidserver.py dhcp-shared-network \
  --ip 10.32.4.55 \
  --warning 20 \
  --critical 10
```

Use `dhcp-scope` only when you intentionally want one individual scope/range:

```bash
python3 /opt/librenms/checks/check_solidserver.py dhcp-scope \
  --scope 10.32.4.0/24 \
  --warning 20 \
  --critical 10
```

Check a DNS zone exists and is active:

```bash
python3 /opt/librenms/checks/check_solidserver.py dns-zone \
  --zone middlebury.edu
```

If the API object names differ, add a Solid Server `WHERE` filter. Avoid
checking broad interface/container values like `default-netv4`; those can cover
multiple shared networks and hide the thing we actually need to alert on.

```bash
python3 /opt/librenms/checks/check_solidserver.py dhcp-shared-network \
  --where "dhcpsn_name='10.32.4.0/22'" \
  --shared-network 10.32.4.0/22
```

## LibreNMS setup

Copy `nms/check_solidserver.py` to a checks directory on the LibreNMS host,
for example:

```bash
sudo install -o librenms -g librenms -m 0755 nms/check_solidserver.py /opt/librenms/checks/check_solidserver.py
```

Enable Nagios plugin service checks in LibreNMS, add service checks for the
DHCP scopes and DNS zones that matter, then create alert rules matching warning
or critical service state. Use the existing LibreNMS Slack transport for
delivery.

Recommended first services:

- `solidserver-api`
- `dhcp-shared-network-<site-or-cidr>`
- `dns-zone-middlebury.edu`
- `dns-zone-<critical-internal-zone>`

## Local testing without the API

Save a Solid Server JSON response and pass it with `--data-file`:

```bash
python3 nms/check_solidserver.py dhcp-shared-network \
  --data-file tests/samples/solidserver-dhcp-range.json \
  --shared-network 10.32.4.0/22
```
