<?php

namespace App\Plugins\SolidServer;

use App\Plugins\Hooks\PageHook;
use Illuminate\Contracts\Auth\Authenticatable;

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
        $sharedNetworks = [];
        $rawRangeCount = 0;

        if ($username === '' || $password === '') {
            $error = 'Solid Server credentials are not configured.';
        } else {
            try {
                $ranges = $this->fetchRows($baseUrl, '/rest/dhcp_range_list', $username, $password, $verifyTls);
                $rawRangeCount = count($ranges);
                $sharedNetworks = $this->aggregateSharedNetworks($ranges, $warning, $critical);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'base_url' => $baseUrl,
            'critical' => $critical,
            'error' => $error,
            'fetched_at' => date('Y-m-d H:i:s'),
            'raw_range_count' => $rawRangeCount,
            'shared_networks' => $sharedNetworks,
            'summary' => $this->summary($sharedNetworks),
            'warning' => $warning,
        ];
    }

    private function fetchRows(string $baseUrl, string $endpoint, string $username, string $password, bool $verifyTls): array
    {
        // Read-only by design: this plugin only performs GET/list requests.
        $rows = [];
        $limit = 500;
        $offset = 0;

        do {
            $url = $baseUrl . $endpoint . '?' . http_build_query([
                'limit' => $limit,
                'offset' => $offset,
            ]);

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
        } while ($count >= $limit);

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

            if (!isset($networks[$key])) {
                $networks[$key] = [
                    'critical' => $critical,
                    'duplicate_range_count' => 0,
                    'free' => 0,
                    'free_percent' => null,
                    'id' => $id,
                    'name' => $name,
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

            $server = trim((string) ($range['vdhcp_parent_name'] ?? $range['dhcp_name'] ?? $range['hostaddr'] ?? ''));
            if ($server !== '') {
                $networks[$key]['servers'][$server] = true;
            }
            if ($vlan !== null) {
                $networks[$key]['vlans'][$vlan] = true;
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
