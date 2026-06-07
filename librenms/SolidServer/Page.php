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

        if ($username === '' || $password === '') {
            $error = 'Solid Server credentials are not configured.';
        } else {
            try {
                $ranges = $this->fetchRows($baseUrl, '/rest/dhcp_range_list', $username, $password, $verifyTls);
                $sharedNetworks = $this->aggregateSharedNetworks($ranges, $warning, $critical);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'base_url' => $baseUrl,
            'critical' => $critical,
            'error' => $error,
            'shared_networks' => $sharedNetworks,
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

        foreach ($ranges as $range) {
            $name = $range['dhcpsn_name'] ?? $range['shared_network'] ?? $range['dhcpscope_name'] ?? 'unknown';
            $id = $range['dhcpsn_id'] ?? $range['shared_network_id'] ?? $name;
            $key = (string) $id;

            if (!isset($networks[$key])) {
                $networks[$key] = [
                    'critical' => $critical,
                    'free' => 0,
                    'free_percent' => null,
                    'id' => $id,
                    'name' => $name,
                    'range_count' => 0,
                    'state' => 'unknown',
                    'total' => 0,
                    'used' => 0,
                    'warning' => $warning,
                ];
            }

            $total = $this->firstNumber($range, ['dhcpscope_size', 'dhcpscope_total', 'dhcprange_size', 'total', 'size']);
            $used = $this->firstNumber($range, ['dhcpscope_used', 'dhcpscope_addr_used', 'dhcprange_used', 'used', 'leases_used']);
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
        }

        foreach ($networks as &$network) {
            if ($network['total'] <= 0) {
                continue;
            }

            $network['free_percent'] = ($network['free'] / $network['total']) * 100;
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
