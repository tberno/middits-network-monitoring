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

## What this plugin does not do

- It does not write to Solid Server.
- It does not change DHCP, DNS, scopes, ranges, reservations, or shared
  networks.
- It does not send Slack messages directly.

## Alerting note

The plugin gives LibreNMS a local dashboard/control surface. For Slack alerting,
keep using LibreNMS alert rules against pollable state. The current practical
path is still `nms/check_solidserver.py` as a LibreNMS service check, or a later
plugin-package version that writes first-class LibreNMS components/sensors.
