<h3>Solid Server DHCP shared networks</h3>

@if ($error)
    <div class="alert alert-danger">{{ $error }}</div>
@else
    <p>
        Source: {{ $base_url }}
        <span class="text-muted">| fetched {{ $fetched_at }} | raw ranges {{ number_format($raw_range_count) }}</span>
    </p>

    <div class="row">
        <div class="col-sm-2">
            <div class="alert alert-danger">
                <strong>{{ number_format($summary['critical']) }}</strong><br>
                Critical
            </div>
        </div>
        <div class="col-sm-2">
            <div class="alert alert-warning">
                <strong>{{ number_format($summary['warning']) }}</strong><br>
                Warning
            </div>
        </div>
        <div class="col-sm-2">
            <div class="alert alert-success">
                <strong>{{ number_format($summary['ok']) }}</strong><br>
                OK
            </div>
        </div>
        <div class="col-sm-2">
            <div class="alert alert-info">
                <strong>{{ number_format($summary['total']) }}</strong><br>
                Shared networks
            </div>
        </div>
    </div>

    <table class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>State</th>
                <th>Shared network</th>
                <th>Free</th>
                <th>Used %</th>
                <th>Used</th>
                <th>Total</th>
                <th>Ranges</th>
                <th>HA duplicates</th>
                <th>DHCP source</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shared_networks as $network)
                <tr>
                    <td>
                        @if ($network['state'] === 'critical')
                            <span class="label label-danger">critical</span>
                        @elseif ($network['state'] === 'warning')
                            <span class="label label-warning">warning</span>
                        @elseif ($network['state'] === 'ok')
                            <span class="label label-success">ok</span>
                        @else
                            <span class="label label-default">unknown</span>
                        @endif
                    </td>
                    <td>{{ $network['name'] }}</td>
                    <td>
                        @if ($network['free_percent'] !== null)
                            {{ number_format($network['free_percent'], 2) }}%
                        @else
                            unknown
                        @endif
                    </td>
                    <td>
                        @if ($network['used_percent'] !== null)
                            {{ number_format($network['used_percent'], 2) }}%
                        @else
                            unknown
                        @endif
                    </td>
                    <td>{{ number_format($network['used']) }}</td>
                    <td>{{ number_format($network['total']) }}</td>
                    <td>{{ $network['range_count'] }}</td>
                    <td>{{ $network['duplicate_range_count'] }}</td>
                    <td>{{ implode(', ', $network['servers']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">No DHCP shared network data returned.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endif
