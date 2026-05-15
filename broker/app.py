from flask import Flask, jsonify, request

from broker.models import NormalizedAlert
from broker.formatters import format_slack_alert

app = Flask(__name__)


def normalize_graylog(payload: dict) -> NormalizedAlert:
    return NormalizedAlert(
        source="graylog",
        event_type=payload.get("eventtype", "graylog-event"),
        state=payload.get("state", "alert"),
        severity=payload.get("severity", "critical"),
        device=payload.get("device", "unknown-device"),
        summary=payload.get("summary", "Graylog alert"),
        details=payload.get("message", ""),
        alert_id=payload.get("alertid"),
        fired_at=payload.get("firedat"),
        resolved_at=payload.get("resolvedat"),
        link=payload.get("link"),
        metadata=payload,
    )


def normalize_nms(payload: dict) -> NormalizedAlert:
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
        event_type=payload.get("eventtype", "nms-event"),
        state=state,
        severity=payload.get("severity", "critical"),
        device=payload.get("hostname") or payload.get("device", "unknown-device"),
        summary=summary,
        details=details,
        ip=payload.get("ip"),
        alert_id=payload.get("alertid") or payload.get("id"),
        rule=payload.get("rule"),
        fired_at=payload.get("timestamp") or payload.get("firedat"),
        resolved_at=payload.get("resolvedat"),
        downtime=payload.get("elapsed") or payload.get("downtime"),
        link=payload.get("link"),
        metadata=payload,
    )


def normalize_mist(payload: dict) -> NormalizedAlert:
    return NormalizedAlert(
        source="mist",
        event_type=payload.get("eventtype", "mist-event"),
        state=payload.get("state", "resolved"),
        severity=payload.get("severity", "ok"),
        device=payload.get("device", "unknown-device"),
        summary=payload.get("summary", "Mist alert"),
        details=payload.get("details", ""),
        alert_id=payload.get("alertid"),
        fired_at=payload.get("firedat"),
        resolved_at=payload.get("resolvedat"),
        link=payload.get("link"),
        metadata=payload,
    )


def render_alert(source: str, payload: dict) -> str:
    normalizers = {
        "graylog": normalize_graylog,
        "nms": normalize_nms,
        "mist": normalize_mist,
    }
    alert = normalizers[source](payload)
    return format_slack_alert(alert)


@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200


@app.post("/webhook/graylog")
def webhook_graylog():
    payload = request.get_json(silent=True) or {}
    text = render_alert("graylog", payload)
    return jsonify({"ok": True, "source": "graylog", "text": text}), 200


@app.post("/webhook/nms")
def webhook_nms():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True) or {}
    text = render_alert("nms", payload)
    return jsonify({"ok": True, "source": "nms", "text": text}), 200


@app.post("/webhook/mist")
def webhook_mist():
    payload = request.get_json(silent=True) or {}
    text = render_alert("mist", payload)
    return jsonify({"ok": True, "source": "mist", "text": text}), 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5051, debug=False)