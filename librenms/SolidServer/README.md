# SolidServer LibreNMS plugin

This is a local LibreNMS plugin for displaying EfficientIP Solid Server DHCP
shared-network capacity inside LibreNMS.

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
- Cross-references EIP scope CIDRs with LibreNMS interface IPv4 addresses to
  identify likely gateway/SVI/interface owners for each DHCP network.
- Adds LibreNMS device/port links, interface up/down status, gateway-like
  detection, and open alert counts for matching network interfaces.
- Shows LibreNMS VLAN inventory matches for detected or inferred VLAN IDs.
- Adds attention notes for correlation gaps, inferred VLAN mismatches, missing
  gateway-like interfaces, and capacity warnings.
- Provides a read-only DHCP/IP/DNS lookup panel for IP and hostname searches.

## What this plugin does not do

- It does not write to Solid Server.
- It does not change DHCP, DNS, scopes, ranges, reservations, or shared
  networks.
- It does not create, edit, or delete reservations.
- It does not send Slack messages directly.

## Alerting

The plugin gives LibreNMS a local dashboard/control surface. For Slack alerting,
keep using LibreNMS alert rules against pollable state.

The dashboard itself is not polled by LibreNMS, so alerting uses native
LibreNMS components. The component sync helper lives under `bin/`:

```bash
php /opt/librenms/app/Plugins/SolidServer/bin/sync_components.php --device-id 839
```

By default the sync uses the saved SolidServer plugin settings for base URL,
username, password, TLS, and thresholds. Command-line options or environment
variables can still override those values when needed.

The sync creates/updates LibreNMS components with type:

```text
solidserver_dhcp
```

Once components exist, create normal LibreNMS alert rules:

```text
%macros.component_critical = "1" && %component.type = "solidserver_dhcp"
```

```text
%macros.component_warning = "1" && %component.type = "solidserver_dhcp"
```

The sync also writes `/var/lib/librenms/solidserver-components.json` by default.
The Middlebury alert broker can read that file to add shared-network capacity
details to Slack even when LibreNMS sends only the generic alert rule payload.

Systemd unit examples are included under `systemd/`:

```text
solidserver-components.service
solidserver-components.timer
```
