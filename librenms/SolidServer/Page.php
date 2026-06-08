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
        $rawRangeCount = 0;
        $sharedNetworks = [];

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

            $page = $this->getJson($baseUrl . $endpoint . '?' . http_build_query($params), $username, $password, $verifyTls);
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
        $rangeIndexes = [];
        $seenRanges = [];

        foreach ($ranges as $range) {
            $name = trim((string) ($range['dhcpsn_name'] ?? $range['shared_network'] ?? $range['dhcpscope_name'] ?? 'unknown'));
            $key = $name === '' ? 'unknown' : $name;
            $id = $range['dhcpsn_id'] ?? $range['shared_network_id'] ?? $key;
            $rangeKey = implode('|', [
                $key,
                $range['dhcprange_start_addr'] ?? '',
                $range['dhcprange_end_addr'] ?? '',
                $range['dhcprange_name'] ?? '',
            ]);
            $server = trim((string) ($range['vdhcp_parent_name'] ?? $range['dhcp_name'] ?? $range['hostaddr'] ?? ''));
            $vlan = $this->detectVlan($range);
            $cidr = $this->rangeCidr($range);

            if (!isset($networks[$key])) {
                $networks[$key] = [
                    'attention_notes' => [],
                    'cidrs' => [],
                    'critical' => $critical,
                    'duplicate_range_count' => 0,
                    'free' => 0,
                    'free_percent' => null,
                    'id' => $id,
                    'librenms' => [
                        'error' => null,
                        'interface_matches' => [],
                        'vlan_matches' => [],
                    ],
                    'name' => $key,
                    'range_count' => 0,
                    'ranges' => [],
                    'servers' => [],
                    'state' => 'unknown',
                    'total' => 0,
                    'used' => 0,
                    'used_percent' => null,
                    'vlans' => [],
                    'warning' => $warning,
                ];
            }

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
                    $networks[$key]['ranges'][$rangeIndexes[$rangeKey]]['duplicate_count']++;
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

            $leasePercent = null;
            if ($total !== null && $total > 0 && $used !== null) {
                $leasePercent = ($used / $total) * 100;
            }

            $rangeIndex = count($networks[$key]['ranges']);
            $rangeIndexes[$rangeKey] = $rangeIndex;
            $networks[$key]['ranges'][] = [
                'cidr' => $cidr,
                'duplicate_count' => 0,
                'end' => $range['dhcprange_end_addr'] ?? '',
                'failover' => $range['dhcprange_failover_name'] ?? '',
                'free' => $free,
                'lease_percent' => $leasePercent,
                'scope' => $range['dhcpscope_name'] ?? '',
                'server' => $server,
                'start' => $range['dhcprange_start_addr'] ?? '',
                'state' => $range['dhcprange_state'] ?? $range['row_enabled'] ?? '',
                'total' => $total,
                'used' => $used,
                'vlan' => $vlan,
                'vlans' => $vlan === null ? [] : [$vlan],
            ];
        }

        foreach ($networks as &$network) {
            $network['cidrs'] = array_keys($network['cidrs']);
            $network['servers'] = array_keys($network['servers']);
            $network['vlans'] = array_keys($network['vlans']);

            if ($network['total'] <= 0) {
                continue;
            }

            $network['free_percent'] = ($network['free'] / $network['total']) * 100;
            $network['used_percent'] = ($network['used'] / $network['total']) * 100;

            if ($network['free_percent'] <= $critical) {
                $network['state'] = 'critical';
                $network['attention_notes'][] = [
                    'severity' => 'critical',
                    'text' => 'DHCP free capacity is below the configured critical threshold.',
                ];
            } elseif ($network['free_percent'] <= $warning) {
                $network['state'] = 'warning';
                $network['attention_notes'][] = [
                    'severity' => 'warning',
                    'text' => 'DHCP free capacity is below the configured warning threshold.',
                ];
            } else {
                $network['state'] = 'ok';
            }
        }
        unset($network);

        usort($networks, fn ($a, $b) => ($a['free_percent'] ?? 9999) <=> ($b['free_percent'] ?? 9999));

        return $networks;
    }

    private function enrichWithLibreNms(array $networks): array
    {
        $cidrBounds = [];
        $vlanIds = [];

        foreach ($networks as $key => $network) {
            foreach ($network['cidrs'] ?? [] as $cidr) {
                $bounds = $this->cidrBounds($cidr);
                if ($bounds !== null) {
                    $cidrBounds[$key][$cidr] = $bounds;
                }
            }
            foreach ($network['vlans'] ?? [] as $vlan) {
                if (is_numeric($vlan)) {
                    $vlanIds[(string) ((int) $vlan)] = true;
                }
            }
        }

        if ($cidrBounds) {
            try {
                $rows = DB::table('ipv4_addresses')
                    ->leftJoin('ports', 'ports.port_id', '=', 'ipv4_addresses.port_id')
                    ->leftJoin('devices', 'devices.device_id', '=', 'ports.device_id')
                    ->select([
                        'ipv4_addresses.ipv4_address',
                        'ipv4_addresses.ipv4_prefixlen',
                        'ports.ifName',
                        'ports.ifDescr',
                        'ports.ifAlias',
                        'devices.hostname',
                        'devices.sysName',
                    ])
                    ->where(function ($query) use ($cidrBounds) {
                        foreach ($cidrBounds as $boundsByNetwork) {
                            foreach ($boundsByNetwork as $bounds) {
                                $query->orWhereRaw('INET_ATON(ipv4_addresses.ipv4_address) BETWEEN ? AND ?', [$bounds['start'], $bounds['end']]);
                            }
                        }
                    })
                    ->limit(5000)
                    ->get();

                foreach ($rows as $row) {
                    $ip = $this->ipToUnsigned((string) $row->ipv4_address);
                    if ($ip === null) {
                        continue;
                    }

                    foreach ($cidrBounds as $networkKey => $boundsByCidr) {
                        foreach ($boundsByCidr as $cidr => $bounds) {
                            if ($ip < $bounds['start'] || $ip > $bounds['end']) {
                                continue;
                            }

                            $networks[$networkKey]['librenms']['interface_matches'][] = [
                                'alias' => $row->ifAlias ?: '',
                                'cidr' => $cidr,
                                'description' => $row->ifDescr ?: '',
                                'device' => $row->hostname ?: $row->sysName ?: '',
                                'hostname' => $row->hostname ?: $row->sysName ?: '',
                                'ifAlias' => $row->ifAlias ?: '',
                                'ifDescr' => $row->ifDescr ?: '',
                                'ifName' => $row->ifName ?: '',
                                'interface_ip' => $row->ipv4_address . '/' . $row->ipv4_prefixlen,
                                'ip' => $row->ipv4_address . '/' . $row->ipv4_prefixlen,
                                'port' => $row->ifName ?: '',
                            ];

                            $vlan = $this->inferVlanFromInterface($row->ifName, $row->ifDescr, $row->ifAlias);
                            if ($vlan !== null && !in_array($vlan, $networks[$networkKey]['vlans'], true)) {
                                $networks[$networkKey]['vlans'][] = $vlan;
                                $vlanIds[$vlan] = true;
                            }
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $networks = $this->addLibreNmsError($networks, 'Interface enrichment: ' . $exception->getMessage());
            }
        }

        if ($vlanIds) {
            try {
                $rows = DB::table('vlans')
                    ->leftJoin('devices', 'devices.device_id', '=', 'vlans.device_id')
                    ->whereIn('vlans.vlan_vlan', array_keys($vlanIds))
                    ->select(['vlans.vlan_vlan', 'vlans.vlan_name', 'devices.hostname', 'devices.sysName'])
                    ->limit(2000)
                    ->get();

                $matches = [];
                foreach ($rows as $row) {
                    $vlan = (string) ((int) $row->vlan_vlan);
                    $matches[$vlan][] = [
                        'hostname' => $row->hostname ?: $row->sysName ?: '',
                        'name' => $row->vlan_name ?: '',
                        'vlan' => $vlan,
                    ];
                }

                foreach ($networks as &$network) {
                    foreach ($network['vlans'] ?? [] as $vlan) {
                        $key = (string) ((int) $vlan);
                        if (!empty($matches[$key])) {
                            $network['librenms']['vlan_matches'][$key] = $matches[$key];
                        }
                    }
                }
                unset($network);
            } catch (\Throwable $exception) {
                $networks = $this->addLibreNmsError($networks, 'VLAN enrichment: ' . $exception->getMessage());
            }
        }

        foreach ($networks as &$network) {
            if (!empty($network['cidrs']) && empty($network['librenms']['interface_matches'])) {
                $network['attention_notes'][] = [
                    'severity' => 'warning',
                    'text' => 'No LibreNMS interface IP found in EIP CIDR.',
                ];
            }

            if (empty($network['vlans']) && !empty($network['librenms']['interface_matches'])) {
                $network['attention_notes'][] = [
                    'severity' => 'info',
                    'text' => 'LibreNMS interface match found; VLAN may be inferable from interface naming or alias.',
                ];
            }

            if (!empty($network['vlans']) && empty($network['librenms']['vlan_matches'])) {
                $network['attention_notes'][] = [
                    'severity' => 'info',
                    'text' => 'Detected VLAN has no matching LibreNMS VLAN inventory entry.',
                ];
            }
        }
        unset($network);

        return $networks;
    }

    private function lookupSolidServer(string $baseUrl, string $username, string $password, bool $verifyTls, array $ranges, string $query): array
    {
        $query = trim($query);
        $type = $this->lookupType($query);

        return [
            'api_results' => [],
            'errors' => [],
            'query' => $query,
            'range_matches' => $type === 'ip' ? $this->lookupContainingRanges($ranges, $query) : [],
            'type' => $type,
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
            if ($start === null || $end === null || $target < $start || $target > $end) {
                continue;
            }

            $total = $this->firstNumber($range, ['dhcprange_size', 'dhcpscope_size']);
            $used = $this->firstNumber($range, ['dhcprange_lease_count', 'dhcprange_used', 'dhcpscope_used']);
            $matches[] = [
                'end' => $range['dhcprange_end_addr'] ?? '',
                'scope' => $range['dhcpscope_name'] ?? '',
                'server' => $range['vdhcp_parent_name'] ?? $range['dhcp_name'] ?? '',
                'shared_network' => trim((string) ($range['dhcpsn_name'] ?? '')),
                'start' => $range['dhcprange_start_addr'] ?? '',
                'total' => $total,
                'used' => $used,
                'vlan' => $this->detectVlan($range),
            ];
        }

        return $matches;
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

        if (preg_match('/\bvlan[\s._-]*(\d{1,4})\b/i', $text, $match)) {
            return (string) ((int) $match[1]);
        }

        return null;
    }

    private function inferVlanFromInterface(?string $ifName, ?string $ifDescr, ?string $ifAlias): ?string
    {
        $text = implode(' ', array_filter([$ifName, $ifDescr, $ifAlias]));
        if (preg_match('/\b(?:vlan|vl)[\s._-]*(\d{1,4})\b/i', $text, $match)) {
            return (string) ((int) $match[1]);
        }
        if (preg_match('/\.(\d{1,4})\b/', $text, $match)) {
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

    private function ipToUnsigned(string $ip): ?float
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        return (float) sprintf('%u', ip2long($ip));
    }

    private function addLibreNmsError(array $networks, string $message): array
    {
        foreach ($networks as &$network) {
            $network['librenms']['error'] = trim(($network['librenms']['error'] ?? '') . ' ' . $message);
        }
        unset($network);

        return $networks;
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
