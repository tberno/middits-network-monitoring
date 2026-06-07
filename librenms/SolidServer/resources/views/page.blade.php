<h3>Solid Server DHCP shared networks</h3>

@if ($error)
    <div class="alert alert-danger">{{ $error }}</div>
@else
    <p>Source: {{ $base_url }}</p>

    <table class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>State</th>
                <th>Shared network</th>
                <th>Free</th>
                <th>Used</th>
                <th>Total</th>
                <th>Ranges</th>
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
                    <td>{{ number_format($network['used']) }}</td>
                    <td>{{ number_format($network['total']) }}</td>
                    <td>{{ $network['range_count'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No DHCP shared network data returned.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endif
