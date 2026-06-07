<h3>Solid Server DHCP shared networks</h3>

@if ($error)
    <div class="alert alert-danger">{{ $error }}</div>
@else
    <style>
        .solidserver-summary .alert {
            margin-bottom: 10px;
            padding: 10px 12px;
        }
        .solidserver-summary strong {
            font-size: 20px;
        }
        .solidserver-toolbar {
            align-items: center;
            display: flex;
            gap: 8px;
            margin: 8px 0 12px;
        }
        .solidserver-toolbar .form-control {
            max-width: 320px;
        }
        .solidserver-capacity {
            min-width: 150px;
        }
        .solidserver-capacity .progress {
            background-color: #2f363d;
            height: 8px;
            margin-bottom: 0;
        }
        .solidserver-capacity .progress-bar {
            min-width: 2px;
        }
        .solidserver-detail {
            background: rgba(0, 0, 0, 0.08);
        }
        .solidserver-detail table {
            margin-bottom: 0;
        }
    </style>

    <p>
        Source: {{ $base_url }}
        <span class="text-muted">| fetched {{ $fetched_at }} | raw ranges {{ number_format($raw_range_count) }}</span>
    </p>

    <div class="row solidserver-summary">
        <div class="col-sm-2 col-xs-6">
            <div class="alert alert-danger">
                <strong>{{ number_format($summary['critical']) }}</strong><br>
                Critical
            </div>
        </div>
        <div class="col-sm-2 col-xs-6">
            <div class="alert alert-warning">
                <strong>{{ number_format($summary['warning']) }}</strong><br>
                Warning
            </div>
        </div>
        <div class="col-sm-2 col-xs-6">
            <div class="alert alert-success">
                <strong>{{ number_format($summary['ok']) }}</strong><br>
                OK
            </div>
        </div>
        <div class="col-sm-2 col-xs-6">
            <div class="alert alert-info">
                <strong>{{ number_format($summary['total']) }}</strong><br>
                Shared networks
            </div>
        </div>
    </div>

    <div class="solidserver-toolbar">
        <input class="form-control input-sm" id="solidserver-filter" placeholder="Filter shared networks or DHCP source">
        <div class="btn-group btn-group-sm" data-toggle="buttons">
            <label class="btn btn-default active">
                <input autocomplete="off" checked name="solidserver-state" type="radio" value="all"> All
            </label>
            <label class="btn btn-danger">
                <input autocomplete="off" name="solidserver-state" type="radio" value="critical"> Critical
            </label>
            <label class="btn btn-warning">
                <input autocomplete="off" name="solidserver-state" type="radio" value="warning"> Warning
            </label>
            <label class="btn btn-success">
                <input autocomplete="off" name="solidserver-state" type="radio" value="ok"> OK
            </label>
        </div>
    </div>

    <table class="table table-condensed table-striped" id="solidserver-networks">
        <thead>
            <tr>
                <th>State</th>
                <th>Shared network</th>
                <th>Free</th>
                <th>Used %</th>
                <th>Used</th>
                <th>Total</th>
                <th>Ranges</th>
                <th>DHCP source</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shared_networks as $network)
                @php
                    $detail_id = 'solidserver-network-' . md5($network['name']);
                @endphp
                <tr class="solidserver-network-row" data-state="{{ $network['state'] }}" data-filter="{{ strtolower($network['name'] . ' ' . implode(' ', $network['servers'])) }}" data-detail="{{ $detail_id }}">
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
                    <td class="solidserver-capacity">
                        @if ($network['free_percent'] !== null)
                            @if ($network['state'] === 'critical')
                                <span class="label label-danger">{{ number_format($network['free_percent'], 2) }}%</span>
                            @elseif ($network['state'] === 'warning')
                                <span class="label label-warning">{{ number_format($network['free_percent'], 2) }}%</span>
                            @else
                                <span class="label label-success">{{ number_format($network['free_percent'], 2) }}%</span>
                            @endif
                            <div class="progress">
                                <div class="progress-bar progress-bar-success" style="width: {{ max(0, min(100, $network['free_percent'])) }}%"></div>
                            </div>
                        @else
                            <span class="label label-default">unknown</span>
                        @endif
                    </td>
                    <td class="solidserver-capacity">
                        @if ($network['used_percent'] !== null)
                            @if ($network['state'] === 'critical')
                                <span class="label label-danger">{{ number_format($network['used_percent'], 2) }}%</span>
                            @elseif ($network['state'] === 'warning')
                                <span class="label label-warning">{{ number_format($network['used_percent'], 2) }}%</span>
                            @else
                                <span class="label label-success">{{ number_format($network['used_percent'], 2) }}%</span>
                            @endif
                            <div class="progress">
                                @if ($network['state'] === 'critical')
                                    <div class="progress-bar progress-bar-danger" style="width: {{ max(0, min(100, $network['used_percent'])) }}%"></div>
                                @elseif ($network['state'] === 'warning')
                                    <div class="progress-bar progress-bar-warning" style="width: {{ max(0, min(100, $network['used_percent'])) }}%"></div>
                                @else
                                    <div class="progress-bar progress-bar-success" style="width: {{ max(0, min(100, $network['used_percent'])) }}%"></div>
                                @endif
                            </div>
                        @else
                            <span class="label label-default">unknown</span>
                        @endif
                    </td>
                    <td>{{ number_format($network['used']) }}</td>
                    <td>{{ number_format($network['total']) }}</td>
                    <td>{{ $network['range_count'] }}</td>
                    <td>{{ implode(', ', $network['servers']) }}</td>
                    <td>
                        <button class="btn btn-xs btn-info" type="button" data-toggle="collapse" data-target="#{{ $detail_id }}">
                            {{ $network['range_count'] }} ranges
                        </button>
                    </td>
                </tr>
                <tr class="solidserver-detail-row" data-detail-for="{{ $detail_id }}">
                    <td colspan="9">
                        <div class="collapse solidserver-detail" id="{{ $detail_id }}">
                            <table class="table table-condensed">
                                <thead>
                                    <tr>
                                        <th>Range</th>
                                        <th>Scope</th>
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
                                    @foreach ($network['ranges'] as $range)
                                        @php
                                            $range_state = 'ok';
                                            if ($range['lease_percent'] === null) {
                                                $range_state = 'unknown';
                                            } elseif ((100 - $range['lease_percent']) <= $network['critical']) {
                                                $range_state = 'critical';
                                            } elseif ((100 - $range['lease_percent']) <= $network['warning']) {
                                                $range_state = 'warning';
                                            }
                                        @endphp
                                        <tr>
                                            <td>
                                                @if ($range['start'] || $range['end'])
                                                    {{ $range['start'] }} - {{ $range['end'] }}
                                                @else
                                                    {{ $range['name'] }}
                                                @endif
                                            </td>
                                            <td>{{ $range['scope'] }}</td>
                                            <td>{{ $range['used'] !== null ? number_format($range['used']) : 'unknown' }}</td>
                                            <td>{{ $range['total'] !== null ? number_format($range['total']) : 'unknown' }}</td>
                                            <td>{{ $range['free'] !== null ? number_format($range['free']) : 'unknown' }}</td>
                                            <td>
                                                @if ($range_state === 'critical')
                                                    <span class="label label-danger">{{ number_format($range['lease_percent'], 2) }}%</span>
                                                @elseif ($range_state === 'warning')
                                                    <span class="label label-warning">{{ number_format($range['lease_percent'], 2) }}%</span>
                                                @elseif ($range_state === 'ok')
                                                    <span class="label label-success">{{ number_format($range['lease_percent'], 2) }}%</span>
                                                @else
                                                    <span class="label label-default">unknown</span>
                                                @endif
                                            </td>
                                            <td>{{ $range['state'] }}</td>
                                            <td>{{ $range['duplicate_count'] }}</td>
                                            <td>{{ $range['failover'] }}</td>
                                            <td>{{ implode(', ', $range['servers']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">No DHCP shared network data returned.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <script>
        (function () {
            var filterInput = document.getElementById('solidserver-filter');
            var stateInputs = document.querySelectorAll('input[name="solidserver-state"]');
            var rows = document.querySelectorAll('.solidserver-network-row');

            function activeState() {
                for (var i = 0; i < stateInputs.length; i++) {
                    if (stateInputs[i].checked) {
                        return stateInputs[i].value;
                    }
                }
                return 'all';
            }

            function applyFilter() {
                var filter = (filterInput.value || '').toLowerCase();
                var state = activeState();

                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var detailId = row.getAttribute('data-detail');
                    var detailRow = document.querySelector('[data-detail-for="' + detailId + '"]');
                    var rowState = row.getAttribute('data-state');
                    var rowFilter = row.getAttribute('data-filter') || '';
                    var visible = (state === 'all' || rowState === state) && rowFilter.indexOf(filter) !== -1;

                    row.style.display = visible ? '' : 'none';
                    if (detailRow) {
                        detailRow.style.display = visible ? '' : 'none';
                    }
                }
            }

            if (filterInput) {
                filterInput.addEventListener('input', applyFilter);
            }
            for (var i = 0; i < stateInputs.length; i++) {
                stateInputs[i].addEventListener('change', applyFilter);
            }
        })();
    </script>
@endif
