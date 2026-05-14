
# Alert Engine Scaffold

This scaffold creates a shared alert formatting layer for Graylog, NMS, and Mist.

## Files
- `broker/models.py` - normalized alert model
- `broker/mappings.py` - severity/state/source mappings
- `broker/templates.py` - message templates
- `broker/formatters.py` - Slack-style formatter
- `broker/app.py` - per-source normalizers and renderer
- `tests/samples/*.json` - sample payloads
- `scripts/preview_alerts.py` - preview script

## Usage
Run:

```bash
python scripts/preview_alerts.py
```
