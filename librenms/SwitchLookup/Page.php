<?php

namespace App\Plugins\SwitchLookup;

use App\Plugins\Hooks\PageHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class Page extends PageHook
{
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('global-read');
    }

    public function data(array $settings = []): array
    {
        $query = trim((string) request()->query('q', ''));
        $range = $this->normalizeRange((string) request()->query('range', '30h'));
        $section = $this->normalizeSection((string) request()->query('section', 'ports'));
        $selectedDeviceId = $this->selectedDeviceId();
        $results = $this->emptyResults();
        $switches = [];
        $selectedSwitch = null;
        $switchDetails = $this->emptySwitchDetails();
        $error = null;

        try {
            $switches = $this->lookupSwitches($query, $selectedDeviceId);

            if ($selectedDeviceId === null && $query !== '' && !empty($switches)) {
                $selectedDeviceId = (int) $switches[0]['device_id'];
            }

            if ($selectedDeviceId !== null) {
                $selectedSwitch = $this->lookupDeviceById($selectedDeviceId);
                if ($selectedSwitch !== null) {
                    $switchDetails = $this->lookupSwitchDetails($selectedDeviceId, $range);
                }
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        if ($query !== '') {
            try {
                $results = $this->lookup($query, $range);
            } catch (\Throwable $exception) {
                $error = trim(($error ? $error . ' ' : '') . $exception->getMessage());
            }
        }

        return [
            'error' => $error,
            'fetched_at' => date('Y-m-d H:i:s'),
            'query' => $query,
            'range' => $range,
            'range_options' => $this->rangeOptions(),
            'section' => $section,
            'section_options' => $this->sectionOptions(),
            'results' => $results,
            'selected_device_id' => $selectedDeviceId,
            'selected_switch' => $selectedSwitch,
            'switch_details' => $switchDetails,
            'switches' => $switches,
        ];
    }

    private function emptyResults(): array
    {
        return [
            'arp_matches' => [],
            'device_matches' => [],
            'event_matches' => [],
            'interface_matches' => [],
            'notes' => [],
            'port_last_seen' => [],
            'summary' => [
                'arp' => 0,
                'devices' => 0,
                'events' => 0,
                'interfaces' => 0,
                'port_changes' => 0,
                'port_last_seen' => 0,
                'vlans' => 0,
            ],
            'switch_port_changes' => [],
            'type' => 'empty',
            'vlan_matches' => [],
        ];
    }

    private function emptySwitchDetails(): array
    {
        return [
            'events' => [],
            'fdb' => [],
            'notes' => [],
            'port_changes' => [],
            'ports' => [],
            'stats' => [
                'admin_down' => 0,
                'down' => 0,
                'last_change' => null,
                'last_change_age' => '',
                'last_change_date' => '',
                'total' => 0,
                'up' => 0,
            ],
        ];
    }

    private function selectedDeviceId(): ?int
    {
        $value = request()->query('device_id');
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $deviceId = (int) $value;
        return $deviceId > 0 ? $deviceId : null;
    }

    private function lookup(string $query, array $range): array
    {
        $results = $this->emptyResults();
        $type = $this->lookupType($query);
        $results['type'] = $type;

        $results['arp_matches'] = $this->safeLookup('ARP lookup', fn () => $this->lookupArpMatches($query, $type), $results);
        $results['switch_port_changes'] = $this->safeLookup('Switch port change lookup', fn () => $this->lookupSwitchPortChanges($query, $range), $results);
        $results['port_last_seen'] = $this->safeLookup('FDB / port last seen lookup', fn () => $this->lookupPortLastSeen($query, $type, $results['arp_matches']), $results);
        $results['interface_matches'] = $this->safeLookup('Interface lookup', fn () => $this->lookupInterfaceMatches($query, $type), $results);
        $results['device_matches'] = $this->safeLookup('Device lookup', fn () => $this->lookupDeviceMatches($query), $results);
        $results['vlan_matches'] = $this->safeLookup('VLAN lookup', fn () => $this->lookupVlanMatches($query), $results);
        $results['event_matches'] = $this->safeLookup('Event lookup', fn () => $this->lookupEventMatches($query), $results);

        $results['summary'] = [
            'arp' => count($results['arp_matches']),
            'devices' => count($results['device_matches']),
            'events' => count($results['event_matches']),
            'interfaces' => count($results['interface_matches']),
            'port_changes' => count($results['switch_port_changes']),
            'port_last_seen' => count($results['port_last_seen']),
            'vlans' => count($results['vlan_matches']),
        ];

        return $results;
    }

    private function safeLookup(string $label, callable $callback, array &$results): array
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            $results['notes'][] = $label . ': ' . $exception->getMessage();
            return [];
        }
    }

    private function lookupSwitches(string $query, ?int $selectedDeviceId): array
    {
        if (!$this->tableExists('devices') || !$this->tableExists('ports')) {
            return [];
        }

        $portStats = DB::table('ports')
            ->select('device_id', DB::raw('COUNT(*) as port_count'));

        if ($this->hasColumn('ports', 'ifOperStatus')) {
            $portStats->addSelect(DB::raw("SUM(CASE WHEN ifOperStatus = 'up' THEN 1 ELSE 0 END) as ports_up"));
            $portStats->addSelect(DB::raw("SUM(CASE WHEN ifOperStatus = 'down' THEN 1 ELSE 0 END) as ports_down"));
        } else {
            $portStats->addSelect(DB::raw('0 as ports_up'), DB::raw('0 as ports_down'));
        }

        $portStats->groupBy('device_id');

        $builder = DB::table('devices')
            ->leftJoinSub($portStats, 'port_stats', function ($join) {
                $join->on('port_stats.device_id', '=', 'devices.device_id');
            })
            ->select($this->selectList([
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'display', 'display'],
                ['devices', 'ip', 'ip'],
                ['devices', 'os', 'os'],
                ['devices', 'hardware', 'hardware'],
                ['devices', 'location', 'location'],
                ['devices', 'status', 'status'],
                ['devices', 'last_polled', 'last_polled'],
                ['devices', 'type', 'device_type'],
            ]))
            ->addSelect(DB::raw('COALESCE(port_stats.port_count, 0) as port_count'))
            ->addSelect(DB::raw('COALESCE(port_stats.ports_up, 0) as ports_up'))
            ->addSelect(DB::raw('COALESCE(port_stats.ports_down, 0) as ports_down'))
            ->whereRaw('COALESCE(port_stats.port_count, 0) > 0');

        if ($query !== '') {
            $like = '%' . $query . '%';
            $builder->where(function ($where) use ($like) {
                $this->orWhereLikeIfColumn($where, 'devices', 'hostname', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'sysName', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'display', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'ip', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'hardware', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'location', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'os', $like);
            });
        } elseif ($this->hasColumn('devices', 'type')) {
            $builder->where(function ($where) {
                $where->where('devices.type', 'network')
                    ->orWhereRaw('COALESCE(port_stats.port_count, 0) >= 8');
            });
        }

        if ($this->hasColumn('devices', 'hostname')) {
            $builder->orderBy('devices.hostname');
        } else {
            $builder->orderBy('devices.device_id');
        }

        return $builder->limit(500)->get()->map(function ($row) use ($selectedDeviceId) {
            $deviceId = !empty($row->device_id) ? (int) $row->device_id : null;
            $hostname = $this->cleanText($row->hostname ?: $row->display ?: $row->sysName ?: ('device ' . $deviceId));
            $displayName = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, $row->ip ?? null, $deviceId);

            return [
                'device_id' => $deviceId,
                'display_name' => $displayName,
                'hardware' => $this->cleanText($row->hardware ?? ''),
                'hostname' => $hostname,
                'ip' => $this->cleanText($row->ip ?? ''),
                'is_selected' => $selectedDeviceId !== null && $deviceId === $selectedDeviceId,
                'last_polled' => $this->cleanText($row->last_polled ?? ''),
                'location' => $this->cleanText($row->location ?? ''),
                'os' => $this->cleanText($row->os ?? ''),
                'port_count' => (int) ($row->port_count ?? 0),
                'ports_down' => (int) ($row->ports_down ?? 0),
                'ports_up' => (int) ($row->ports_up ?? 0),
                'status' => $row->status ?? '',
                'type' => $this->cleanText($row->device_type ?? ''),
                'url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
            ];
        })->sortBy(function (array $switch) {
            return strtolower($switch['display_name'] ?? $switch['hostname'] ?? '');
        })->values()->all();
    }

    private function lookupDeviceById(int $deviceId): ?array
    {
        if (!$this->tableExists('devices')) {
            return null;
        }

        $row = DB::table('devices')
            ->select($this->selectList([
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'display', 'display'],
                ['devices', 'ip', 'ip'],
                ['devices', 'hardware', 'hardware'],
                ['devices', 'os', 'os'],
                ['devices', 'version', 'version'],
                ['devices', 'location', 'location'],
                ['devices', 'status', 'status'],
                ['devices', 'uptime', 'uptime'],
                ['devices', 'last_polled', 'last_polled'],
                ['devices', 'type', 'device_type'],
            ]))
            ->where('devices.device_id', $deviceId)
            ->first();

        if (!$row) {
            return null;
        }

        $hostname = $this->cleanText($row->hostname ?: $row->display ?: $row->sysName ?: ('device ' . $deviceId));
        $displayName = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, $row->ip ?? null, $deviceId);

        return [
            'device_id' => $deviceId,
            'display_name' => $displayName,
            'hardware' => $this->cleanText($row->hardware ?? ''),
            'hostname' => $hostname,
            'ip' => $this->cleanText($row->ip ?? ''),
            'last_polled' => $this->cleanText($row->last_polled ?? ''),
            'location' => $this->cleanText($row->location ?? ''),
            'os' => $this->cleanText($row->os ?? ''),
            'status' => $row->status ?? '',
            'type' => $this->cleanText($row->device_type ?? ''),
            'uptime' => $row->uptime ?: '',
            'url' => url('/device/device=' . $deviceId . '/'),
            'version' => $this->cleanText($row->version ?? ''),
        ];
    }

    private function lookupSwitchDetails(int $deviceId, array $range): array
    {
        $details = $this->emptySwitchDetails();

        try {
            $details['ports'] = $this->attachPortMacsToPorts($deviceId, $this->lookupPortsForDevice($deviceId));
            $details['stats'] = $this->switchStats($details['ports']);
            $details['port_changes'] = $this->filterChangedPorts($details['ports'], $range);
        } catch (
            \Throwable $exception
        ) {
            $details['notes'][] = 'Port lookup: ' . $exception->getMessage();
        }

        try {
            $details['events'] = $this->lookupEventsForDevice($deviceId);
        } catch (\Throwable $exception) {
            $details['notes'][] = 'Event lookup: ' . $exception->getMessage();
        }

        return $details;
    }

    private function lookupPortsForDevice(int $deviceId): array
    {
        if (!$this->tableExists('ports')) {
            return [];
        }

        $rows = DB::table('ports')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
            ->select($this->selectList([
                ['ports', 'port_id', 'port_id'],
                ['ports', 'device_id', 'port_device_id'],
                ['ports', 'ifName', 'ifName'],
                ['ports', 'ifDescr', 'ifDescr'],
                ['ports', 'ifAlias', 'ifAlias'],
                ['ports', 'ifAdminStatus', 'admin_status'],
                ['ports', 'ifOperStatus', 'oper_status'],
                ['ports', 'ifLastChange', 'ifLastChange'],
                ['ports', 'ifSpeed', 'ifSpeed'],
                ['ports', 'ifVlan', 'ifVlan'],
                ['ports', 'poll_time', 'poll_time'],
                ['ports', 'poll_prev', 'poll_prev'],
                ['ports', 'ifConnectorPresent', 'connector_present'],
                ['ports', 'ifType', 'ifType'],
                ['ports', 'ifMtu', 'ifMtu'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'display', 'display'],
                ['devices', 'uptime', 'device_uptime'],
                ['devices', 'last_polled', 'device_last_polled'],
            ]))
            ->where('ports.device_id', $deviceId);

        if ($this->hasColumn('ports', 'ifName')) {
            $rows->orderBy('ports.ifName');
        } else {
            $rows->orderBy('ports.port_id');
        }

        return $rows->limit(5000)->get()->map(function ($row) {
            return $this->portRowToArray($row);
        })->all();
    }

    private function portRowToArray($row): array
    {
        $deviceId = $this->firstInt([$row->device_id ?? null, $row->port_device_id ?? null]);
        $portId = !empty($row->port_id) ? (int) $row->port_id : null;
        $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);
        $lastChange = $this->portLastChangeInfo($row->ifLastChange ?? null, $row->poll_time ?? null, $row->device_uptime ?? null, $row->device_last_polled ?? null);

        return [
            'admin_status' => $row->admin_status ?: '',
            'connector_present' => $row->connector_present ?: '',
            'device_id' => $deviceId,
            'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
            'hostname' => $hostname,
            'ifAlias' => $this->cleanText($row->ifAlias ?? ''),
            'ifDescr' => $this->cleanText($row->ifDescr ?? ''),
            'port_label' => $this->portLabel($row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
            'ifLastChange' => $row->ifLastChange ?: '',
            'ifMtu' => $row->ifMtu ?: '',
            'ifName' => $this->cleanText($row->ifName ?? ''),
            'ifSpeed' => $row->ifSpeed ?: '',
            'ifSpeed_label' => $this->formatSpeed($row->ifSpeed ?? null),
            'ifVlan' => $row->ifVlan ?: '',
            'vlan_label' => $this->portVlanLabel($row->ifVlan ?? null, $row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
            'ifType' => $this->cleanText($row->ifType ?? ''),
            'last_change' => $lastChange,
            'mac_count' => 0,
            'macs' => '',
            'oper_status' => $row->oper_status ?: '',
            'poll_prev' => $row->poll_prev ?: '',
            'poll_time' => $row->poll_time ?: '',
            'port_id' => $portId,
            'port_url' => ($deviceId && $portId) ? url('/device/device=' . $deviceId . '/tab=port/port=' . $portId . '/') : null,
        ];
    }


    private function attachPortMacsToPorts(int $deviceId, array $ports): array
    {
        foreach ($ports as &$port) {
            $port['macs'] = '';
            $port['mac_count'] = 0;
        }
        unset($port);

        if (!$ports || !$this->tableExists('ports_fdb') || !$this->hasColumn('ports_fdb', 'port_id') || !$this->hasColumn('ports_fdb', 'mac_address')) {
            return $ports;
        }

        $portIds = [];
        foreach ($ports as $port) {
            if (!empty($port['port_id'])) {
                $portIds[(int) $port['port_id']] = true;
            }
        }

        if (!$portIds) {
            return $ports;
        }

        $builder = DB::table('ports_fdb')
            ->select('port_id', 'mac_address')
            ->whereIn('port_id', array_keys($portIds));

        if ($this->hasColumn('ports_fdb', 'device_id')) {
            $builder->where('device_id', $deviceId);
        }

        if ($this->hasColumn('ports_fdb', 'updated_at')) {
            $builder->orderByDesc('updated_at');
        }

        $macMap = [];
        foreach ($builder->limit(5000)->get() as $row) {
            $portId = !empty($row->port_id) ? (int) $row->port_id : null;
            $mac = $this->formatMac($row->mac_address ?? '');
            if ($portId === null || $mac === '') {
                continue;
            }
            $macMap[$portId][$mac] = true;
        }

        foreach ($ports as &$port) {
            $portId = !empty($port['port_id']) ? (int) $port['port_id'] : null;
            $macs = $portId !== null && isset($macMap[$portId]) ? array_keys($macMap[$portId]) : [];
            $port['mac_count'] = count($macs);
            $shown = array_slice($macs, 0, 6);
            $port['macs'] = implode(', ', $shown);
            if (count($macs) > count($shown)) {
                $port['macs'] .= ' +' . (count($macs) - count($shown));
            }
        }
        unset($port);

        return $ports;
    }

    private function switchStats(array $ports): array
    {
        $stats = [
            'admin_down' => 0,
            'down' => 0,
            'last_change' => null,
            'last_change_age' => '',
            'last_change_date' => '',
            'total' => count($ports),
            'up' => 0,
        ];

        foreach ($ports as $port) {
            if (($port['oper_status'] ?? '') === 'up') {
                $stats['up']++;
            }
            if (($port['oper_status'] ?? '') === 'down') {
                $stats['down']++;
            }
            if (($port['admin_status'] ?? '') === 'down') {
                $stats['admin_down']++;
            }

            $timestamp = $port['last_change']['timestamp'] ?? null;
            if ($timestamp !== null && ($stats['last_change'] === null || $timestamp > $stats['last_change'])) {
                $stats['last_change'] = $timestamp;
                $stats['last_change_age'] = $port['last_change']['age'] ?? '';
                $stats['last_change_date'] = $port['last_change']['date'] ?? '';
            }
        }

        return $stats;
    }

    private function filterChangedPorts(array $ports, array $range): array
    {
        $cutoff = $range['seconds'] === null ? null : time() - (int) $range['seconds'];
        $matches = [];

        foreach ($ports as $port) {
            $timestamp = $port['last_change']['timestamp'] ?? null;
            if ($cutoff !== null && ($timestamp === null || $timestamp < $cutoff)) {
                continue;
            }
            if ($timestamp === null && $cutoff !== null) {
                continue;
            }
            $matches[] = $port;
        }

        usort($matches, function (array $a, array $b) {
            return (int) ($b['last_change']['timestamp'] ?? 0) <=> (int) ($a['last_change']['timestamp'] ?? 0);
        });

        return array_slice($matches, 0, 500);
    }

    private function lookupFdbForDevice(int $deviceId): array
    {
        if (!$this->tableExists('ports_fdb')) {
            return [];
        }

        $builder = DB::table('ports_fdb')
            ->leftJoin('ports', 'ports.port_id', '=', 'ports_fdb.port_id')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports_fdb.device_id')
            ->select($this->selectList([
                ['ports_fdb', 'mac_address', 'mac'],
                ['ports_fdb', 'vlan_id', 'fdb_vlan_id'],
                ['ports_fdb', 'created_at', 'first_seen'],
                ['ports_fdb', 'updated_at', 'last_seen'],
                ['ports_fdb', 'port_id', 'fdb_port_id'],
                ['ports_fdb', 'device_id', 'fdb_device_id'],
                ['ports', 'port_id', 'port_id'],
                ['ports', 'ifName', 'ifName'],
                ['ports', 'ifDescr', 'ifDescr'],
                ['ports', 'ifAlias', 'ifAlias'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
            ]))
            ->where('ports_fdb.device_id', $deviceId)
            ->orderByDesc('ports_fdb.updated_at')
            ->limit(500);

        if ($this->tableExists('vlans')) {
            $builder->leftJoin('vlans', function ($join) {
                $join->on('vlans.vlan_id', '=', 'ports_fdb.vlan_id')
                    ->on('vlans.device_id', '=', 'ports_fdb.device_id');
            });
            $builder->addSelect($this->selectList([
                ['vlans', 'vlan_vlan', 'vlan'],
                ['vlans', 'vlan_name', 'vlan_name'],
            ]));
        } else {
            $builder->addSelect(DB::raw('NULL as vlan'), DB::raw('NULL as vlan_name'));
        }

        return $builder->get()->map(function ($row) {
            $deviceId = $this->firstInt([$row->device_id ?? null, $row->fdb_device_id ?? null]);
            $portId = $this->firstInt([$row->port_id ?? null, $row->fdb_port_id ?? null]);
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);

            return [
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'first_seen' => $row->first_seen ?: '',
                'hostname' => $hostname,
                'ifAlias' => $this->cleanText($row->ifAlias ?? ''),
                'ifDescr' => $this->cleanText($row->ifDescr ?? ''),
                'port_label' => $this->portLabel($row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'ifName' => $row->ifName ?: '',
                'last_seen' => $row->last_seen ?: '',
                'mac' => $row->mac ?: '',
                'port_id' => $portId,
                'port_url' => ($deviceId && $portId) ? url('/device/device=' . $deviceId . '/tab=port/port=' . $portId . '/') : null,
                'vlan' => $row->vlan ?: $row->fdb_vlan_id ?: '',
                'vlan_name' => $row->vlan_name ?: '',
            ];
        })->all();
    }

    private function lookupEventsForDevice(int $deviceId): array
    {
        if (!$this->tableExists('eventlog')) {
            return [];
        }

        $builder = DB::table('eventlog')
            ->leftJoin('devices', 'devices.device_id', '=', 'eventlog.device_id')
            ->select($this->selectList([
                ['eventlog', 'event_id', 'event_id'],
                ['eventlog', 'device_id', 'event_device_id'],
                ['eventlog', 'datetime', 'datetime'],
                ['eventlog', 'type', 'type'],
                ['eventlog', 'message', 'message'],
                ['eventlog', 'reference', 'reference'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
            ]))
            ->where('eventlog.device_id', $deviceId);

        if ($this->hasColumn('eventlog', 'datetime')) {
            $builder->orderByDesc('eventlog.datetime');
        } elseif ($this->hasColumn('eventlog', 'event_id')) {
            $builder->orderByDesc('eventlog.event_id');
        }

        return $builder->limit(100)->get()->map(function ($row) {
            $deviceId = $this->firstInt([$row->device_id ?? null, $row->event_device_id ?? null]);
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);

            return [
                'datetime' => $row->datetime ?: '',
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'hostname' => $hostname,
                'message' => $row->message ?: '',
                'reference' => $row->reference ?: '',
                'type' => $row->type ?: '',
            ];
        })->all();
    }

    private function lookupArpMatches(string $query, string $type): array
    {
        if (!$this->tableExists('ipv4_mac')) {
            return [];
        }

        $macs = $type === 'mac' ? $this->macLookupVariants($query) : [];
        $isIp = $type === 'ip';

        if (!$isIp && !$macs) {
            return [];
        }

        $builder = DB::table('ipv4_mac')
            ->leftJoin('ports', 'ports.port_id', '=', 'ipv4_mac.port_id')
            ->leftJoin('devices', 'devices.device_id', '=', 'ipv4_mac.device_id')
            ->select($this->selectList([
                ['ipv4_mac', 'ipv4_address', 'ip'],
                ['ipv4_mac', 'mac_address', 'mac'],
                ['ipv4_mac', 'context_name', 'context'],
                ['ipv4_mac', 'port_id', 'arp_port_id'],
                ['ipv4_mac', 'device_id', 'arp_device_id'],
                ['ports', 'port_id', 'port_id'],
                ['ports', 'ifName', 'ifName'],
                ['ports', 'ifDescr', 'ifDescr'],
                ['ports', 'ifAlias', 'ifAlias'],
                ['ports', 'ifAdminStatus', 'admin_status'],
                ['ports', 'ifOperStatus', 'oper_status'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'uptime', 'device_uptime'],
                ['devices', 'last_polled', 'device_last_polled'],
            ]))
            ->where(function ($where) use ($query, $isIp, $macs) {
                if ($isIp) {
                    $where->orWhere('ipv4_mac.ipv4_address', $query);
                }

                if ($macs) {
                    $where->orWhereIn('ipv4_mac.mac_address', $macs);
                }
            })
            ->limit(100);

        return $builder->get()->map(function ($row) {
            $deviceId = $this->firstInt([$row->device_id ?? null, $row->arp_device_id ?? null]);
            $portId = $this->firstInt([$row->port_id ?? null, $row->arp_port_id ?? null]);
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);
            $lastChange = $this->portLastChangeInfo($row->ifLastChange ?? null, $row->poll_time ?? null, $row->device_uptime ?? null, $row->device_last_polled ?? null);

            return [
                'admin_status' => $row->admin_status ?: '',
                'context' => $row->context ?: '',
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'hostname' => $hostname,
                'ifAlias' => $this->cleanText($row->ifAlias ?? ''),
                'ifDescr' => $this->cleanText($row->ifDescr ?? ''),
                'port_label' => $this->portLabel($row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'ifName' => $row->ifName ?: '',
                'ip' => $row->ip ?: '',
                'mac' => $row->mac ?: '',
                'oper_status' => $row->oper_status ?: '',
                'port_id' => $portId,
                'port_url' => ($deviceId && $portId) ? url('/device/device=' . $deviceId . '/tab=port/port=' . $portId . '/') : null,
            ];
        })->all();
    }

    private function lookupPortLastSeen(string $query, string $type, array $arpMatches): array
    {
        if (!$this->tableExists('ports_fdb')) {
            return [];
        }

        $macs = [];

        if ($type === 'mac') {
            $macs = array_merge($macs, $this->macLookupVariants($query));
        }

        foreach ($arpMatches as $match) {
            if (!empty($match['mac'])) {
                $macs = array_merge($macs, $this->macLookupVariants((string) $match['mac']));
            }
        }

        $macs = array_values(array_unique(array_filter($macs)));
        if (!$macs) {
            return [];
        }

        $builder = DB::table('ports_fdb')
            ->leftJoin('ports', 'ports.port_id', '=', 'ports_fdb.port_id')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports_fdb.device_id')
            ->select($this->selectList([
                ['ports_fdb', 'mac_address', 'mac'],
                ['ports_fdb', 'vlan_id', 'fdb_vlan_id'],
                ['ports_fdb', 'created_at', 'first_seen'],
                ['ports_fdb', 'updated_at', 'last_seen'],
                ['ports_fdb', 'port_id', 'fdb_port_id'],
                ['ports_fdb', 'device_id', 'fdb_device_id'],
                ['ports', 'port_id', 'port_id'],
                ['ports', 'ifName', 'ifName'],
                ['ports', 'ifDescr', 'ifDescr'],
                ['ports', 'ifAlias', 'ifAlias'],
                ['ports', 'ifAdminStatus', 'admin_status'],
                ['ports', 'ifOperStatus', 'oper_status'],
                ['ports', 'ifLastChange', 'ifLastChange'],
                ['ports', 'poll_time', 'poll_time'],
                ['ports', 'poll_prev', 'poll_prev'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'uptime', 'device_uptime'],
                ['devices', 'last_polled', 'device_last_polled'],
            ]))
            ->whereIn('ports_fdb.mac_address', $macs)
            ->orderByDesc('ports_fdb.updated_at')
            ->limit(100);

        if ($this->tableExists('vlans')) {
            $builder->leftJoin('vlans', function ($join) {
                $join->on('vlans.vlan_id', '=', 'ports_fdb.vlan_id')
                    ->on('vlans.device_id', '=', 'ports_fdb.device_id');
            });
            $builder->addSelect($this->selectList([
                ['vlans', 'vlan_vlan', 'vlan'],
                ['vlans', 'vlan_name', 'vlan_name'],
            ]));
        } else {
            $builder->addSelect(DB::raw('NULL as vlan'), DB::raw('NULL as vlan_name'));
        }

        return $builder->get()->map(function ($row) {
            $deviceId = $this->firstInt([$row->device_id ?? null, $row->fdb_device_id ?? null]);
            $portId = $this->firstInt([$row->port_id ?? null, $row->fdb_port_id ?? null]);
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);
            $lastChange = $this->portLastChangeInfo($row->ifLastChange ?? null, $row->poll_time ?? null, $row->device_uptime ?? null, $row->device_last_polled ?? null);

            return [
                'admin_status' => $row->admin_status ?: '',
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'first_seen' => $row->first_seen ?: '',
                'hostname' => $hostname,
                'ifAlias' => $this->cleanText($row->ifAlias ?? ''),
                'ifDescr' => $this->cleanText($row->ifDescr ?? ''),
                'port_label' => $this->portLabel($row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'ifLastChange' => $row->ifLastChange ?: '',
                'last_change' => $lastChange,
                'ifName' => $row->ifName ?: '',
                'last_seen' => $row->last_seen ?: '',
                'mac' => $row->mac ?: '',
                'oper_status' => $row->oper_status ?: '',
                'poll_prev' => $row->poll_prev ?: '',
                'poll_time' => $row->poll_time ?: '',
                'port_id' => $portId,
                'port_url' => ($deviceId && $portId) ? url('/device/device=' . $deviceId . '/tab=port/port=' . $portId . '/') : null,
                'vlan' => $row->vlan ?: ($row->fdb_vlan_id ?: ''),
                'vlan_name' => $row->vlan_name ?: '',
            ];
        })->all();
    }

    private function lookupInterfaceMatches(string $query, string $type): array
    {
        if (!$this->tableExists('ports')) {
            return [];
        }

        $like = '%' . $query . '%';

        $builder = DB::table('ports')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
            ->select($this->selectList([
                ['ports', 'port_id', 'port_id'],
                ['ports', 'device_id', 'port_device_id'],
                ['ports', 'ifName', 'ifName'],
                ['ports', 'ifDescr', 'ifDescr'],
                ['ports', 'ifAlias', 'ifAlias'],
                ['ports', 'ifAdminStatus', 'admin_status'],
                ['ports', 'ifOperStatus', 'oper_status'],
                ['ports', 'ifLastChange', 'ifLastChange'],
                ['ports', 'ifSpeed', 'ifSpeed'],
                ['ports', 'ifVlan', 'ifVlan'],
                ['ports', 'poll_time', 'poll_time'],
                ['ports', 'poll_prev', 'poll_prev'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'uptime', 'device_uptime'],
                ['devices', 'last_polled', 'device_last_polled'],
            ]))
            ->where(function ($where) use ($like) {
                $this->orWhereLikeIfColumn($where, 'ports', 'ifName', $like);
                $this->orWhereLikeIfColumn($where, 'ports', 'ifDescr', $like);
                $this->orWhereLikeIfColumn($where, 'ports', 'ifAlias', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'hostname', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'sysName', $like);
            })
            ->limit(100);

        if ($type === 'ip' && $this->tableExists('ipv4_addresses')) {
            $builder->orWhereExists(function ($exists) use ($query) {
                $exists->select(DB::raw(1))
                    ->from('ipv4_addresses')
                    ->whereColumn('ipv4_addresses.port_id', 'ports.port_id')
                    ->where('ipv4_addresses.ipv4_address', $query);
            });
        }

        return $builder->get()->map(function ($row) {
            $deviceId = $this->firstInt([$row->device_id ?? null, $row->port_device_id ?? null]);
            $portId = !empty($row->port_id) ? (int) $row->port_id : null;
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);
            $lastChange = $this->portLastChangeInfo($row->ifLastChange ?? null, $row->poll_time ?? null, $row->device_uptime ?? null, $row->device_last_polled ?? null);

            return [
                'admin_status' => $row->admin_status ?: '',
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'hostname' => $hostname,
                'ifAlias' => $this->cleanText($row->ifAlias ?? ''),
                'ifDescr' => $this->cleanText($row->ifDescr ?? ''),
                'port_label' => $this->portLabel($row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'ifLastChange' => $row->ifLastChange ?: '',
                'last_change' => $lastChange,
                'ifName' => $row->ifName ?: '',
                'ifSpeed' => $row->ifSpeed ?: '',
                'ifSpeed_label' => $this->formatSpeed($row->ifSpeed ?? null),
                'ifVlan' => $row->ifVlan ?: '',
                'vlan_label' => $this->portVlanLabel($row->ifVlan ?? null, $row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'oper_status' => $row->oper_status ?: '',
                'poll_prev' => $row->poll_prev ?: '',
                'poll_time' => $row->poll_time ?: '',
                'port_id' => $portId,
                'port_url' => ($deviceId && $portId) ? url('/device/device=' . $deviceId . '/tab=port/port=' . $portId . '/') : null,
            ];
        })->all();
    }

    private function lookupSwitchPortChanges(string $query, array $range): array
    {
        if (!$this->tableExists('devices') || !$this->tableExists('ports')) {
            return [];
        }

        $like = '%' . $query . '%';

        $devices = DB::table('devices')
            ->select($this->selectList([
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'display', 'display'],
                ['devices', 'ip', 'ip'],
                ['devices', 'location', 'location'],
                ['devices', 'uptime', 'device_uptime'],
                ['devices', 'last_polled', 'device_last_polled'],
            ]))
            ->where(function ($where) use ($like) {
                $this->orWhereLikeIfColumn($where, 'devices', 'hostname', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'sysName', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'display', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'ip', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'location', $like);
            })
            ->limit(20)
            ->get();

        $deviceIds = [];
        foreach ($devices as $device) {
            if (!empty($device->device_id)) {
                $deviceIds[] = (int) $device->device_id;
            }
        }

        $deviceIds = array_values(array_unique($deviceIds));
        if (!$deviceIds) {
            return [];
        }

        $rows = DB::table('ports')
            ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
            ->select($this->selectList([
                ['ports', 'port_id', 'port_id'],
                ['ports', 'device_id', 'port_device_id'],
                ['ports', 'ifName', 'ifName'],
                ['ports', 'ifDescr', 'ifDescr'],
                ['ports', 'ifAlias', 'ifAlias'],
                ['ports', 'ifAdminStatus', 'admin_status'],
                ['ports', 'ifOperStatus', 'oper_status'],
                ['ports', 'ifLastChange', 'ifLastChange'],
                ['ports', 'ifSpeed', 'ifSpeed'],
                ['ports', 'ifVlan', 'ifVlan'],
                ['ports', 'poll_time', 'poll_time'],
                ['ports', 'poll_prev', 'poll_prev'],
                ['ports', 'ifConnectorPresent', 'connector_present'],
                ['ports', 'ifType', 'ifType'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'display', 'display'],
                ['devices', 'uptime', 'device_uptime'],
                ['devices', 'last_polled', 'device_last_polled'],
            ]))
            ->whereIn('ports.device_id', $deviceIds)
            ->limit(2000)
            ->get();

        $cutoff = $range['seconds'] === null ? null : time() - (int) $range['seconds'];
        $matches = [];

        foreach ($rows as $row) {
            $lastChange = $this->portLastChangeInfo($row->ifLastChange ?? null, $row->poll_time ?? null, $row->device_uptime ?? null, $row->device_last_polled ?? null);
            $timestamp = $lastChange['timestamp'] ?? null;

            if ($cutoff !== null && ($timestamp === null || $timestamp < $cutoff)) {
                continue;
            }

            $deviceId = $this->firstInt([$row->device_id ?? null, $row->port_device_id ?? null]);
            $portId = !empty($row->port_id) ? (int) $row->port_id : null;
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);

            $matches[] = [
                'admin_status' => $row->admin_status ?: '',
                'connector_present' => $row->connector_present ?: '',
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'hostname' => $hostname,
                'ifAlias' => $this->cleanText($row->ifAlias ?? ''),
                'ifDescr' => $this->cleanText($row->ifDescr ?? ''),
                'port_label' => $this->portLabel($row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'ifLastChange' => $row->ifLastChange ?: '',
                'ifName' => $row->ifName ?: '',
                'ifSpeed' => $row->ifSpeed ?: '',
                'ifSpeed_label' => $this->formatSpeed($row->ifSpeed ?? null),
                'ifVlan' => $row->ifVlan ?: '',
                'vlan_label' => $this->portVlanLabel($row->ifVlan ?? null, $row->ifName ?? '', $row->ifAlias ?? '', $row->ifDescr ?? ''),
                'ifType' => $row->ifType ?: '',
                'last_change' => $lastChange,
                'oper_status' => $row->oper_status ?: '',
                'poll_prev' => $row->poll_prev ?: '',
                'poll_time' => $row->poll_time ?: '',
                'port_id' => $portId,
                'port_url' => ($deviceId && $portId) ? url('/device/device=' . $deviceId . '/tab=port/port=' . $portId . '/') : null,
            ];
        }

        usort($matches, function (array $a, array $b) {
            return (int) ($b['last_change']['timestamp'] ?? 0) <=> (int) ($a['last_change']['timestamp'] ?? 0);
        });

        return array_slice($matches, 0, 300);
    }

    private function lookupDeviceMatches(string $query): array
    {
        if (!$this->tableExists('devices')) {
            return [];
        }

        $like = '%' . $query . '%';

        return DB::table('devices')
            ->select($this->selectList([
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
                ['devices', 'display', 'display'],
                ['devices', 'ip', 'ip'],
                ['devices', 'hardware', 'hardware'],
                ['devices', 'os', 'os'],
                ['devices', 'status', 'status'],
                ['devices', 'last_polled', 'last_polled'],
                ['devices', 'location', 'location'],
            ]))
            ->where(function ($where) use ($like) {
                $this->orWhereLikeIfColumn($where, 'devices', 'hostname', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'sysName', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'display', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'ip', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'hardware', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'location', $like);
            })
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $deviceId = !empty($row->device_id) ? (int) $row->device_id : null;

                return [
                    'device_id' => $deviceId,
                    'hardware' => $row->hardware ?: '',
                    'hostname' => $row->hostname ?: $row->sysName ?: $row->display ?: '',
                    'ip' => $row->ip ?: '',
                    'last_polled' => $row->last_polled ?: '',
                    'location' => $row->location ?: '',
                    'os' => $row->os ?: '',
                    'status' => $row->status ?? '',
                    'url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                ];
            })
            ->all();
    }

    private function lookupVlanMatches(string $query): array
    {
        if (!$this->tableExists('vlans')) {
            return [];
        }

        $like = '%' . $query . '%';
        $numericVlan = is_numeric($query) ? (int) $query : null;

        return DB::table('vlans')
            ->leftJoin('devices', 'devices.device_id', '=', 'vlans.device_id')
            ->select($this->selectList([
                ['vlans', 'vlan_id', 'vlan_id'],
                ['vlans', 'vlan_vlan', 'vlan'],
                ['vlans', 'vlan_name', 'name'],
                ['vlans', 'device_id', 'vlan_device_id'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
            ]))
            ->where(function ($where) use ($like, $numericVlan) {
                $this->orWhereLikeIfColumn($where, 'vlans', 'vlan_name', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'hostname', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'sysName', $like);

                if ($numericVlan !== null && $this->hasColumn('vlans', 'vlan_vlan')) {
                    $where->orWhere('vlans.vlan_vlan', $numericVlan);
                }
            })
            ->limit(100)
            ->get()
            ->map(function ($row) {
                $deviceId = $this->firstInt([$row->device_id ?? null, $row->vlan_device_id ?? null]);
                $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);

                return [
                    'device_id' => $deviceId,
                    'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                    'hostname' => $hostname,
                    'name' => $row->name ?: '',
                    'vlan' => $row->vlan ?: '',
                ];
            })
            ->all();
    }

    private function lookupEventMatches(string $query): array
    {
        if (!$this->tableExists('eventlog')) {
            return [];
        }

        $like = '%' . $query . '%';

        $builder = DB::table('eventlog')
            ->leftJoin('devices', 'devices.device_id', '=', 'eventlog.device_id')
            ->select($this->selectList([
                ['eventlog', 'event_id', 'event_id'],
                ['eventlog', 'device_id', 'event_device_id'],
                ['eventlog', 'datetime', 'datetime'],
                ['eventlog', 'type', 'type'],
                ['eventlog', 'message', 'message'],
                ['eventlog', 'reference', 'reference'],
                ['devices', 'device_id', 'device_id'],
                ['devices', 'hostname', 'hostname'],
                ['devices', 'sysName', 'sysName'],
            ]))
            ->where(function ($where) use ($like) {
                $this->orWhereLikeIfColumn($where, 'eventlog', 'message', $like);
                $this->orWhereLikeIfColumn($where, 'eventlog', 'type', $like);
                $this->orWhereLikeIfColumn($where, 'eventlog', 'reference', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'hostname', $like);
                $this->orWhereLikeIfColumn($where, 'devices', 'sysName', $like);
            });

        if ($this->hasColumn('eventlog', 'datetime')) {
            $builder->orderByDesc('eventlog.datetime');
        } elseif ($this->hasColumn('eventlog', 'event_id')) {
            $builder->orderByDesc('eventlog.event_id');
        }

        return $builder->limit(50)->get()->map(function ($row) {
            $deviceId = $this->firstInt([$row->device_id ?? null, $row->event_device_id ?? null]);
            $hostname = $this->displayDeviceName($row->hostname ?? null, $row->sysName ?? null, $row->display ?? null, null, $deviceId ?? null);

            return [
                'datetime' => $row->datetime ?: '',
                'device_id' => $deviceId,
                'device_url' => $deviceId ? url('/device/device=' . $deviceId . '/') : null,
                'hostname' => $hostname,
                'message' => $row->message ?: '',
                'reference' => $row->reference ?: '',
                'type' => $row->type ?: '',
            ];
        })->all();
    }


    private function sectionOptions(): array
    {
        return [
            'summary' => 'Summary',
            'changes' => 'Changed ports',
            'ports' => 'Port inventory',
            'events' => 'Events',
            'all' => 'All data',
        ];
    }

    private function normalizeSection(string $section): string
    {
        $section = strtolower(trim($section));
        $options = $this->sectionOptions();

        return array_key_exists($section, $options) ? $section : 'ports';
    }

    private function rangeOptions(): array
    {
        return [
            '30h' => ['label' => '30 hours', 'seconds' => 30 * 60 * 60],
            '1d' => ['label' => '1 day', 'seconds' => 24 * 60 * 60],
            '7d' => ['label' => '7 days', 'seconds' => 7 * 24 * 60 * 60],
            '30d' => ['label' => '30 days', 'seconds' => 30 * 24 * 60 * 60],
            '90d' => ['label' => '90 days', 'seconds' => 90 * 24 * 60 * 60],
            'all' => ['label' => 'All known', 'seconds' => null],
        ];
    }

    private function normalizeRange(string $range): array
    {
        $options = $this->rangeOptions();
        $key = strtolower(trim($range));

        if (!array_key_exists($key, $options)) {
            $key = '30h';
        }

        return [
            'key' => $key,
            'label' => $options[$key]['label'],
            'seconds' => $options[$key]['seconds'],
        ];
    }

    private function portLastChangeInfo($ifLastChange, $pollTime = null, $deviceUptime = null, $deviceLastPolled = null): array
    {
        $raw = trim((string) ($ifLastChange ?? ''));
        $timestamp = null;
        $source = '';

        if ($raw !== '' && is_numeric($raw)) {
            $value = (float) $raw;
            $now = time();

            if ($value >= 946684800 && $value <= ($now + (2 * 365 * 24 * 60 * 60))) {
                $timestamp = (int) $value;
                $source = 'epoch';
            } else {
                $anchor = $this->timestampFromValue($deviceLastPolled) ?: $this->timestampFromValue($pollTime) ?: $now;
                $uptime = is_numeric($deviceUptime) ? (float) $deviceUptime : null;

                if ($uptime !== null && $uptime > 0) {
                    $secondsSinceBoot = $value / 100;
                    if ($secondsSinceBoot <= ($uptime + 86400)) {
                        $timestamp = (int) round($anchor - $uptime + $secondsSinceBoot);
                        $source = 'timeticks';
                    } elseif ($value <= ($uptime + 86400)) {
                        $timestamp = (int) round($anchor - $uptime + $value);
                        $source = 'uptime-seconds';
                    }
                }
            }
        }

        return [
            'age' => $timestamp ? $this->humanAge($timestamp) : '',
            'date' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : '',
            'raw' => $raw,
            'source' => $source,
            'timestamp' => $timestamp,
        ];
    }

    private function timestampFromValue($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;
            return $number > 0 ? $number : null;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp !== false ? $timestamp : null;
    }

    private function humanAge(int $timestamp): string
    {
        $seconds = max(0, time() - $timestamp);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h ago';
        }

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm ago';
        }

        return $minutes . 'm ago';
    }

    private function formatSpeed($speed): string
    {
        if (!is_numeric($speed) || (float) $speed <= 0) {
            return '';
        }

        $speed = (float) $speed;
        $units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
        $index = 0;

        while ($speed >= 1000 && $index < count($units) - 1) {
            $speed /= 1000;
            $index++;
        }

        return rtrim(rtrim(number_format($speed, 2), '0'), '.') . ' ' . $units[$index];
    }

    private function lookupType(string $query): string
    {
        if (filter_var($query, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ip';
        }

        if (strlen($this->normalizeMac($query)) === 12) {
            return 'mac';
        }

        if (is_numeric($query)) {
            return 'number';
        }

        return 'text';
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];

        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = DB::connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $exception) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $cache[$key] = $this->tableExists($table) && DB::connection()->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $exception) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    private function selectList(array $columns): array
    {
        $select = [];

        foreach ($columns as [$table, $column, $alias]) {
            if ($this->hasColumn($table, $column)) {
                $select[] = $table . '.' . $column . ' as ' . $alias;
            } else {
                $select[] = DB::raw('NULL as ' . $alias);
            }
        }

        return $select;
    }

    private function orWhereLikeIfColumn($where, string $table, string $column, string $like): void
    {
        if ($this->hasColumn($table, $column)) {
            $where->orWhere($table . '.' . $column, 'like', $like);
        }
    }

    private function normalizeMac(string $mac): string
    {
        return strtolower(preg_replace('/[^0-9a-f]/i', '', $mac));
    }

    private function macLookupVariants(string $mac): array
    {
        $compact = $this->normalizeMac($mac);

        if (strlen($compact) !== 12) {
            return [];
        }

        $octets = str_split($compact, 2);

        return array_values(array_unique([
            $compact,
            implode(':', $octets),
            implode('-', $octets),
            substr($compact, 0, 4) . '.' . substr($compact, 4, 4) . '.' . substr($compact, 8, 4),
        ]));
    }

    private function cleanText($value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?: '';
        $text = preg_replace('/\s+/', ' ', $text) ?: '';

        return trim($text);
    }

    private function isMeaningfulName(string $name): bool
    {
        $name = $this->cleanText($name);

        if ($name === '' || strlen($name) < 3) {
            return false;
        }

        if (filter_var($name, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!preg_match('/[A-Za-z]/', $name)) {
            return false;
        }

        if (preg_match('/^[a-f0-9]{1,6}$/i', $name)) {
            return false;
        }

        return true;
    }

    private function displayDeviceName($hostname = null, $sysName = null, $display = null, $ip = null, ?int $deviceId = null): string
    {
        foreach ([$display, $hostname, $sysName] as $candidate) {
            $name = $this->shortDeviceName($this->cleanText($candidate));
            if ($this->isMeaningfulName($name)) {
                return $name;
            }
        }

        foreach ([$ip, $hostname] as $candidate) {
            $addr = $this->cleanText($candidate);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_IP)) {
                $ptr = @gethostbyaddr($addr);
                $ptr = $this->cleanText($ptr);
                if ($ptr !== '' && $ptr !== $addr) {
                    $ptr = preg_replace('/\.middlebury\.edu$/i', '', rtrim($ptr, '.')) ?: $ptr;
                    if ($this->isMeaningfulName($ptr)) {
                        return $ptr;
                    }
                }
            }
        }

        $fallback = $this->shortDeviceName($this->cleanText($hostname ?: $ip ?: ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return $deviceId ? 'device ' . $deviceId : 'unknown device';
    }



    private function shortDeviceName(string $name): string
    {
        $name = rtrim($this->cleanText($name), '.');
        $name = preg_replace('/\.middlebury\.edu$/i', '', $name) ?: $name;
        return $name;
    }

    private function formatMac($mac): string
    {
        $compact = strtolower(preg_replace('/[^0-9a-f]/i', '', (string) ($mac ?? '')) ?: '');
        if (strlen($compact) === 12) {
            return implode(':', str_split($compact, 2));
        }

        return $this->cleanText($mac);
    }

    private function portVlanLabel($ifVlan, $ifName, $ifAlias, $ifDescr): string
    {
        $vlan = trim((string) ($ifVlan ?? ''));
        if ($vlan !== '' && is_numeric($vlan) && (int) $vlan > 0) {
            return (string) ((int) $vlan);
        }

        $text = implode(' ', array_filter([
            $this->cleanText($ifName),
            $this->cleanText($ifAlias),
            $this->cleanText($ifDescr),
        ]));

        if (preg_match('/\b(?:vlan|vl)[\s._-]*(\d{1,4})\b/i', $text, $match)) {
            return (string) ((int) $match[1]);
        }

        return '';
    }

    private function portLabel($ifName, $ifAlias, $ifDescr): string
    {
        $name = strtolower($this->cleanText($ifName));
        $alias = $this->cleanText($ifAlias);
        $descr = $this->cleanText($ifDescr);

        if ($alias !== '' && strtolower($alias) !== $name && strtolower($alias) !== strtolower($descr)) {
            return $alias;
        }

        if ($descr !== '' && strtolower($descr) !== $name) {
            return $descr;
        }

        return '';
    }

    private function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
