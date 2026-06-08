<?php

namespace App\Plugins\SolidServer;

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
        $baseUrl = rtrim($settings['base_url'] ?? getenv('EIP_BASE_URL') ?: 'https://juno-eip.middlebury.edu', '/');
        $username = $settings['username'] ?? getenv('EIP_USER') ?: '';
        $password = $settings['password'] ?? getenv('EIP_PASS') ?: '';
        $warning = (float) ($settings['warning_free_percent'] ?? 20);
        $critical = (float) ($settings['critical_free_percent'] ?? 10);
        $verifyTls = (bool) ($settings['verify_tls'] ?? true);

        $error = null;
        $lookup = null;
        $lookupQuery = trim((string) request()->query('lookup', ''));
        $sharedNetworks = [];
        $rawRangeCount = 0;

        if ($username === '' || $password === '') {
            $error = 'Solid Server credentials are not configured.';
        } else {
            try {
                $ranges = $this->fetchRows($baseUrl, '/rest/dhcp_range_list', $username, $password, $verifyTls);
                $rawRangeCount = count($ranges);
                $sharedNetworks = $this->aggregateSharedNetworks($ranges, $warning, $critical);
                $sharedNetworks = $this->enrichWithLibreNms($sharedNetworks);
                if ($lookupQuery !== '') {
                    $lookup = $this->lookupSolidServer($baseUrl, $username, $password, $verifyTls, $ranges, $lookupQuery);
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'base_url' => $baseUrl,
            'critical' => $critical,
            'error' => $error,
            'fetched_at' => date('Y-m-d H:i:s'),
            'lookup' => $lookup,
            'lookup_query' => $lookupQuery,
            'raw_range_count' => $rawRangeCount,
            'shared_networks' => $sharedNetworks,
            'summary' => $this->summary($sharedNetworks),
            'warning' => $warning,
        ];
    }

    private function fetchRows(string $baseUrl, string $endpoint, string $username, string $password, bool $verifyTls, ?string $where = null, int $maxRows = 5000): array
    {
        // Read-only by design: this plugin only performs GET/list requests.
        $rows = [];
        $limit = 500;
        $offset = 0;

        do {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
            ];
            if ($where !== null && $where !== '') {
                $params['WHERE'] = $where;
            }

            $url = $baseUrl . $endpoint . '?' . http_build_query($params);

            $page = $this->getJson($url, $username, $password, $verifyTls);
            if (!is_array($page)) {
                throw new \RuntimeException("Solid Server returned a non-list response from {$endpoint}");
            }

            $count = 0;
            foreach ($page as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                    $count++;
                }
            }

            $offset += $limit;
        } while ($count >= $limit && count($rows) < $maxRows);

        return $rows;
    }

    private function getJson(string $url, string $username, string $password, bool $verifyTls): array
    {
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
            throw new \RuntimeException('Unable to connect to Solid Server API.');
        }

        $status = $http_response_header[0] ?? '';
        if (!str_contains($status, '200') && !str_contains($status, '204')) {
            throw new \RuntimeException("Solid Server API error: {$status}");
        }

        if (trim($body) === '') {
            return [];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Solid Server returned invalid JSON.');
        }

        return $json;
    }

    private function aggregateSharedNetworks(array $ranges, float $warning, float $critical): array
    {
        $networks = [];
        $seenRanges = [];
        $rangeIndexes = [];

        foreach ($ranges as $range) {
            $name = trim((string) ($range['dhcpsn_name'] ?? $range['shared_network'] ?? $range['dhcpscope_name'] ?? 'unknown'));
            $id = $range['dhcpsn_id'] ?? $range['shared_network_id'] ?? $name;
            $key = $name;
            $rangeKey = implode('|', [
                $key,
                $range['dhcprange_start_addr'] ?? '',
                $range['dhcprange_end_addr'] ?? '',
                $range['dhcprange_name'] ?? '',
            ]);
            $vlan = $this->detectVlan($range);
            $cidr = $this->rangeCidr($range);

            if (!isset($networks[$key])) {
                $networks[$key] = [
                    'critical' => $critical,
                    'cidrs' => [],
                    'duplicate_range_count' => 0,
                    'free' => 0,
                    'free_percent' => null,
                    'id' => $id,
                    'name' => $name,
                    'range_count' => 0,
                    'ranges' => [],
                    'librenms' => [
                        'error' => null,
                        'interface_matches' => [],
                        'vlan_matches' => [],
                    ],
                    'servers' => [],
                    'state' => 'unknown',
                    'total' => 0,
                    'used' => 0,
                    'used_percent' => null,
                    'vlans' => [],
                    'warning' => $warning,
                ];
            }

            $server = trim((string) ($range['vdhcp_parent_name'] ?? $range['dhcp_name'] ?? $range['hostaddr'] ?? ''));
            if ($server !== '') {
                $networks[$key]['servers'][$server] = true;
            }
            if ($vlan !== null) {
                $networks[$key]['vlans'][$vlan] = true;
            }
            if ($cidr !== null) {
                $networks[$key]['cidrs'][$cidr] = true;
            }

            if (isset($seenRanges[$rangeKey])) {
                $networks[$key]['duplicate_range_count']++;
                if (isset($rangeIndexes[$rangeKey])) {
                    $rangeIndex = $rangeIndexes[$rangeKey];
                    $networks[$key]['ranges'][$rangeIndex]['duplicate_count']++;
                    if ($server !== '') {
                        $networks[$key]['ranges'][$rangeIndex]['servers'][$server] = true;
                    }
                    if ($vlan !== null) {
                        $networks[$key]['ranges'][$rangeIndex]['vlans'][$vlan] = true;
                    }
                    if ($cidr !== null) {
                        $networks[$key]['ranges'][$rangeIndex]['cidr'] = $cidr;
                    }
                }
                continue;
            }

            $seenRanges[$rangeKey] = true;

            $total = $this->firstNumber($range, ['dhcpscope_size', 'dhcpscope_total', 'dhcprange_size', 'total', 'size']);
            $used = $this->firstNumber($range, ['dhcpscope_used', 'dhcpscope_addr_used', 'dhcprange_used', 'dhcprange_lease_count', 'used', 'leases_used']);
            $free = $this->firstNumber($range, ['dhcpscope_free', 'dhcpscope_addr_free', 'dhcprange_free', 'free', 'leases_free', 'available']);

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

            $rangeIndex = count($networks[$key]['ranges']);
            $rangeIndexes[$rangeKey] = $rangeIndex;
            $networks[$key]['ranges'][] = [
                'cidr' => $cidr,
                'duplicate_count' => 0,
                'end' => $range['dhcprange_end_addr'] ?? '',
                'failover' => $range['dhcprange_failover_name'] ?? '',
                'free' => $free,
                'lease_percent' => $this->firstNumber($range, ['dhcprange_lease_percent', 'lease_percent']),
                'name' => $range['dhcprange_name'] ?? '',
                'scope' => $range['dhcpscope_name'] ?? '',
                'servers' => $server !== '' ? [$server => true] : [],
                'start' => $range['dhcprange_start_addr'] ?? '',
                'state' => $range['dhcprange_state'] ?? '',
                'total' => $total,
                'used' => $used,
                'vlans' => $vlan !== null ? [$vlan => true] : [],
            ];
        }

        foreach ($networks as &$network) {
            if ($network['total'] <= 0) {
                $network['cidrs'] = array_keys($network['cidrs']);
                $network['servers'] = array_keys($network['servers']);
                $network['vlans'] = array_keys($network['vlans']);
                foreach ($network['ranges'] as &$range) {
                    $range['servers'] = array_keys($range['servers']);
                    $range['vlans'] = array_keys($range['vlans']);
                }
                unset($range);
                continue;
            }

            $network['free_percent'] = ($network['free'] / $network['total']) * 100;
            $network['used_percent'] = ($network['used'] / $network['total']) * 100;
            $network['cidrs'] = array_keys($network['cidrs']);
            sort($network['cidrs'], SORT_NATURAL);
            $network['servers'] = array_keys($network['servers']);
            $network['vlans'] = array_keys($network['vlans']);
            sort($network['vlans'], SORT_NATURAL);
            foreach ($network['ranges'] as &$range) {
                $range['servers'] = array_keys($range['servers']);
                $range['vlans'] = array_keys($range['vlans']);
                sort($range['vlans'], SORT_NATURAL);
                if ($range['lease_percent'] === null && $range['total'] && $range['used'] !== null) {
                    $range['lease_percent'] = ($range['used'] / $range['total']) * 100;
                }
            }
            unset($range);

            if ($network['free_percent'] <= $critical) {
                $network['state'] = 'critical';
            } elseif ($network['free_percent'] <= $warning) {
                $network['state'] = 'warning';
            } else {
                $network['state'] = 'ok';
            }
        }

        usort($networks, fn ($a, $b) => ($a['free_percent'] ?? 999) <=> ($b['free_percent'] ?? 999));

        return $networks;
    }

    private function enrichWithLibreNms(array $networks): array
    {
        $cidrBounds = [];
        $vlanIds = [];
        foreach ($networks as $networkKey => $network) {
            foreach ($network['cidrs'] ?? [] as $cidr) {
                $bounds = $this->cidrBounds($cidr);
                if ($bounds !== null) {
                    $cidrBounds[$networkKey][$cidr] = $bounds;
                }
            }
            foreach ($network['vlans'] ?? [] as $vlan) {
                if (is_numeric($vlan)) {
                    $vlanIds[(string) ((int) $vlan)] = true;
                }
            }
        }

        if (!$vlanIds && !$cidrBounds) {
            return $networks;
        }

        if ($vlanIds) {
            try {
                $rows = DB::table('vlans')
                    ->leftJoin('devices', 'devices.device_id', '=', 'vlans.device_id')
                    ->whereIn('vlans.vlan_vlan', array_keys($vlanIds))
                    ->select([
                        'vlans.vlan_vlan',
                        'vlans.vlan_name',
                        'vlans.vlan_domain',
                        'devices.device_id',
                        'devices.hostname',
                        'devices.sysName',
                    ])
                    ->orderBy('vlans.vlan_vlan')
                    ->orderBy('devices.hostname')
                    ->limit(2000)
                    ->get();

                $matchesByVlan = [];
                foreach ($rows as $row) {
                    $vlan = (string) ((int) $row->vlan_vlan);
                    $matchesByVlan[$vlan][] = [
                        'device_id' => $row->device_id,
                        'hostname' => $row->hostname ?: $row->sysName ?: 'unknown-device',
                        'name' => $row->vlan_name ?: '',
                        'domain' => $row->vlan_domain ?: '',
                    ];
                }

                foreach ($networks as &$network) {
                    $network['librenms']['vlan_matches'] = [];
                    foreach ($network['vlans'] ?? [] as $vlan) {
                        $vlanKey = (string) ((int) $vlan);
                        if (!empty($matchesByVlan[$vlanKey])) {
                            $network['librenms']['vlan_matches'][$vlanKey] = $matchesByVlan[$vlanKey];
                        }
                    }
                }
                unset($network);
            } catch (\Throwable $exception) {
                $networks = $this->addLibreNmsError($networks, 'VLAN enrichment: ' . $exception->getMessage());
            }
        }

        if ($cidrBounds) {
            try {
                $query = DB::table('ipv4_addresses')
                    ->leftJoin('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
                    ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
                    ->select([
                        'ipv4_addresses.ipv4_address',
                        'ipv4_addresses.ipv4_prefixlen',
                        'ports.port_id',
                        'ports.ifName',
                        'ports.ifDescr',
                        'ports.ifAlias',
                        'devices.device_id',
                        'devices.hostname',
                        'devices.sysName',
                    ])
                    ->where(function ($query) use ($cidrBounds) {
                        foreach ($cidrBounds as $networkBounds) {
                            foreach ($networkBounds as $bounds) {
                                $query->orWhereRaw('INET_ATON(ipv4_addresses.ipv4_address) BETWEEN ? AND ?', [$bounds['start'], $bounds['end']]);
                            }
                        }
                    })
                    ->limit(2000);

                $rows = $query->get();
                foreach ($rows as $row) {
                    $ipInt = $this->ipToUnsigned((string) $row->ipv4_address);
                    if ($ipInt === null) {
                        continue;
                    }

                    foreach ($cidrBounds as $networkKey => $networkBounds) {
                        foreach ($networkBounds as $cidr => $bounds) {
                            if ($ipInt < $bounds['start'] || $ipInt > $bounds['end']) {
                                continue;
                            }

                            $matchKey = implode('|', [
                                $cidr,
                                $row->device_id,
                                $row->port_id,
                                $row->ipv4_address,
                            ]);

                            $networks[$networkKey]['librenms']['interface_matches'][$matchKey] = [
                                'cidr' => $cidr,
                                'device_id' => $row->device_id,
                                'hostname' => $row->hostname ?: $row->sysName ?: 'unknown-device',
                                'ifAlias' => $row->ifAlias ?: '',
                                'ifDescr' => $row->ifDescr ?: '',
                                'ifName' => $row->ifName ?: '',
                                'ip' => $row->ipv4_address,
                                'prefixlen' => $row->ipv4_prefixlen,
                                'port_id' => $row->port_id,
                            ];
                        }
                    }
                }

                foreach ($networks as &$network) {
                    $network['librenms']['interface_matches'] = array_values($network['librenms']['interface_matches']);
                }
                unset($network);
            } catch (\Throwable $exception) {
                $networks = $this->addLibreNmsError($networks, 'Interface enrichment: ' . $exception->getMessage());
            }
        }

        return $networks;
    }

    private function addLibreNmsError(array $networks, string $message): array
    {
        foreach ($networks as &$network) {
            $existing = $network['librenms']['error'] ?? null;
            $network['librenms']['error'] = $existing ? $existing . ' | ' . $message : $message;
        }
        unset($network);

        return $networks;
    }

    private function detectVlan(array $row): ?string
    {
        foreach (['vlan_id', 'vlan', 'vlanid', 'dhcp_vlan_id', 'dhcpscope_vlan_id'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (string) ((int) $row[$key]);
            }
        }

        $text = implode(' ', array_filter([
            $row['dhcpsn_name'] ?? null,
            $row['dhcpscope_name'] ?? null,
            $row['dhcprange_name'] ?? null,
            $row['dhcp_comment'] ?? null,
            $row['dhcprange_class_name'] ?? null,
            $row['dhcpscope_class_name'] ?? null,
        ]));

        if (preg_match('/\bvlan[\s_-]*(\d{1,4})\b/i', $text, $match)) {
            return (string) ((int) $match[1]);
        }

        return null;
    }

    private function rangeCidr(array $row): ?string
    {
        $netAddr = trim((string) ($row['dhcpscope_net_addr'] ?? ''));
        $prefix = trim((string) ($row['dhcpscope_prefix'] ?? ''));

        if ($netAddr === '' || $prefix === '' || !is_numeric($prefix)) {
            return null;
        }

        return $netAddr . '/' . (int) $prefix;
    }

    private function cidrBounds(string $cidr): ?array
    {
        if (!preg_match('/^([0-9.]+)\/(\d{1,2})$/', $cidr, $match)) {
            return null;
        }

        $base = $this->ipToUnsigned($match[1]);
        $prefix = (int) $match[2];
        if ($base === null || $prefix < 0 || $prefix > 32) {
            return null;
        }

        $size = 2 ** (32 - $prefix);
        $start = floor($base / $size) * $size;

        return [
            'end' => $start + $size - 1,
            'start' => $start,
        ];
    }

    private function lookupSolidServer(string $baseUrl, string $username, string $password, bool $verifyTls, array $ranges, string $query): array
    {
        $query = trim($query);
        $type = $this->lookupType($query);
        $rangeMatches = $type === 'ip' ? $this->lookupContainingRanges($ranges, $query) : [];
        $apiResults = [];
        $errors = [];

        foreach ($this->lookupPlans($query, $type) as $plan) {
            try {
                $rows = $this->fetchRows(
                    $baseUrl,
                    $plan['endpoint'],
                    $username,
                    $password,
                    $verifyTls,
                    $plan['where'],
                    100
                );

                foreach ($rows as $row) {
                    $apiResults[] = [
                        'endpoint' => $plan['endpoint'],
                        'label' => $plan['label'],
                        'summary' => $this->summarizeLookupRow($row),
                        'row' => $this->visibleLookupFields($row),
                    ];
                }
            } catch (\Throwable $exception) {
                $errors[] = [
                    'endpoint' => $plan['endpoint'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'api_results' => $apiResults,
            'errors' => $errors,
            'query' => $query,
            'range_matches' => $rangeMatches,
            'type' => $type,
        ];
    }

    private function lookupType(string $query): string
    {
        if (filter_var($query, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ip';
        }

        if (preg_match('/^[0-9a-f]{2}([:\-]?[0-9a-f]{2}){5}$/i', $query)) {
            return 'mac';
        }

        return 'name';
    }

    private function lookupPlans(string $query, string $type): array
    {
        $escaped = str_replace("'", "\\'", $query);
        $normalizedMac = strtolower(str_replace('-', ':', $query));

        if ($type === 'ip') {
            return [
                ['endpoint' => '/rest/dhcp_lease_list', 'label' => 'DHCP lease', 'where' => "hostaddr='{$escaped}'"],
                ['endpoint' => '/rest/dhcp_static_list', 'label' => 'DHCP reservation', 'where' => "hostaddr='{$escaped}'"],
                ['endpoint' => '/rest/ip_address_list', 'label' => 'IPAM address', 'where' => "hostaddr='{$escaped}'"],
                ['endpoint' => '/rest/dns_rr_list', 'label' => 'DNS record', 'where' => "value1='{$escaped}'"],
            ];
        }

        if ($type === 'mac') {
            $mac = str_replace("'", "\\'", $normalizedMac);
            return [
                ['endpoint' => '/rest/dhcp_lease_list', 'label' => 'DHCP lease', 'where' => "mac_addr='{$mac}'"],
                ['endpoint' => '/rest/dhcp_static_list', 'label' => 'DHCP reservation', 'where' => "mac_addr='{$mac}'"],
                ['endpoint' => '/rest/ip_address_list', 'label' => 'IPAM address', 'where' => "mac_addr='{$mac}'"],
            ];
        }

        return [
            ['endpoint' => '/rest/dhcp_lease_list', 'label' => 'DHCP lease', 'where' => "name='{$escaped}'"],
            ['endpoint' => '/rest/dhcp_static_list', 'label' => 'DHCP reservation', 'where' => "name='{$escaped}'"],
            ['endpoint' => '/rest/ip_address_list', 'label' => 'IPAM address', 'where' => "name='{$escaped}'"],
            ['endpoint' => '/rest/dns_rr_list', 'label' => 'DNS record', 'where' => "dnsrr_full_name='{$escaped}'"],
        ];
    }

    private function lookupContainingRanges(array $ranges, string $ip): array
    {
        $matches = [];
        $target = $this->ipToUnsigned($ip);
        if ($target === null) {
            return $matches;
        }

        foreach ($ranges as $range) {
            $start = $this->ipToUnsigned((string) ($range['dhcprange_start_addr'] ?? ''));
            $end = $this->ipToUnsigned((string) ($range['dhcprange_end_addr'] ?? ''));
            if ($start === null || $end === null) {
                continue;
            }

            if ($target >= $start && $target <= $end) {
                $matches[] = [
                    'end' => $range['dhcprange_end_addr'] ?? '',
                    'failover' => $range['dhcprange_failover_name'] ?? '',
                    'free' => null,
                    'lease_percent' => $this->firstNumber($range, ['dhcprange_lease_percent', 'lease_percent']),
                    'name' => $range['dhcprange_name'] ?? '',
                    'scope' => $range['dhcpscope_name'] ?? '',
                    'server' => $range['vdhcp_parent_name'] ?? $range['dhcp_name'] ?? '',
                    'shared_network' => trim((string) ($range['dhcpsn_name'] ?? '')),
                    'start' => $range['dhcprange_start_addr'] ?? '',
                    'state' => $range['dhcprange_state'] ?? '',
                    'total' => $this->firstNumber($range, ['dhcprange_size', 'dhcpscope_size']),
                    'used' => $this->firstNumber($range, ['dhcprange_lease_count', 'dhcprange_used']),
                    'vlan' => $this->detectVlan($range),
                ];
            }
        }

        return $matches;
    }

    private function ipToUnsigned(string $ip): ?float
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        return (float) sprintf('%u', ip2long($ip));
    }

    private function summarizeLookupRow(array $row): string
    {
        foreach (['hostaddr', 'ip_addr', 'name', 'hostname', 'dnsrr_full_name', 'dhcpstatic_name', 'dhcplease_name'] as $key) {
            if (!empty($row[$key])) {
                return (string) $row[$key];
            }
        }

        return 'Solid Server record';
    }

    private function visibleLookupFields(array $row): array
    {
        $preferred = [
            'hostaddr',
            'name',
            'hostname',
            'mac_addr',
            'client_id',
            'dhcplease_end_time',
            'dhcpstatic_name',
            'dnsrr_full_name',
            'value1',
            'dhcpsn_name',
            'dhcpscope_name',
            'dhcprange_name',
            'dhcp_name',
            'vdhcp_parent_name',
        ];
        $visible = [];

        foreach ($preferred as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                $visible[$key] = $row[$key];
            }
        }

        return $visible ?: array_slice($row, 0, 12, true);
    }

    private function summary(array $networks): array
    {
        $summary = [
            'critical' => 0,
            'ok' => 0,
            'total' => count($networks),
            'unknown' => 0,
            'warning' => 0,
        ];

        foreach ($networks as $network) {
            $state = $network['state'] ?? 'unknown';
            if (!isset($summary[$state])) {
                $state = 'unknown';
            }

            $summary[$state]++;
        }

        return $summary;
    }

    private function firstNumber(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }
}
