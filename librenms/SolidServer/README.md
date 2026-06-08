# SolidServer LibreNMS plugin

This is a local LibreNMS plugin prototype for displaying EfficientIP Solid
Server DHCP shared-network capacity inside LibreNMS.

The Solid Server side is strictly read-only. Use an API account with list/read
permissions only. The plugin only performs HTTP GET requests against Solid
Server and does not create, update, or delete Solid Server objects.

LibreNMS local plugins belong under `app/Plugins`. Install this by copying the
`SolidServer` directory to:

```bash
/opt/librenms/app/Plugins/SolidServer
```

Then enable it from the LibreNMS plugin admin page and configure:

- Solid Server base URL
- API username
- API password
- warning free-percent threshold
- critical free-percent threshold
- TLS verification

## What this plugin does

- Pulls `/rest/dhcp_range_list` from Solid Server.
- Groups ranges by shared-network name.
- Deduplicates HA/failover duplicate rows by shared-network name plus range
  start/end/name.
- Calculates aggregate free, used, total, and free percentage per shared
  network.
- Shows the lowest-free shared networks first with state summaries and DHCP
  source names.
- Tracks skipped HA duplicate rows so the operator can tell when failover data
  was present but not double-counted.
- Provides per-shared-network drilldown into underlying ranges, lease counts,
  failover names, and DHCP sources.
- Shows corresponding VLANs when Solid Server returns an explicit VLAN field or
  when a VLAN can be parsed from names such as `Vlan 113`.
- Cross-references detected EIP VLANs with LibreNMS VLAN inventory and links
  matching LibreNMS devices in the shared-network drilldown.
- Also attempts VLAN matching by comparing EIP shared-network names with
  LibreNMS VLAN names and by inferring VLAN IDs from matched LibreNMS interface
  names, descriptions, and aliases.
- Cross-references EIP scope CIDRs with LibreNMS interface IPv4 addresses to
  identify likely gateway/SVI/interface owners for each DHCP network.
- Adds attention notes for correlation gaps, such as EIP VLANs without
  LibreNMS VLAN matches or EIP CIDRs without matching LibreNMS interface IPs.
- Provides a read-only DHCP/IP lookup panel for IP, MAC, hostname, or
  reservation-style searches. IP searches always map to the containing DHCP
  range from `/rest/dhcp_range_list` and then try read-only lease, reservation,
  IPAM, and DNS record endpoints for richer details.

## What this plugin does not do

- It does not write to Solid Server.
- It does not change DHCP, DNS, scopes, ranges, reservations, or shared
  networks.
- It does not create, edit, or delete reservations.
- It does not send Slack messages directly.

## Alerting note

The plugin gives LibreNMS a local dashboard/control surface. For Slack alerting,
keep using LibreNMS alert rules against pollable state.

The dashboard itself is not polled by LibreNMS, so alerting needs a pollable
LibreNMS object. The preferred next step is to promote the dashboard findings
into native LibreNMS state, such as components or sensors, so normal LibreNMS
alert rules can send Slack notifications without a separate service-check
script.

This plugin includes a component sync helper under `bin/`. Keep helper scripts
out of the plugin root because LibreNMS scans root-level plugin PHP files as web
hooks.

```bash
php /opt/librenms/app/Plugins/SolidServer/bin/sync_components.php \
  --device-id 839 \
  --dry-run
```

Use `--dry-run` first to confirm the target device and counts. The script writes
LibreNMS component rows with type `solidserver_dhcp`; it does not write to Solid
Server. Each component is one EIP DHCP shared network.

By default the sync uses the saved SolidServer plugin settings for base URL,
username, password, TLS, and thresholds. Command-line options or environment
variables can still override those values when needed.

Once components exist, create normal LibreNMS alert rules:

```text
%macros.component_critical = "1" && %component.type = "solidserver_dhcp"
```

```text
%macros.component_warning = "1" && %component.type = "solidserver_dhcp"
```

Schedule the sync from cron on the LibreNMS server as the `librenms` user so the
component state refreshes before LibreNMS evaluates alert rules.

The sync also writes `/var/lib/librenms/solidserver-components.json` by default.
The Middlebury alert broker can read that file to add shared-network capacity
details to Slack even when LibreNMS sends only the generic alert rule payload.

Systemd unit examples are included under `systemd/`:

```text
solidserver-components.service
solidserver-components.timer
```

Good alert candidates from this plugin are:

- shared network free percent at or below critical threshold
- shared network free percent at or below warning threshold
- EIP CIDR has no matching LibreNMS interface IP
- EIP VLAN has no matching LibreNMS VLAN inventory entry
- LibreNMS interface match exists, but VLAN could not be detected
