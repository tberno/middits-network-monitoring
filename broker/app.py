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
    state_value = payload.get("state")
    state = "alert" if str(state_value) == "1" else "resolved"

    summary = payload.get("name") or payload.get("rule") or "NMS alert"

    faults = payload.get("faults") or []
    fault_lines = []
    for fault in faults:
        if isinstance(fault, dict) and fault.get("string"):
            fault_lines.append(fault["string"])

    details = "\n".join(fault_lines) if fault_lines else payload.get("details", "")

    return NormalizedAlert(
        source="nms",
        event_type=payload.get("event_type", "nms_event"),
        state=state,
        severity=payload.get("severity", "critical"),
        device=payload.get("hostname") or payload.get("device", "unknown-device"),
        summary=summary,
        details=details,
        ip=payload.get("ip"),
        alert_id=payload.get("alert_id") or payload.get("id"),
        rule=payload.get("rule"),
        link=payload.get("link"),
        fired_at=payload.get("timestamp") or payload.get("fired_at"),
        resolved_at=payload.get("resolved_at"),
        downtime=payload.get("elapsed") or payload.get("downtime"),
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