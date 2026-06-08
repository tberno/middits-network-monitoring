#!/usr/bin/env php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$options = getopt('', [
    'base-url:',
    'user:',
    'password:',
    'warning-free:',
    'critical-free:',
    'insecure',
    'device-id:',
    'device-hostname:',
    'state-file:',
    'dry-run',
]);

try {
    $root = dirname(__DIR__, 4);
    require_once $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $settings = loadPluginSettings();
    $baseUrl = rtrim((string) ($options['base-url'] ?? getenv('EIP_BASE_URL') ?: getenv('EIP_HOST') ?: ($settings['base_url'] ?? 'https://juno-eip.middlebury.edu')), '/');
    $username = (string) ($options['user'] ?? getenv('EIP_USER') ?: ($settings['username'] ?? ''));
    $password = (string) ($options['password'] ?? getenv('EIP_PASS') ?: ($settings['password'] ?? ''));
    $warning = (float) ($options['warning-free'] ?? getenv('EIP_WARNING_FREE') ?: ($settings['warning_free_percent'] ?? 20));
    $critical = (float) ($options['critical-free'] ?? getenv('EIP_CRITICAL_FREE') ?: ($settings['critical_free_percent'] ?? 10));
    $verifyTls = isset($options['insecure']) ? false : (bool) ($settings['verify_tls'] ?? true);
    $deviceId = resolveDeviceId($options);
    $stateFile = (string) ($options['state-file'] ?? getenv('SOLIDSERVER_COMPONENT_STATE') ?: '/var/lib/librenms/solidserver-components.json');
    $dryRun = isset($options['dry-run']);

    if ($username === '' || $password === '') {
        throw new RuntimeException('Solid Server credentials are not configured.');
    }

    $ranges = fetchRows($baseUrl, '/rest/dhcp_range_list', $username, $password, $verifyTls);
    $networks = aggregateNetworks($ranges, $warning, $critical);
    enrichWithLibreNms($networks);
    $result = syncComponents($deviceId, $networks, $dryRun);

    if (!$dryRun) {
        writeStateFile($stateFile, $deviceId, $networks, $result);
    }

    printf(
        "Synced %d Solid Server DHCP components on device_id %d (%d created, %d updated, %d critical, %d warning, %d ok)%s%s\n",
        $result['total'],
        $deviceId,
        $result['created'],
        $result['updated'],
        $result['critical'],
        $result['warning'],
        $result['ok'],
        $dryRun ? ' [dry-run]' : '',
        $dryRun ? '' : " and wrote {$stateFile}"
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'Solid Server component sync failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function loadPluginSettings(): array
{
    if (!Schema::hasTable('plugins') || !Schema::hasColumn('plugins', 'settings')) {
        return [];
    }

    foreach (['plugin_name', 'name', 'plugin'] as $column) {
        if (Schema::hasColumn('plugins', $column)) {
            $row = DB::table('plugins')->where($column, 'SolidServer')->first(['settings']);
            if ($row && $row->settings) {
                $settings = json_decode((string) $row->settings, true);
                return is_array($settings) ? $settings : [];
            }
        }
    }

    return [];
}

function resolveDeviceId(array $options): int
{
    if (!empty($options['device-id']) && is_numeric($options['device-id'])) {
        return (int) $options['device-id'];
    }

    $hostname = trim((string) ($options['device-hostname'] ?? getenv('LIBRENMS_DEVICE_HOSTNAME') ?: ''));
    if ($hostname === '') {
        throw new RuntimeException('Set --device-id or --device-hostname.');
    }

    $device = DB::table('devices')->where('hostname', $hostname)->orWhere('sysName', $hostname)->first(['device_id']);
    if (!$device) {
        throw new RuntimeException("LibreNMS device not found: {$hostname}");
    }

    return (int) $device->device_id;
}

function fetchRows(string $baseUrl, string $endpoint, string $username, string $password, bool $verifyTls): array
{
    $rows = [];
    $limit = 500;
    $offset = 0;

    do {
        $url = $baseUrl . $endpoint . '?' . http_build_query(['limit' => $limit, 'offset' => $offset]);
        $context = stream_context_create([
            'http' => [
                'header' => [
                    'Authorization: Basic ' . base64_encode($username . ':' . $password),
                    'Accept: application/json',
                ],
                'ignore_errors' => true,
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Unable to connect to Solid Server API.');
        }

        $status = $http_response_header[0] ?? '';
        if (!str_contains($status, '200') && !str_contains($status, '204')) {
            throw new RuntimeException("Solid Server API error: {$status}");
        }

        $page = trim($body) === '' ? [] : json_decode($body, true);
        if (!is_array($page)) {
            throw new RuntimeException('Solid Server returned invalid JSON.');
        }

        $count = 0;
        foreach ($page as $row) {
            if (is_array($row)) {
                $rows[] = $row;
                $count++;
            }
        }
        $offset += $limit;
    } while ($count >= $limit && count($rows) < 5000);

    return $rows;
}

function aggregateNetworks(array $ranges, float $warning, float $critical): array
{
    $networks = [];
    $seen = [];

    foreach ($ranges as $range) {
        $name = trim((string) ($range['dhcpsn_name'] ?? $range['shared_network'] ?? $range['dhcpscope_name'] ?? 'unknown'));
        $key = $name === '' ? 'unknown' : $name;
        $rangeKey = implode('|', [$key, $range['dhcprange_start_addr'] ?? '', $range['dhcprange_end_addr'] ?? '', $range['dhcprange_name'] ?? '']);
        $server = trim((string) ($range['vdhcp_parent_name'] ?? $range['dhcp_name'] ?? ''));
        $vlan = detectVlan($range);
        $cidr = rangeCidr($range);

        if (!isset($networks[$key])) {
            $networks[$key] = [
                'cidrs' => [],
                'dhcp_sources' => [],
                'free' => 0.0,
                'free_percent' => null,
                'interface_matches' => 0,
                'name' => $key,
                'range_count' => 0,
                'state' => 'unknown',
                'total' => 0.0,
                'used' => 0.0,
                'vlan_matches' => 0,
                'vlans' => [],
            ];
        }

        if ($server !== '') {
            $networks[$key]['dhcp_sources'][$server] = true;
        }
        if ($vlan !== null) {
            $networks[$key]['vlans'][$vlan] = true;
        }
        if ($cidr !== null) {
            $networks[$key]['cidrs'][$cidr] = true;
        }

        if (isset($seen[$rangeKey])) {
            continue;
        }
        $seen[$rangeKey] = true;

        $total = firstNumber($range, ['dhcpscope_size', 'dhcpscope_total', 'dhcprange_size', 'total', 'size']);
        $used = firstNumber($range, ['dhcpscope_used', 'dhcpscope_addr_used', 'dhcprange_used', 'dhcprange_lease_count', 'used', 'leases_used']);
        $free = firstNumber($range, ['dhcpscope_free', 'dhcpscope_addr_free', 'dhcprange_free', 'free', 'leases_free', 'available']);

        if ($total === null && $used !== null && $free !== null) {
            $total = $used + $free;
        }
        if ($free === null && $total !== null && $used !== null) {
            $free = $total - $used;
        }
        if ($used === null && $total !== null && $free !== null) {
            $used = $total - $free;
        }

        if ($total !== null && $free !== null) {
            $networks[$key]['total'] += $total;
            $networks[$key]['used'] += $used ?? ($total - $free);
            $networks[$key]['free'] += $free;
            $networks[$key]['range_count']++;
        }
    }

    foreach ($networks as &$network) {
        $network['cidrs'] = array_keys($network['cidrs']);
        $network['dhcp_sources'] = array_keys($network['dhcp_sources']);
        $network['vlans'] = array_keys($network['vlans']);
        if ($network['total'] > 0) {
            $network['free_percent'] = ($network['free'] / $network['total']) * 100;
            if ($network['free_percent'] <= $critical) {
                $network['state'] = 'critical';
            } elseif ($network['free_percent'] <= $warning) {
                $network['state'] = 'warning';
            } else {
                $network['state'] = 'ok';
            }
        }
    }
    unset($network);

    uasort($networks, fn (array $a, array $b): int => ($a['free_percent'] ?? 9999) <=> ($b['free_percent'] ?? 9999));
    return $networks;
}

function enrichWithLibreNms(array &$networks): void
{
    $cidrBounds = [];
    foreach ($networks as $key => $network) {
        foreach ($network['cidrs'] as $cidr) {
            $bounds = cidrBounds($cidr);
            if ($bounds) {
                $cidrBounds[$key][$cidr] = $bounds;
            }
        }
    }

    if (!$cidrBounds) {
        return;
    }

    $query = DB::table('ipv4_addresses')
        ->leftJoin('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
        ->select(['ipv4_addresses.ipv4_address', 'ports.ifName', 'ports.ifDescr', 'ports.ifAlias'])
        ->where(function ($query) use ($cidrBounds) {
            foreach ($cidrBounds as $networkBounds) {
                foreach ($networkBounds as $bounds) {
                    $query->orWhereRaw('INET_ATON(ipv4_addresses.ipv4_address) BETWEEN ? AND ?', [$bounds['start'], $bounds['end']]);
                }
            }
        })
        ->limit(5000);

    foreach ($query->get() as $row) {
        $ip = ipToUnsigned((string) $row->ipv4_address);
        if ($ip === null) {
            continue;
        }

        foreach ($cidrBounds as $networkKey => $networkBounds) {
            foreach ($networkBounds as $bounds) {
                if ($ip >= $bounds['start'] && $ip <= $bounds['end']) {
                    $networks[$networkKey]['interface_matches']++;
                    $vlan = inferVlanFromInterface($row->ifName, $row->ifDescr, $row->ifAlias);
                    if ($vlan !== null && !in_array($vlan, $networks[$networkKey]['vlans'], true)) {
                        $networks[$networkKey]['vlans'][] = $vlan;
                    }
                }
            }
        }
    }
}

function syncComponents(int $deviceId, array $networks, bool $dryRun): array
{
    $type = 'solidserver_dhcp';
    $existing = DB::table('component')
        ->leftJoin('component_prefs', function ($join) {
            $join->on('component_prefs.component', '=', 'component.id')->where('component_prefs.attribute', '=', 'solidserver_key');
        })
        ->where('component.device_id', $deviceId)
        ->where('component.type', $type)
        ->select(['component.id', 'component_prefs.value'])
        ->get();

    $byKey = [];
    foreach ($existing as $row) {
        if ($row->value) {
            $byKey[(string) $row->value] = (int) $row->id;
        }
    }

    $result = ['created' => 0, 'critical' => 0, 'ok' => 0, 'total' => count($networks), 'updated' => 0, 'warning' => 0];

    foreach ($networks as $key => $network) {
        $status = componentStatus($network);
        $state = $status === 2 ? 'critical' : ($status === 1 ? 'warning' : 'ok');
        $result[$state]++;
        $label = componentLabel($network);
        $error = componentError($network);

        if ($dryRun) {
            isset($byKey[$key]) ? $result['updated']++ : $result['created']++;
            continue;
        }

        if (isset($byKey[$key])) {
            $componentId = $byKey[$key];
            DB::table('component')->where('id', $componentId)->update([
                'disabled' => 0,
                'error' => $error,
                'ignore' => 0,
                'label' => $label,
                'status' => $status,
            ]);
            $result['updated']++;
        } else {
            $componentId = (int) DB::table('component')->insertGetId([
                'device_id' => $deviceId,
                'disabled' => 0,
                'error' => $error,
                'ignore' => 0,
                'label' => $label,
                'status' => $status,
                'type' => $type,
            ]);
            $result['created']++;
        }

        $prefs = [
            'cidrs' => implode(',', $network['cidrs']),
            'dhcp_sources' => implode(',', $network['dhcp_sources']),
            'free' => (string) round($network['free']),
            'free_percent' => $network['free_percent'] === null ? '' : sprintf('%.2f', $network['free_percent']),
            'interface_matches' => (string) $network['interface_matches'],
            'range_count' => (string) $network['range_count'],
            'solidserver_key' => (string) $key,
            'state' => $state,
            'summary' => componentSummary($network),
            'total' => (string) round($network['total']),
            'used' => (string) round($network['used']),
            'vlan_matches' => (string) $network['vlan_matches'],
            'vlans' => implode(',', $network['vlans']),
        ];

        foreach ($prefs as $attribute => $value) {
            DB::table('component_prefs')->updateOrInsert(['component' => $componentId, 'attribute' => $attribute], ['value' => $value]);
        }
    }

    return $result;
}

function writeStateFile(string $path, int $deviceId, array $networks, array $result): void
{
    $entries = [];
    foreach ($networks as $network) {
        $status = componentStatus($network);
        $entries[] = [
            'cidrs' => $network['cidrs'],
            'dhcp_sources' => $network['dhcp_sources'],
            'error' => componentError($network),
            'free' => round($network['free']),
            'free_percent' => $network['free_percent'] === null ? null : round($network['free_percent'], 2),
            'interface_matches' => $network['interface_matches'],
            'label' => componentLabel($network),
            'name' => $network['name'],
            'range_count' => $network['range_count'],
            'state' => $status === 2 ? 'critical' : ($status === 1 ? 'warning' : 'ok'),
            'status' => $status,
            'summary' => componentSummary($network),
            'total' => round($network['total']),
            'used' => round($network['used']),
            'vlan_matches' => $network['vlan_matches'],
            'vlans' => $network['vlans'],
        ];
    }

    usort($entries, fn (array $a, array $b): int => ($a['free_percent'] ?? 9999) <=> ($b['free_percent'] ?? 9999));
    $payload = ['device_id' => $deviceId, 'generated_at' => date('c'), 'result' => $result, 'solidserver_dhcp' => $entries];

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    chmod($tmp, 0644);
    rename($tmp, $path);
}

function componentStatus(array $network): int
{
    if (($network['state'] ?? '') === 'critical') {
        return 2;
    }
    if (($network['state'] ?? '') === 'warning') {
        return 1;
    }
    if (($network['cidrs'] ?? []) && ($network['interface_matches'] ?? 0) === 0) {
        return 1;
    }
    return 0;
}

function componentLabel(array $network): string
{
    $percent = $network['free_percent'] === null ? 'unknown free' : sprintf('%.2f%% free', $network['free_percent']);
    $cidrs = $network['cidrs'] ? implode(', ', $network['cidrs']) : 'no CIDR';
    return 'SolidServer DHCP ' . $network['name'] . ' (' . $percent . ', ' . $cidrs . ')';
}

function componentSummary(array $network): string
{
    return implode(' ', [
        'shared_network=' . $network['name'],
        'free_percent=' . ($network['free_percent'] === null ? 'unknown' : sprintf('%.2f', $network['free_percent'])),
        'used=' . (string) round($network['used']),
        'free=' . (string) round($network['free']),
        'total=' . (string) round($network['total']),
        'cidrs=' . ($network['cidrs'] ? implode(',', $network['cidrs']) : 'none'),
        'vlans=' . ($network['vlans'] ? implode(',', $network['vlans']) : 'unknown'),
        'dhcp_sources=' . ($network['dhcp_sources'] ? implode(',', $network['dhcp_sources']) : 'unknown'),
        'ranges=' . (string) $network['range_count'],
        'interface_matches=' . (string) $network['interface_matches'],
    ]);
}

function componentError(array $network): string
{
    $notes = [];
    if (($network['state'] ?? '') === 'critical') {
        $notes[] = 'Free capacity is at/below critical threshold.';
    } elseif (($network['state'] ?? '') === 'warning') {
        $notes[] = 'Free capacity is at/below warning threshold.';
    }
    if (($network['cidrs'] ?? []) && ($network['interface_matches'] ?? 0) === 0) {
        $notes[] = 'No LibreNMS interface IP found in EIP CIDR.';
    }
    return trim(componentSummary($network) . ' ' . implode(' ', $notes));
}

function detectVlan(array $row): ?string
{
    foreach (['vlan_id', 'vlan', 'vlanid', 'dhcp_vlan_id', 'dhcpscope_vlan_id'] as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (string) ((int) $row[$key]);
        }
    }
    $text = implode(' ', array_filter([$row['dhcpsn_name'] ?? null, $row['dhcpscope_name'] ?? null, $row['dhcprange_name'] ?? null]));
    return preg_match('/\bvlan[\s._-]*(\d{1,4})\b/i', $text, $match) ? (string) ((int) $match[1]) : null;
}

function inferVlanFromInterface(?string $ifName, ?string $ifDescr, ?string $ifAlias): ?string
{
    $text = implode(' ', array_filter([$ifName, $ifDescr, $ifAlias]));
    if (preg_match('/\b(?:vlan|vl)[\s._-]*(\d{1,4})\b/i', $text, $match)) {
        return (string) ((int) $match[1]);
    }
    return preg_match('/\.(\d{1,4})\b/', $text, $match) ? (string) ((int) $match[1]) : null;
}

function rangeCidr(array $row): ?string
{
    $netAddr = trim((string) ($row['dhcpscope_net_addr'] ?? ''));
    $prefix = trim((string) ($row['dhcpscope_prefix'] ?? ''));
    return $netAddr !== '' && is_numeric($prefix) ? $netAddr . '/' . (int) $prefix : null;
}

function cidrBounds(string $cidr): ?array
{
    if (!preg_match('/^([0-9.]+)\/(\d{1,2})$/', $cidr, $match)) {
        return null;
    }
    $base = ipToUnsigned($match[1]);
    $prefix = (int) $match[2];
    if ($base === null || $prefix < 0 || $prefix > 32) {
        return null;
    }
    $size = 2 ** (32 - $prefix);
    $start = floor($base / $size) * $size;
    return ['end' => $start + $size - 1, 'start' => $start];
}

function ipToUnsigned(string $ip): ?float
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (float) sprintf('%u', ip2long($ip)) : null;
}

function firstNumber(array $row, array $keys): ?float
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (float) $row[$key];
        }
    }
    return null;
}
