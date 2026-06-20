@php
$q = $query ?? '';
$r = $results ?? [];
$summary = $r['summary'] ?? ['arp' => 0, 'port_changes' => 0, 'port_last_seen' => 0, 'interfaces' => 0, 'devices' => 0, 'vlans' => 0, 'events' => 0];
$range = $range ?? ['key' => '30h', 'label' => '30 hours', 'seconds' => 30 * 60 * 60];
$rangeOptions = $range_options ?? [];
$section = $section ?? 'ports';
$sectionOptions = $section_options ?? ['summary' => 'Summary', 'changes' => 'Changed ports', 'ports' => 'Port inventory', 'events' => 'Events', 'all' => 'All data'];
$switches = $switches ?? [];
$selectedSwitch = $selected_switch ?? null;
$selectedDeviceId = $selected_device_id ?? null;
$details = $switch_details ?? ['ports' => [], 'port_changes' => [], 'fdb' => [], 'events' => [], 'notes' => [], 'stats' => []];
$stats = $details['stats'] ?? [];
$visibleSummary = $summary;
unset($visibleSummary['port_last_seen']);
$totalMatches = array_sum($visibleSummary);
$pluginUrl = url()->current();
$showSummary = in_array($section, ['summary', 'all'], true);
$showChanges = in_array($section, ['changes', 'all'], true);
$showPorts = in_array($section, ['ports', 'all'], true);
$showEvents = in_array($section, ['events', 'all'], true);
@endphp

<style>
.switch-lookup-page {
    --sl-bg: #1f252b;
    --sl-panel: #252d34;
    --sl-panel-deep: #20262c;
    --sl-border: #39434d;
    --sl-text: #edf2f6;
    --sl-muted: #aeb8c2;
    --sl-ok: #2f9d58;
    --sl-warn: #c98c20;
    --sl-info: #74d5ea;
    color: var(--sl-text);
    font-size: 11px;
}
.switch-lookup-page h3,
.switch-lookup-page h4 { color: var(--sl-text); }
.switch-lookup-page h3 { font-size: 16px; margin: 0; }
.switch-lookup-page h4 { font-size: 13px; margin: 0 0 4px; }
.switch-lookup-page .sl-panel {
    background: var(--sl-panel);
    border: 1px solid var(--sl-border);
    border-radius: 3px;
    margin-bottom: 5px;
    padding: 5px 7px;
}
.switch-lookup-page .sl-muted { color: var(--sl-muted); }
.switch-lookup-page .sl-topbar {
    align-items: center;
    display: grid;
    gap: 5px;
    grid-template-columns: minmax(250px, 390px) auto 1fr;
}
.switch-lookup-page .sl-form { align-items: center; display: flex; gap: 4px; margin: 0; }
.switch-lookup-page .sl-form input[type="text"] { height: 24px; min-width: 230px; width: 100%; }
.switch-lookup-page .sl-range,
.switch-lookup-page .sl-sections { align-items: center; display: flex; flex-wrap: wrap; gap: 3px; margin: 0; }
.switch-lookup-page .sl-range .btn,
.switch-lookup-page .sl-sections .btn { padding: 1px 6px; font-size: 11px; line-height: 1.35; }
.switch-lookup-page .sl-range .btn.active,
.switch-lookup-page .sl-sections .btn.active { box-shadow: inset 0 0 0 2px rgba(255,255,255,.25); font-weight: 700; }
.switch-lookup-page .sl-layout { display: grid; gap: 6px; grid-template-columns: 245px minmax(0, 1fr); }
.switch-lookup-page .sl-left { max-height: calc(100vh - 132px); overflow-y: auto; position: sticky; top: 6px; }
.switch-lookup-page .sl-switch-count { margin-bottom: 5px; }
.switch-lookup-page .sl-switch-list { display: flex; flex-direction: column; gap: 1px; }
.switch-lookup-page .sl-switch-item {
    background: var(--sl-panel-deep);
    border: 1px solid transparent;
    border-radius: 2px;
    color: var(--sl-text);
    display: block;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.15;
    overflow: hidden;
    padding: 3px 6px;
    text-decoration: none;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.switch-lookup-page .sl-switch-item:hover,
.switch-lookup-page .sl-switch-item.active { background: #31404b; border-color: #6bb6d6; color: var(--sl-text); text-decoration: none; }
.switch-lookup-page .sl-header {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 7px;
    justify-content: space-between;
}
.switch-lookup-page .sl-title-line { align-items: baseline; display: flex; flex-wrap: wrap; gap: 8px; }
.switch-lookup-page .sl-chip {
    background: #31404b;
    border: 1px solid var(--sl-border);
    border-radius: 999px;
    display: inline-block;
    margin: 1px 2px 1px 0;
    padding: 1px 7px;
}
.switch-lookup-page .sl-summary { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.switch-lookup-page .sl-card { background: var(--sl-panel-deep); border: 1px solid var(--sl-border); border-radius: 3px; min-width: 56px; padding: 3px 6px; }
.switch-lookup-page .sl-card strong { display: block; font-size: 15px; line-height: 1; }
.switch-lookup-page .sl-table-wrap { overflow-x: auto; }
.switch-lookup-page table { background: var(--sl-panel-deep); color: var(--sl-text); font-size: 11px; line-height: 1.15; margin-bottom: 0; table-layout: auto; width: 100% !important; }
.switch-lookup-page table > thead > tr > th,
.switch-lookup-page table > tbody > tr > td { border-color: var(--sl-border); padding: 2px 7px; vertical-align: middle; white-space: nowrap; }
.switch-lookup-page table > thead > tr > th { background: #20262c; position: sticky; top: 0; z-index: 1; }
.switch-lookup-page .sl-note { background: #2a3440; border-left: 4px solid var(--sl-info); margin: 5px 0; padding: 6px 8px; }
.switch-lookup-page .sl-note.warning { border-left-color: var(--sl-warn); }
.switch-lookup-page .sl-status-up { color: var(--sl-ok); font-weight: 700; }
.switch-lookup-page .sl-status-down { color: #e06666; font-weight: 700; }
.switch-lookup-page a { color: #8fd7ff; }
.switch-lookup-page .sl-loading { opacity: .75; pointer-events: none; }
.switch-lookup-page .sl-right-scroll { max-height: calc(100vh - 205px); overflow-y: auto; }
.switch-lookup-page .sl-port-table { min-width: 760px; }
.switch-lookup-page .sl-change-table th:nth-child(1),
.switch-lookup-page .sl-change-table td:nth-child(1) { width: 180px; }
.switch-lookup-page .sl-change-table th:nth-child(2),
.switch-lookup-page .sl-change-table td:nth-child(2) { width: 130px; }
.switch-lookup-page .sl-change-table th:nth-child(3),
.switch-lookup-page .sl-change-table td:nth-child(3) { width: 95px; }
.switch-lookup-page .sl-change-table th:nth-child(4),
.switch-lookup-page .sl-change-table td:nth-child(4) { width: 85px; }
.switch-lookup-page .sl-change-table th:nth-child(5),
.switch-lookup-page .sl-change-table td:nth-child(5) { width: 55px; text-align: center; }
.switch-lookup-page .sl-change-table th:nth-child(6),
.switch-lookup-page .sl-change-table td:nth-child(6) { white-space: normal; }
.switch-lookup-page .sl-inventory-table th:nth-child(1),
.switch-lookup-page .sl-inventory-table td:nth-child(1) { width: 145px; }
.switch-lookup-page .sl-inventory-table th:nth-child(2),
.switch-lookup-page .sl-inventory-table td:nth-child(2) { width: 90px; }
.switch-lookup-page .sl-inventory-table th:nth-child(3),
.switch-lookup-page .sl-inventory-table td:nth-child(3) { width: 90px; }
.switch-lookup-page .sl-inventory-table th:nth-child(4),
.switch-lookup-page .sl-inventory-table td:nth-child(4) { width: 55px; text-align: center; }
.switch-lookup-page .sl-inventory-table th:nth-child(5),
.switch-lookup-page .sl-inventory-table td:nth-child(5) { width: auto; }
.switch-lookup-page .sl-macs { color: var(--sl-muted); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 10px; }
.switch-lookup-page .sl-age { color: var(--sl-muted); margin-left: 3px; }
@media (max-width: 1100px) {
    .switch-lookup-page .sl-layout { grid-template-columns: 1fr; }
    .switch-lookup-page .sl-left { max-height: 260px; position: static; }
    .switch-lookup-page .sl-topbar { grid-template-columns: 1fr; }
}
</style>

<div class="switch-lookup-page">
    <div class="sl-panel">
        <div class="sl-topbar">
            <form class="sl-form" method="GET">
                <label class="sr-only" for="q">Filter switches or search</label>
                <input class="form-control input-sm" id="q" name="q" type="text" value="{{ $q }}" placeholder="Filter switches, interface, MAC, VLAN, alias">
                <input type="hidden" name="range" value="{{ $range['key'] ?? '30h' }}">
                <input type="hidden" name="section" value="{{ $section }}">
                @if ($selectedDeviceId)
                    <input type="hidden" name="device_id" value="{{ $selectedDeviceId }}">
                @endif
                <button class="btn btn-primary btn-xs" type="submit">Search</button>
                @if ($q !== '' || $selectedDeviceId)
                    <a class="btn btn-default btn-xs" href="{{ $pluginUrl }}">Clear</a>
                @endif
            </form>

            <form class="sl-range" method="GET">
                <input type="hidden" name="q" value="{{ $q }}">
                <input type="hidden" name="section" value="{{ $section }}">
                @if ($selectedDeviceId)
                    <input type="hidden" name="device_id" value="{{ $selectedDeviceId }}">
                @endif
                <span class="sl-muted">Changed:</span>
                @foreach ($rangeOptions as $key => $option)
                    <button class="btn btn-xs {{ ($range['key'] ?? '30h') === $key ? 'btn-primary active' : 'btn-default' }}" name="range" value="{{ $key }}" type="submit">{{ $option['label'] ?? $key }}</button>
                @endforeach
            </form>

            <div class="sl-muted text-right">Updated {{ $fetched_at ?? '' }}</div>
        </div>
    </div>

    @if (!empty($error))
        <div class="sl-panel"><div class="sl-note warning">{{ $error }}</div></div>
    @endif

    <div class="sl-layout">
        <div class="sl-left sl-panel">
            <h4>Switches</h4>
            <div class="sl-muted sl-switch-count">{{ count($switches) }} shown{{ $q !== '' ? ' for "' . $q . '"' : '' }}.</div>
            <div class="sl-switch-list">
                @forelse ($switches as $sw)
                    @php
                    $deviceId = $sw['device_id'] ?? null;
                    $isSelected = $selectedDeviceId && $deviceId && (int) $selectedDeviceId === (int) $deviceId;
                    $href = $pluginUrl . '?' . http_build_query([
                        'device_id' => $deviceId,
                        'q' => $q,
                        'range' => $range['key'] ?? '30h',
                        'section' => $section,
                    ]);
                    $title = trim(($sw['display_name'] ?? $sw['hostname'] ?? '') . ' ' . ($sw['ip'] ?? '') . ' ' . ($sw['location'] ?? '') . ' ports ' . ($sw['port_count'] ?? 0));
                    @endphp
                    <a class="sl-switch-item {{ $isSelected ? 'active' : '' }}" href="{{ $href }}" data-device-id="{{ $deviceId }}" title="{{ $title }}">
                        {{ $sw['display_name'] ?? $sw['hostname'] ?? '' }}
                    </a>
                @empty
                    <div class="sl-note warning">No switches were found. Try clearing the search box.</div>
                @endforelse
            </div>
        </div>

        <div class="sl-right" id="sl-right">
            @if (!empty($selectedSwitch))
                <div class="sl-panel">
                    <div class="sl-header">
                        <div>
                            <div class="sl-title-line">
                                <h3>{{ $selectedSwitch['display_name'] ?? $selectedSwitch['hostname'] ?? '' }}</h3>
                                <span class="sl-muted">{{ $selectedSwitch['ip'] ?? '' }}</span>
                                @if (!empty($selectedSwitch['location']))
                                    <span class="sl-muted">{{ $selectedSwitch['location'] }}</span>
                                @endif
                            </div>
                            <div style="margin-top: 4px;">
                                @if (!empty($selectedSwitch['url']))
                                    <a class="btn btn-default btn-xs" href="{{ $selectedSwitch['url'] }}">Open LibreNMS</a>
                                @endif
                                @if (!empty($selectedSwitch['os']))<span class="sl-chip">{{ $selectedSwitch['os'] }}</span>@endif
                                @if (!empty($selectedSwitch['hardware']))<span class="sl-chip">{{ $selectedSwitch['hardware'] }}</span>@endif
                                @if (!empty($selectedSwitch['last_polled']))<span class="sl-chip">Poll {{ $selectedSwitch['last_polled'] }}</span>@endif
                            </div>
                        </div>

                        <div class="sl-sections">
                            <span class="sl-muted">Show:</span>
                            @foreach ($sectionOptions as $key => $label)
                                @php
                                $sectionHref = $pluginUrl . '?' . http_build_query([
                                    'device_id' => $selectedDeviceId,
                                    'q' => $q,
                                    'range' => $range['key'] ?? '30h',
                                    'section' => $key,
                                ]);
                                @endphp
                                <a class="btn btn-xs sl-section-link {{ $section === $key ? 'btn-primary active' : 'btn-default' }}" href="{{ $sectionHref }}" data-section="{{ $key }}">{{ $label }}</a>
                            @endforeach
                        </div>
                    </div>

                    @if ($showSummary)
                        <div class="sl-summary">
                            <div class="sl-card"><span class="sl-muted">Ports</span><strong>{{ $stats['total'] ?? 0 }}</strong></div>
                            <div class="sl-card"><span class="sl-muted">Up</span><strong>{{ $stats['up'] ?? 0 }}</strong></div>
                            <div class="sl-card"><span class="sl-muted">Down</span><strong>{{ $stats['down'] ?? 0 }}</strong></div>
                            <div class="sl-card"><span class="sl-muted">Changed</span><strong>{{ count($details['port_changes'] ?? []) }}</strong></div>
                            <div class="sl-card"><span class="sl-muted">Events</span><strong>{{ count($details['events'] ?? []) }}</strong></div>
                        </div>
                        @if (!empty($stats['last_change_date']))
                            <div class="sl-note">Newest known port change: <strong>{{ $stats['last_change_date'] }}</strong> - {{ $stats['last_change_age'] ?? '' }}</div>
                        @endif
                    @endif
                </div>

                @if (!empty($details['notes']))
                    <div class="sl-panel">
                        <h4>Notes</h4>
                        @foreach ($details['notes'] as $note)
                            <div class="sl-note warning">{{ $note }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="sl-right-scroll">
                    @if ($showChanges)
                        <div class="sl-panel">
                            <h4>Ports changed in {{ $range['label'] ?? 'selected range' }}</h4>
                            @if (!empty($details['port_changes']))
                                <div class="sl-table-wrap">
                                    <table class="table table-condensed sl-port-table sl-change-table">
                                        <thead>
                                        <tr>
                                            <th>Changed</th>
                                            <th>Interface</th>
                                            <th>Status</th>
                                            <th>Speed</th>
                                            <th>VLAN</th>
                                            <th>MACs on port</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($details['port_changes'] as $row)
                                            @php $lc = $row['last_change'] ?? []; @endphp
                                            <tr>
                                                <td>{{ $lc['date'] ?? '' }} <span class="sl-age">{{ $lc['age'] ?? '' }}</span></td>
                                                <td>
                                                    @if (!empty($row['port_url']))
                                                        <a href="{{ $row['port_url'] }}">{{ $row['ifName'] ?? '' }}</a>
                                                    @else
                                                        {{ $row['ifName'] ?? '' }}
                                                    @endif
                                                </td>
                                                <td><span class="{{ ($row['oper_status'] ?? '') === 'up' ? 'sl-status-up' : (($row['oper_status'] ?? '') === 'down' ? 'sl-status-down' : '') }}">{{ $row['oper_status'] ?? '' }}</span></td>
                                                <td>{{ $row['ifSpeed_label'] ?? $row['ifSpeed'] ?? '' }}</td>
                                                <td>{{ $row['vlan_label'] ?? '' }}</td>
                                                <td class="sl-macs">{{ $row['macs'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="sl-note">No changed ports found in {{ $range['label'] ?? 'selected range' }} for this switch.</div>
                            @endif
                        </div>
                    @endif

                    @if ($showPorts)
                        <div class="sl-panel">
                            <h4>Port inventory</h4>
                            <div class="sl-table-wrap">
                                <table class="table table-condensed sl-port-table sl-inventory-table">
                                    <thead>
                                    <tr>
                                        <th>Interface</th>
                                        <th>Status</th>
                                        <th>Speed</th>
                                        <th>VLAN</th>
                                        <th>Last change</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse (($details['ports'] ?? []) as $row)
                                        @php $lc = $row['last_change'] ?? []; @endphp
                                        <tr>
                                            <td>
                                                @if (!empty($row['port_url']))
                                                    <a href="{{ $row['port_url'] }}">{{ $row['ifName'] ?? '' }}</a>
                                                @else
                                                    {{ $row['ifName'] ?? '' }}
                                                @endif
                                            </td>
                                            <td><span class="{{ ($row['oper_status'] ?? '') === 'up' ? 'sl-status-up' : (($row['oper_status'] ?? '') === 'down' ? 'sl-status-down' : '') }}">{{ $row['oper_status'] ?? '' }}</span></td>
                                            <td>{{ $row['ifSpeed_label'] ?? $row['ifSpeed'] ?? '' }}</td>
                                            <td>{{ $row['vlan_label'] ?? '' }}</td>
                                            <td>{{ $lc['date'] ?? '' }} <span class="sl-age">{{ $lc['age'] ?? '' }}</span></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5">No ports found for this switch.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    @if ($showEvents)
                        <div class="sl-panel">
                            <h4>Recent events</h4>
                            @if (!empty($details['events']))
                                <div class="sl-table-wrap">
                                    <table class="table table-condensed">
                                        <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Message</th>
                                            <th>Reference</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($details['events'] as $row)
                                            <tr>
                                                <td>{{ $row['datetime'] ?? '' }}</td>
                                                <td>{{ $row['type'] ?? '' }}</td>
                                                <td>{{ $row['message'] ?? '' }}</td>
                                                <td>{{ $row['reference'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="sl-note">No recent events found for this switch.</div>
                            @endif
                        </div>
                    @endif
                </div>
            @else
                <div class="sl-panel">
                    <h4>Pick a switch</h4>
                    <div class="sl-note">Choose a switch from the left. Use the Show buttons to switch between Summary, Changed ports, Port inventory, Events, or All data.</div>
                </div>
            @endif

            @if (!empty($r['notes']))
                <div class="sl-panel">
                    <h4>Lookup notes</h4>
                    @foreach ($r['notes'] as $note)
                        <div class="sl-note warning">{{ $note }}</div>
                    @endforeach
                </div>
            @endif

            @if ($q !== '' && $totalMatches === 0 && empty($selectedSwitch))
                <div class="sl-panel"><div class="sl-note warning">No global LibreNMS matches were found for <strong>{{ $q }}</strong>.</div></div>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    var root = document.querySelector('.switch-lookup-page');
    if (!root) { return; }

    var list = root.querySelector('.sl-switch-list');
    var right = root.querySelector('#sl-right');
    if (!right || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') { return; }

    function setHiddenValue(name, value) {
        root.querySelectorAll('form.sl-form, form.sl-range').forEach(function (form) {
            var input = form.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            input.value = value || '';
        });
    }

    function loadRight(url) {
        right.classList.add('sl-loading');
        right.innerHTML = '<div class="sl-panel"><div class="sl-note">Loading...</div></div>';

        window.fetch(url, {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                var doc = new window.DOMParser().parseFromString(html, 'text/html');
                var nextRight = doc.querySelector('#sl-right');
                if (!nextRight) { throw new Error('Could not find switch detail panel in response'); }
                right.innerHTML = nextRight.innerHTML;
                right.classList.remove('sl-loading');
                window.history.replaceState(null, '', url);
            })
            .catch(function () {
                window.location.href = url;
            });
    }

    if (list) {
        list.addEventListener('click', function (event) {
            var link = event.target.closest('a.sl-switch-item');
            if (!link) { return; }
            event.preventDefault();
            list.querySelectorAll('a.sl-switch-item.active').forEach(function (item) { item.classList.remove('active'); });
            link.classList.add('active');
            setHiddenValue('device_id', link.getAttribute('data-device-id'));
            loadRight(link.href);
        });
    }

    root.addEventListener('click', function (event) {
        var link = event.target.closest('a.sl-section-link');
        if (!link) { return; }
        event.preventDefault();
        setHiddenValue('section', link.getAttribute('data-section'));
        loadRight(link.href);
    });
})();
</script>
