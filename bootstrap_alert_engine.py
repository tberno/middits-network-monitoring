from pathlib import Path
import textwrap

ROOT = Path.cwd()

FILES = {
    "broker/__init__.py": "",
    "broker/models.py": '''
from dataclasses import dataclass, field
from typing import Any, Dict, Optional


@dataclass
class NormalizedAlert:
    source: str
    event_type: str
    state: str
    severity: str
    device: str
    summary: str
    details: str = ""
    ip: Optional[str] = None
    alert_id: Optional[str] = None
    rule: Optional[str] = None
    link: Optional[str] = None
    fired_at: Optional[str] = None
    resolved_at: Optional[str] = None
    downtime: Optional[str] = None
    metadata: Dict[str, Any] = field(default_factory=dict)
''',
    "broker/mappings.py": '''
SEVERITY_TO_EMOJI = {
    "critical": ":red_circle:",
    "warning": ":large_orange_circle:",
    "info": ":large_blue_circle:",
    "ok": ":white_check_mark:",
    "resolved": ":large_green_circle:",
}

STATE_LABEL = {
    "alert": "ALERT",
    "resolved": "RESOLVED",
}

SOURCE_LABEL = {
    "graylog": "Graylog",
    "nms": "NMS",
    "mist": "Mist",
}
''',
    "broker/templates.py": '''
ALERT_TEMPLATE = """{emoji} {state_label} [{source}] {summary}
Device: {device}
Severity: {severity}{ip_line}{rule_line}{fired_line}{resolved_line}{downtime_line}{link_line}{details_block}"""

DETAILS_TEMPLATE = """
Details:
{details}
"""
''',
    "broker/formatters.py": '''
from broker.mappings import SEVERITY_TO_EMOJI, STATE_LABEL, SOURCE_LABEL
from broker.templates import ALERT_TEMPLATE, DETAILS_TEMPLATE


def _line(label: str, value):
    return f"\\n{label}: {value}" if value else ""


def format_slack_alert(alert):
    severity_key = (alert.severity or "").lower()
    state_key = (alert.state or "").lower()

    emoji = SEVERITY_TO_EMOJI.get(
        "resolved" if state_key == "resolved" else severity_key,
        ":red_circle:",
    )
    state_label = STATE_LABEL.get(state_key, alert.state.upper())
    source_label = SOURCE_LABEL.get(alert.source.lower(), alert.source)

    details_block = ""
    if alert.details:
        details_block = DETAILS_TEMPLATE.format(details=alert.details.strip())

    return ALERT_TEMPLATE.format(
        emoji=emoji,
        state_label=state_label,
        source=source_label,
        summary=alert.summary,
        device=alert.device,
        severity=alert.severity.upper(),
        ip_line=_line("IP", alert.ip),
        rule_line=_line("Rule", alert.rule),
        fired_line=_line("Fired", alert.fired_at),
        resolved_line=_line("Resolved", alert.resolved_at),
        downtime_line=_line("Downtime", alert.downtime),
        link_line=_line("Link", alert.link),
        details_block=details_block,
    )
''',
    "broker/app.py": '''
from broker.models import NormalizedAlert
from broker.formatters import format_slack_alert


def normalize_graylog(payload):
    return NormalizedAlert(
        source="graylog",
        event_type=payload.get("event_type", "graylog_event"),
        state=payload.get("state", "alert"),
        severity=payload.get("severity", "critical"),
        device=payload.get("device", "unknown-device"),
        summary=payload.get("summary", "Graylog alert"),
        details=payload.get("message", ""),
        alert_id=payload.get("alert_id"),
        fired_at=payload.get("fired_at"),
        link=payload.get("link"),
        metadata=payload,
    )


def normalize_nms(payload):
    return NormalizedAlert(
        source="nms",
        event_type=payload.get("event_type", "nms_event"),
        state=payload.get("state", "alert"),
        severity=payload.get("severity", "warning"),
        device=payload.get("device", "unknown-device"),
        summary=payload.get("summary", "NMS alert"),
        details=payload.get("details", ""),
        ip=payload.get("ip"),
        alert_id=payload.get("alert_id"),
        rule=payload.get("rule"),
        link=payload.get("link"),
        fired_at=payload.get("fired_at"),
        resolved_at=payload.get("resolved_at"),
        downtime=payload.get("downtime"),
        metadata=payload,
    )


def normalize_mist(payload):
    return NormalizedAlert(
        source="mist",
        event_type=payload.get("event_type", "mist_event"),
        state=payload.get("state", "resolved"),
        severity=payload.get("severity", "ok"),
        device=payload.get("device", "unknown-device"),
        summary=payload.get("summary", "Mist alert"),
        details=payload.get("details", ""),
        alert_id=payload.get("alert_id"),
        fired_at=payload.get("fired_at"),
        resolved_at=payload.get("resolved_at"),
        metadata=payload,
    )


def render_alert(source, payload):
    normalizers = {
        "graylog": normalize_graylog,
        "nms": normalize_nms,
        "mist": normalize_mist,
    }
    alert = normalizers[source](payload)
    return format_slack_alert(alert)
''',
    "tests/samples/graylog.json": '''
{
  "event_type": "graylog_event",
  "state": "alert",
  "severity": "critical",
  "device": "FABRIC-CORE-DFL",
  "summary": "Network Hardware Critical Extended",
  "message": "fpc0 qsfp-0010 Chan 0 Rx power low alarm set",
  "alert_id": "graylog-1001",
  "fired_at": "2026-05-14T10:46:25-0400",
  "link": "https://graylog.example/events/graylog-1001"
}
''',
    "tests/samples/nms.json": '''
{
  "event_type": "nms_event",
  "state": "resolved",
  "severity": "critical",
  "device": "ross-dining-nutrislice",
  "summary": "Device recovered",
  "details": "Device returned to service after outage.",
  "ip": "10.2.206.142",
  "alert_id": "nms-86361",
  "rule": "Devices up/down",
  "link": "https://raccoon.middlebury.edu/alerts/86361",
  "fired_at": "2026-05-14 08:01:00 AM",
  "resolved_at": "2026-05-14 08:16:01 AM",
  "downtime": "15m 0s"
}
''',
    "tests/samples/mist.json": '''
{
  "event_type": "mist_event",
  "state": "resolved",
  "severity": "ok",
  "device": "FABRIC-CORE-DFL",
  "summary": "Sw Bgp Neighbor Up",
  "details": "BGP peer 10.40.0.47 changed state from OpenConfirm to Established.",
  "alert_id": "mist-5001",
  "fired_at": "2026-05-14 12:40:39 PM",
  "resolved_at": "2026-05-14 12:41:00 PM"
}
''',
    "scripts/preview_alerts.py": '''
import json
from pathlib import Path
from broker.app import render_alert


ROOT = Path(__file__).resolve().parents[1]
SAMPLES = ROOT / "tests" / "samples"


def load(name):
    with open(SAMPLES / name, "r", encoding="utf-8") as f:
        return json.load(f)


def main():
    for source, filename in [
        ("graylog", "graylog.json"),
        ("nms", "nms.json"),
        ("mist", "mist.json"),
    ]:
        payload = load(filename)
        print("=" * 80)
        print(render_alert(source, payload))
        print()

if __name__ == "__main__":
    main()
''',
    "README-alert-engine.md": '''
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
''',
}

def write_file(rel_path: str, content: str):
    path = ROOT / rel_path
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        print(f"skipped {rel_path} (already exists)")
        return
    normalized = textwrap.dedent(content).lstrip("\\n")
    path.write_text(normalized, encoding="utf-8")
    print(f"created {rel_path}")


def main():
    for rel_path, content in FILES.items():
        write_file(rel_path, content)
    print("Done. No existing files were overwritten.")


if __name__ == "__main__":
    main()