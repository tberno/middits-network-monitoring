<style>
    .solidserver-page {
        --ss-bg: #23282e;
        --ss-panel: #2b3138;
        --ss-panel-soft: #303740;
        --ss-panel-deep: #20262d;
        --ss-border: #1b2026;
        --ss-muted: #9aa7b4;
        --ss-text: #f2f5f7;
        --ss-ok: #61bd66;
        --ss-warn: #f0a12b;
        --ss-crit: #e35d5d;
        --ss-info: #54bfd8;
        color: var(--ss-text);
    }

    .solidserver-page .ss-header {
        align-items: flex-end;
        display: flex;
        flex-wrap: wrap;
        gap: 10px 18px;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .solidserver-page .ss-header h3 {
        margin: 0 0 4px;
    }

    .solidserver-page .ss-meta {
        color: var(--ss-muted);
    }

    .solidserver-page .ss-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(120px, 180px));
        gap: 8px;
        margin: 0;
    }

    .solidserver-page .ss-stat {
        border-left: 4px solid var(--ss-info);
        background: var(--ss-panel);
        padding: 8px 10px;
    }

    .solidserver-page .ss-stat strong {
        display: block;
        font-size: 20px;
        line-height: 1.1;
    }

    .solidserver-page .ss-stat span {
        color: var(--ss-muted);
        font-size: 12px;
        text-transform: uppercase;
    }

    .solidserver-page .ss-stat.critical { border-color: var(--ss-crit); }
    .solidserver-page .ss-stat.warning { border-color: var(--ss-warn); }
    .solidserver-page .ss-stat.ok { border-color: var(--ss-ok); }
    .solidserver-page .ss-stat.total { border-color: var(--ss-info); }

    .solidserver-page .ss-toolbar {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 10px 0;
    }

    .solidserver-page .ss-lookup {
        align-items: center;
        background: var(--ss-panel-deep);
        border: 1px solid #37414b;
        display: grid;
        gap: 10px;
        grid-template-columns: auto minmax(220px, 420px) auto 1fr;
        margin: 14px 0 12px;
        padding: 10px 12px;
    }

    .solidserver-page .ss-filter {
        max-width: 340px;
    }

    .solidserver-page .ss-table {
        table-layout: fixed;
    }

    .solidserver-page .ss-table th {
        background: var(--ss-panel);
        border-color: var(--ss-border);
        color: var(--ss-text);
        position: sticky;
        top: 0;
        z-index: 2;
    }

    .solidserver-page .ss-table td {
        border-color: var(--ss-border);
        padding: 7px 8px;
        vertical-align: middle;
    }

    .solidserver-page .ss-network-row.warning {
        background: rgba(240, 161, 43, 0.12);
        box-shadow: inset 3px 0 0 var(--ss-warn);
    }

    .solidserver-page .ss-network-row.critical {
        background: rgba(227, 93, 93, 0.12);
        box-shadow: inset 3px 0 0 var(--ss-crit);
    }

    .solidserver-page .ss-badge {
        border-radius: 3px;
        color: #fff;
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
        min-width: 48px;
        padding: 4px 6px;
        text-align: center;
    }

    .solidserver-page .ss-badge.ok { background: var(--ss-ok); }
    .solidserver-page .ss-badge.warning { background: var(--ss-warn); }
    .solidserver-page .ss-badge.critical { background: var(--ss-crit); }
    .solidserver-page .ss-badge.info { background: var(--ss-info); }
    .solidserver-page .ss-badge.muted { background: #6c7782; }

    .solidserver-page .ss-percent {
        min-width: 132px;
    }

    .solidserver-page .ss-percent-label {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        margin-bottom: 2px;
        padding: 2px 5px;
    }

    .solidserver-page .ss-percent-label.ok { background: var(--ss-ok); }
    .solidserver-page .ss-percent-label.warning { background: var(--ss-warn); }
    .solidserver-page .ss-percent-label.critical { background: var(--ss-crit); }
    .solidserver-page .ss-bar {
        background: #252b33;
        border-radius: 2px;
        height: 6px;
        overflow: hidden;
    }

    .solidserver-page .ss-bar span {
        display: block;
        height: 6px;
    }

    .solidserver-page .ss-bar .ok { background: var(--ss-ok); }
    .solidserver-page .ss-bar .warning { background: var(--ss-warn); }
    .solidserver-page .ss-bar .critical { background: var(--ss-crit); }

    .solidserver-page .ss-detail {
        background: #252b31;
        border-left: 4px solid #39434d;
        padding: 10px 12px 14px;
    }

    .solidserver-page .ss-result {
        background: var(--ss-panel-deep);
        border: 1px solid #37414b;
        margin: 10px 0 12px;
        padding: 12px;
    }

    .solidserver-page .ss-result h4 {
        margin-top: 0;
    }

    .solidserver-page .ss-pill-line {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin: 8px 0;
    }

    .solidserver-page .ss-chip {
        background: #3a434d;
        border-radius: 3px;
        color: #eef3f6;
        display: inline-block;
        font-size: 12px;
        padding: 3px 7px;
    }

    .solidserver-page .ss-link {
        color: #74d5ea;
        font-weight: 700;
    }

    .solidserver-page .ss-mini {
        color: var(--ss-muted);
        font-size: 11px;
        line-height: 1.3;
    }

    .solidserver-page .ss-status {
        border-radius: 2px;
        display: inline-block;
        font-size: 10px;
        font-weight: 700;
        line-height: 1;
        padding: 3px 5px;
        text-transform: uppercase;
    }

    .solidserver-page .ss-status.up { background: var(--ss-ok); color: #fff; }
    .solidserver-page .ss-status.down { background: var(--ss-crit); color: #fff; }
    .solidserver-page .ss-status.muted { background: #6c7782; color: #fff; }

    .solidserver-page .ss-detail-row {
        display: none;
    }

    .solidserver-page .ss-detail-row.is-open {
        display: table-row;
    }

    .solidserver-page .ss-detail-toggle {
        min-width: 72px;
    }

    .solidserver-page details.ss-disclosure > summary {
        color: var(--ss-info);
        cursor: pointer;
        font-weight: 700;
        list-style: none;
    }

    .solidserver-page details.ss-disclosure > summary::-webkit-details-marker {
        display: none;
    }

    .solidserver-page details.ss-disclosure > summary:before {
        content: "+";
        display: inline-block;
        margin-right: 6px;
        width: 12px;
    }

    .solidserver-page details.ss-disclosure[open] > summary:before {
        content: "-";
    }

    .solidserver-page .ss-notes {
        display: grid;
        gap: 8px;
        margin: 10px 0;
    }

    .solidserver-page .ss-note {
        border-left: 4px solid var(--ss-info);
        background: rgba(84, 191, 216, 0.14);
        padding: 8px 10px;
    }

    .solidserver-page .ss-note.warning {
        border-color: var(--ss-warn);
        background: rgba(240, 161, 43, 0.16);
    }

    .solidserver-page .ss-note.critical {
        border-color: var(--ss-crit);
        background: rgba(227, 93, 93, 0.16);
    }

    .solidserver-page .ss-muted {
        color: var(--ss-muted);
    }

    @media (max-width: 900px) {
        .solidserver-page .ss-summary {
            grid-template-columns: repeat(2, minmax(140px, 1fr));
        }

        .solidserver-page .ss-lookup {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="solidserver-page">
    @if ($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @else
        @php
            $summary = $summary ?? ['critical' => 0, 'warning' => 0, 'ok' => 0, 'total' => count($shared_networks ?? [])];
            $lookupQuery = $lookup_query ?? '';
        @endphp

        <div class="ss-header">
            <div>
                <h3>Solid Server DHCP shared networks</h3>
                <div class="ss-meta">
                    Source: {{ $base_url }}
                    @if (!empty($fetched_at))
                        <span>| fetched {{ $fetched_at }}</span>
                    @endif
                    @if (!empty($raw_range_count))
                        <span>| raw ranges {{ number_format($raw_range_count) }}</span>
                    @endif
                </div>
            </div>

            <div class="ss-summary">
                <div class="ss-stat critical">
                    <strong>{{ number_format($summary['critical'] ?? 0) }}</strong>
                    <span>Critical</span>
                </div>
                <div class="ss-stat warning">
                    <strong>{{ number_format($summary['warning'] ?? 0) }}</strong>
                    <span>Warning</span>
                </div>
                <div class="ss-stat ok">
                    <strong>{{ number_format($summary['ok'] ?? 0) }}</strong>
                    <span>OK</span>
                </div>
                <div class="ss-stat total">
                    <strong>{{ number_format($summary['total'] ?? count($shared_networks ?? [])) }}</strong>
                    <span>Shared networks</span>
                </div>
            </div>
        </div>

        <form class="ss-lookup" method="GET">
            <label class="sr-only" for="lookup">DHCP / IP lookup</label>
            <strong>DHCP / IP lookup</strong>
            <input class="form-control input-sm ss-filter" id="lookup" name="lookup" value="{{ $lookupQuery }}" placeholder="IP, MAC, hostname, or reservation">
            <button class="btn btn-primary btn-sm" type="submit">Lookup</button>
            <span class="ss-muted">Read-only lookup; no reservation changes are made.</span>
        </form>

        @if (!empty($lookup))
            <div class="ss-result">
                <h4>Lookup result: {{ $lookup['query'] ?? $lookupQuery }}</h4>

                @if (!empty($lookup['resolved_ips']))
                    <div class="ss-muted">DNS resolved IP{{ count($lookup['resolved_ips']) === 1 ? '' : 's' }}</div>
                    <div class="ss-pill-line">
                        @foreach ($lookup['resolved_ips'] as $ip)
                            <span class="ss-chip">{{ $ip }}</span>
                        @endforeach
                    </div>
                @elseif (($lookup['type'] ?? '') === 'name')
                    <div class="ss-note warning">Hostname did not resolve to an IPv4 address from the LibreNMS server.</div>
                @endif

                @if (!empty($lookup['api_results']))
                    <h4>EIP DNS records</h4>
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Source</th>
                                <th>Record</th>
                                <th>Fields</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lookup['api_results'] as $result)
                                <tr>
                                    <td>{{ $result['label'] ?? $result['endpoint'] ?? 'Record' }}</td>
                                    <td>{{ $result['summary'] ?? '' }}</td>
                                    <td>
                                        @foreach (($result['row'] ?? []) as $key => $value)
                                            <span class="label label-default">{{ $key }}={{ $value }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if (($lookup['type'] ?? '') === 'name' && empty($lookup['dns_record_count']))
                    <div class="ss-note">
                        The hostname resolves in DNS, but the Solid Server DNS record endpoint did not return a matching row. DHCP matching below is based on the resolved IP.
                    </div>
                @endif

                @if (!empty($lookup['range_matches']))
                    <h4>DHCP range matches</h4>
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th>Matched IP</th>
                                <th>Shared network</th>
                                <th>Range</th>
                                <th>Scope</th>
                                <th>VLAN</th>
                                <th>Used</th>
                                <th>Total</th>
                                <th>DHCP source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lookup['range_matches'] as $match)
                                <tr>
                                    <td>{{ $match['matched_ip'] ?? '' }}</td>
                                    <td>{{ $match['shared_network'] ?? '' }}</td>
                                    <td>{{ $match['start'] ?? '' }} - {{ $match['end'] ?? '' }}</td>
                                    <td>{{ $match['scope'] ?? '' }}</td>
                                    <td>{{ $match['vlan'] ?? 'unknown' }}</td>
                                    <td>{{ isset($match['used']) ? number_format($match['used']) : 'unknown' }}</td>
                                    <td>{{ isset($match['total']) ? number_format($match['total']) : 'unknown' }}</td>
                                    <td>{{ $match['server'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="ss-note">DNS/static result found, but no matching DHCP pool contains the resolved IP in the current range data.</div>
                @endif
            </div>
        @endif

        <div class="ss-toolbar">
            <input class="form-control input-sm ss-filter" id="solidserver-filter" placeholder="Filter shared networks, VLAN, DHCP source">
            <button class="btn btn-default btn-sm" data-ss-state="all" type="button">All</button>
            <button class="btn btn-danger btn-sm" data-ss-state="critical" type="button">Critical</button>
            <button class="btn btn-warning btn-sm" data-ss-state="warning" type="button">Warning</button>
            <button class="btn btn-success btn-sm" data-ss-state="ok" type="button">OK</button>
        </div>

        <table class="table table-condensed table-striped ss-table" id="solidserver-networks">
            <thead>
                <tr>
                    <th style="width: 72px;">State</th>
                    <th style="width: 190px;">Shared network</th>
                    <th style="width: 110px;">VLAN</th>
                    <th style="width: 150px;">Free</th>
                    <th style="width: 150px;">Used %</th>
                    <th style="width: 70px;">Used</th>
                    <th style="width: 70px;">Total</th>
                    <th style="width: 72px;">Ranges</th>
                    <th style="width: 110px;">LibreNMS</th>
                    <th style="width: 90px;">Attention</th>
                    <th>DHCP source</th>
                    <th style="width: 110px;">Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shared_networks as $network)
                    @php
                        $state = $network['state'] ?? 'unknown';
                        $stateClass = in_array($state, ['critical', 'warning', 'ok'], true) ? $state : 'muted';
                        $freePercent = $network['free_percent'] ?? null;
                        $usedPercent = $network['used_percent'] ?? ($freePercent !== null ? 100 - $freePercent : null);
                        $vlans = $network['vlans'] ?? [];
                        $vlanText = $vlans ? implode(', ', $vlans) : ($network['vlan'] ?? 'not detected');
                        $servers = $network['servers'] ?? $network['server_names'] ?? [];
                        $serverText = is_array($servers) ? implode(', ', $servers) : (string) $servers;
                        $librenms = $network['librenms'] ?? [];
                        $interfaceMatches = $librenms['interface_matches'] ?? [];
                        $vlanMatches = $librenms['vlan_matches'] ?? [];
                        $deviceMatches = $librenms['device_matches'] ?? [];
                        $deviceCount = $librenms['device_count'] ?? count($deviceMatches);
                        $gatewayCount = $librenms['gateway_count'] ?? 0;
                        $openAlertCount = $librenms['open_alert_count'] ?? 0;
                        $notes = $network['attention_notes'] ?? $network['notes'] ?? [];
                        $ranges = $network['ranges'] ?? [];
                        $deviceText = implode(' ', array_map(fn ($device) => $device['hostname'] ?? '', $deviceMatches));
                    @endphp

                    <tr class="ss-network-row {{ $state }}" data-state="{{ $state }}" data-search="{{ strtolower(($network['name'] ?? '') . ' ' . $vlanText . ' ' . $serverText . ' ' . $deviceText) }}">
                        <td><span class="ss-badge {{ $stateClass }}">{{ $state }}</span></td>
                        <td>{{ $network['name'] ?? 'unknown' }}</td>
                        <td class="{{ $vlans ? '' : 'ss-muted' }}">{{ $vlanText }}</td>
                        <td class="ss-percent">
                            @if ($freePercent !== null)
                                <span class="ss-percent-label {{ $stateClass }}">{{ number_format($freePercent, 2) }}%</span>
                                <div class="ss-bar"><span class="{{ $stateClass }}" style="width: {{ max(0, min(100, $freePercent)) }}%;"></span></div>
                            @else
                                <span class="ss-muted">unknown</span>
                            @endif
                        </td>
                        <td class="ss-percent">
                            @if ($usedPercent !== null)
                                <span class="ss-percent-label {{ $stateClass }}">{{ number_format($usedPercent, 2) }}%</span>
                                <div class="ss-bar"><span class="{{ $stateClass }}" style="width: {{ max(0, min(100, $usedPercent)) }}%;"></span></div>
                            @else
                                <span class="ss-muted">unknown</span>
                            @endif
                        </td>
                        <td>{{ number_format($network['used'] ?? 0) }}</td>
                        <td>{{ number_format($network['total'] ?? 0) }}</td>
                        <td>{{ number_format($network['range_count'] ?? count($ranges)) }}</td>
                        <td>
                            @if (count($interfaceMatches))
                                <span class="ss-badge muted">{{ $deviceCount }} dev</span>
                                <div class="ss-mini">{{ count($interfaceMatches) }} intf{{ $gatewayCount ? ' / ' . $gatewayCount . ' gw' : '' }}</div>
                                @if ($openAlertCount)
                                    <div class="ss-mini">{{ $openAlertCount }} open alert{{ $openAlertCount === 1 ? '' : 's' }}</div>
                                @endif
                            @elseif (count($vlanMatches))
                                <span class="ss-badge muted">{{ count($vlanMatches) }} vlan</span>
                            @else
                                <span class="ss-muted">none</span>
                            @endif
                        </td>
                        <td>
                            @if (count($notes))
                                <span class="ss-badge {{ $state === 'ok' ? 'info' : $stateClass }}">{{ count($notes) }} note</span>
                            @else
                                <span class="ss-muted">none</span>
                            @endif
                        </td>
                        <td>{{ $serverText ?: 'unknown' }}</td>
                        <td>
                            <button class="btn btn-info btn-xs ss-detail-toggle" data-ss-detail="ss-detail-{{ $loop->index }}" type="button">{{ number_format(count($ranges) ?: ($network['range_count'] ?? 0)) }} ranges</button>
                        </td>
                    </tr>

                    <tr id="ss-detail-{{ $loop->index }}" class="ss-detail-row" data-state="{{ $state }}" data-search="{{ strtolower(($network['name'] ?? '') . ' ' . $vlanText . ' ' . $serverText . ' ' . $deviceText) }}">
                        <td colspan="12" class="ss-detail">
                            <details class="ss-disclosure">
                                <summary>Details for {{ $network['name'] ?? 'unknown' }}</summary>

                                @if (count($interfaceMatches))
                                    <h4>LibreNMS device and interface matches</h4>
                                    <table class="table table-condensed">
                                        <thead>
                                            <tr>
                                                <th>EIP CIDR</th>
                                                <th>Interface IP</th>
                                                <th>Device</th>
                                                <th>Port</th>
                                                <th>Status</th>
                                                <th>VLAN</th>
                                                <th>Alerts</th>
                                                <th>Description</th>
                                                <th>Alias</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($interfaceMatches as $match)
                                                @php
                                                    $oper = strtolower((string) ($match['oper_status'] ?? ''));
                                                    $admin = strtolower((string) ($match['admin_status'] ?? ''));
                                                    $statusClass = $oper === 'up' ? 'up' : ($oper === 'down' ? 'down' : 'muted');
                                                @endphp
                                                <tr>
                                                    <td>{{ $match['cidr'] ?? '' }}</td>
                                                    <td>{{ $match['ip'] ?? $match['interface_ip'] ?? '' }}</td>
                                                    <td>
                                                        @if (!empty($match['device_url']))
                                                            <a class="ss-link" href="{{ $match['device_url'] }}">{{ $match['hostname'] ?? $match['device'] ?? '' }}</a>
                                                        @else
                                                            {{ $match['hostname'] ?? $match['device'] ?? '' }}
                                                        @endif
                                                        @if (!empty($match['is_gateway_like']))
                                                            <div class="ss-mini">gateway-like</div>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if (!empty($match['port_url']))
                                                            <a class="ss-link" href="{{ $match['port_url'] }}">{{ $match['ifName'] ?? $match['port'] ?? '' }}</a>
                                                        @else
                                                            {{ $match['ifName'] ?? $match['port'] ?? '' }}
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <span class="ss-status {{ $statusClass }}">{{ $oper ?: 'unknown' }}</span>
                                                        @if ($admin && $admin !== $oper)
                                                            <div class="ss-mini">admin {{ $admin }}</div>
                                                        @endif
                                                    </td>
                                                    <td>{{ $match['inferred_vlan'] ?? 'unknown' }}</td>
                                                    <td>{{ number_format($match['open_alerts'] ?? 0) }}</td>
                                                    <td>{{ $match['ifDescr'] ?? $match['description'] ?? '' }}</td>
                                                    <td>{{ $match['ifAlias'] ?? $match['alias'] ?? '' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif

                                @if (count($vlanMatches))
                                    <h4>LibreNMS VLAN inventory</h4>
                                    <table class="table table-condensed">
                                        <thead>
                                            <tr>
                                                <th>VLAN</th>
                                                <th>Name</th>
                                                <th>Device</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($vlanMatches as $vlan => $matches)
                                                @foreach ($matches as $match)
                                                    <tr>
                                                        <td>{{ $vlan }}</td>
                                                        <td>{{ $match['name'] ?? '' }}</td>
                                                        <td>{{ $match['hostname'] ?? '' }}</td>
                                                    </tr>
                                                @endforeach
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif

                                @if (count($notes))
                                    <h4>Attention notes</h4>
                                    <div class="ss-notes">
                                        @foreach ($notes as $note)
                                            @php
                                                $noteText = is_array($note) ? ($note['text'] ?? $note['message'] ?? json_encode($note)) : $note;
                                                $noteSeverity = is_array($note) ? ($note['severity'] ?? $note['state'] ?? 'info') : 'info';
                                                if (str_contains(strtolower($noteText), 'threshold') || str_contains(strtolower($noteText), 'free capacity')) {
                                                    $noteSeverity = $state === 'critical' ? 'critical' : 'warning';
                                                }
                                            @endphp
                                            <div class="ss-note {{ in_array($noteSeverity, ['critical', 'warning'], true) ? $noteSeverity : '' }}">{{ $noteText }}</div>
                                        @endforeach
                                    </div>
                                @endif

                                @if (count($ranges))
                                    <h4>Ranges</h4>
                                    <table class="table table-condensed">
                                        <thead>
                                            <tr>
                                                <th>Range</th>
                                                <th>Scope</th>
                                                <th>VLAN</th>
                                                <th>Used</th>
                                                <th>Total</th>
                                                <th>Free</th>
                                                <th>Lease %</th>
                                                <th>State</th>
                                                <th>HA duplicates</th>
                                                <th>Failover</th>
                                                <th>Source</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($ranges as $range)
                                                <tr>
                                                    <td>{{ $range['start'] ?? '' }} - {{ $range['end'] ?? '' }}</td>
                                                    <td>{{ $range['scope'] ?? $range['cidr'] ?? '' }}</td>
                                                    <td>{{ $range['vlan'] ?? (($range['vlans'] ?? []) ? implode(', ', $range['vlans']) : 'unknown') }}</td>
                                                    <td>{{ number_format($range['used'] ?? 0) }}</td>
                                                    <td>{{ number_format($range['total'] ?? 0) }}</td>
                                                    <td>{{ number_format($range['free'] ?? 0) }}</td>
                                                    <td>
                                                        @php $lease = $range['lease_percent'] ?? null; @endphp
                                                        @if ($lease !== null)
                                                            <span class="ss-badge {{ $lease >= 90 ? 'warning' : 'ok' }}">{{ number_format($lease, 2) }}%</span>
                                                        @else
                                                            <span class="ss-muted">unknown</span>
                                                        @endif
                                                    </td>
                                                    <td>{{ $range['state'] ?? '' }}</td>
                                                    <td>{{ $range['duplicate_count'] ?? 0 }}</td>
                                                    <td>{{ $range['failover'] ?? '' }}</td>
                                                    <td>{{ $range['server'] ?? (($range['servers'] ?? []) ? implode(', ', array_keys($range['servers'])) : '') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12">No DHCP shared network data returned.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif
</div>

<script>
    (function () {
        var filter = document.getElementById('solidserver-filter');
        var buttons = document.querySelectorAll('[data-ss-state]');
        var rows = document.querySelectorAll('#solidserver-networks tbody tr');
        var toggles = document.querySelectorAll('[data-ss-detail]');
        var activeState = 'all';

        function applyFilter() {
            var text = (filter && filter.value ? filter.value : '').toLowerCase();

            rows.forEach(function (row) {
                var stateMatch = activeState === 'all' || row.getAttribute('data-state') === activeState;
                var textMatch = !text || (row.getAttribute('data-search') || '').indexOf(text) !== -1;
                var visible = stateMatch && textMatch;

                if (row.classList.contains('ss-detail-row')) {
                    row.style.display = visible && row.classList.contains('is-open') ? 'table-row' : 'none';
                } else {
                    row.style.display = visible ? '' : 'none';
                }
            });
        }

        if (filter) {
            filter.addEventListener('input', applyFilter);
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activeState = button.getAttribute('data-ss-state') || 'all';
                applyFilter();
            });
        });

        toggles.forEach(function (button) {
            button.addEventListener('click', function () {
                var detail = document.getElementById(button.getAttribute('data-ss-detail'));
                if (!detail) {
                    return;
                }

                detail.classList.toggle('is-open');
                applyFilter();
            });
        });
    })();
</script>
