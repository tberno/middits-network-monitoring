<div class="form-group">
    <label for="base_url">Solid Server base URL</label>
    <input class="form-control" id="base_url" name="base_url" value="{{ $settings['base_url'] ?? 'https://juno-eip.middlebury.edu' }}">
</div>

<div class="form-group">
    <label for="username">API username</label>
    <input class="form-control" id="username" name="username" value="{{ $settings['username'] ?? '' }}">
</div>

<div class="form-group">
    <label for="password">API password</label>
    <input class="form-control" id="password" name="password" type="password" value="{{ $settings['password'] ?? '' }}">
</div>

<div class="form-group">
    <label for="warning_free_percent">Warning free percent</label>
    <input class="form-control" id="warning_free_percent" name="warning_free_percent" type="number" step="0.1" value="{{ $settings['warning_free_percent'] ?? 20 }}">
</div>

<div class="form-group">
    <label for="critical_free_percent">Critical free percent</label>
    <input class="form-control" id="critical_free_percent" name="critical_free_percent" type="number" step="0.1" value="{{ $settings['critical_free_percent'] ?? 10 }}">
</div>

<div class="checkbox">
    <label>
        <input name="verify_tls" type="checkbox" value="1" @if (($settings['verify_tls'] ?? true)) checked @endif>
        Verify TLS certificate
    </label>
</div>
