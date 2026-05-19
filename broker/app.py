from flask import Flask, jsonify, request
import os
import requests

from broker.models import NormalizedAlert
from broker.formatters import format_slack_alert

app = Flask(__name__)

SLACK_BOT_TOKEN = os.getenv("SLACK_BOT_TOKEN")

# Default/fallback channel.
SLACK_CHANNEL_ID = os.getenv("SLACK_CHANNEL_ID")

# Specific destinations.
SLACK_NMS_CHANNEL_ID = os.getenv("SLACK_NMS_CHANNEL_ID") or SLACK_CHANNEL_ID
SLACK_WIFI_CHANNEL_ID = os.getenv("SLACK_WIFI_CHANNEL_ID") or SLACK_CHANNEL_ID


def slack_color_for_text(text: str) -> str:
    upper = text.upper()

    if "RESOLVED" in upper:
        return "good"

    if "CRITICAL" in upper or "DOWN" in upper:
        return "danger"

    if "INFO" in upper or "INFORMATIONAL" in upper:
        return "#1f6feb"

    if "WARNING" in upper or "WARN" in upper:
        return "warning"

    return "#808080"


def slack_fallback_for_text(text: str) -> str:
    # Top-level Slack text is only for notification previews/accessibility.
    # Keep it generic so Slack does not visibly duplicate the attachment title.
    return "Network alert"


def _combined_payload_text(source: str, payload: dict, text: str) -> str:
    payload_values = " ".join(
        str(value).lower()
        for value in payload.values()
        if value is not None
    )
    return f"{source or ''} {text or ''} {payload_values}".lower()


def slack_channel_for_alert(source: str, payload: dict, text: str) -> str:
    """
    Route alerts to the correct Slack channel.

    Intended behavior:
      - NMS/LibreNMS alerts go to the NMS/network alerts channel.
      - Graylog alerts go to the NMS/network alerts channel.
      - Mist AP/WiFi/client/wireless alerts go to the WiFi alerts channel.
      - Mist switch/routing/interface alerts go to the NMS/network alerts channel.
    """
    source_key = (source or "").lower()
    combined = _combined_payload_text(source_key, payload, text)

    if source_key in ("nms", "graylog"):
        return SLACK_NMS_CHANNEL_ID

    if source_key == "mist":
        wifi_terms = [
            "wifi",
            "wi-fi",
            "wireless",
            "wlan",
            "ssid",
            "access point",
            "ap_down",
            "ap disconnected",
            "ap restarted",
            "radio",
            "radio_down",
            "client",
            "client disconnected",
            "device restarted",
        ]

        switch_terms = [
            "switch",
            "switch_down",
            "switch restarted",
            "gateway",
            "port",
            "interface",
            "bgp",
            "ospf",
            "lldp",
            "stp",
            "fabric-core",
        ]

        if any(term in combined for term in switch_terms):
            return SLACK_NMS_CHANNEL_ID

        if any(term in combined for term in wifi_terms):
            return SLACK_WIFI_CHANNEL_ID

        # Mist is primarily WiFi/AP unless the payload clearly looks like switch/network.
        return SLACK_WIFI_CHANNEL_ID

    return SLACK_CHANNEL_ID


def send_to_slack(text: str, channel_id: str):
    if not SLACK_BOT_TOKEN or not channel_id:
        raise RuntimeError("Missing SLACK_BOT_TOKEN or Slack channel ID")

    color = slack_color_for_text(text)
    fallback = slack_fallback_for_text(text)

    resp = requests.post(
        "https://slack.com/api/chat.postMessage",
        headers={
            "Authorization": f"Bearer {SLACK_BOT_TOKEN}",
            "Content-Type": "application/json; charset=utf-8",
        },
        json={
            "channel": channel_id,
            "text": fallback,
            "attachments": [
                {
                    "color": color,
                    "text": text,
                    "fallback": fallback,
                }
            ],
            "unfurl_links": False,
            "unfurl_media": False,
        },
        timeout=10,
    )

    data = resp.json()

    if not data.get("ok"):
        raise RuntimeError(f"Slack API error: {data}")


def normalize_graylog(payload: dict) -> NormalizedAlert:
    return NormalizedAlert(
        source="graylog",
        event_type=payload.get("eventtype", "graylog-event"),
        state=payload.get("state", "alert"),
        severity=payload.get("severity") or payload.get("priority") or "critical",
        device=payload.get("device") or payload.get("host") or payload.get("source") or "unknown-device",
        summary=payload.get("summary") or payload.get("title") or payload.get("eventtype") or "Graylog alert",
        details=payload.get("details") or payload.get("message") or "",
        alert_id=payload.get("alertid") or payload.get("id"),
        fired_at=payload.get("firedat") or payload.get("timestamp"),
        resolved_at=payload.get("resolvedat"),
        link=payload.get("link") or payload.get("url"),
        metadata=payload,
    )


def normalize_nms(payload: dict) -> NormalizedAlert:
    state_value = payload.get("state")
    state = "alert" if str(state_value) == "1" else "resolved"

    summary = payload.get("name") or payload.get("rule") or payload.get("title") or "NMS alert"

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
        device=payload.get("hostname") or payload.get("sysName") or payload.get("sysname") or payload.get("device", "unknown-device"),
        summary=summary,
        details=details,
        ip=payload.get("ip"),
        alert_id=payload.get("alertid") or payload.get("id"),
        rule=payload.get("rule"),
        fired_at=payload.get("timestamp") or payload.get("firedat"),
        resolved_at=payload.get("resolvedat"),
        downtime=payload.get("elapsed") or payload.get("downtime"),
        link=payload.get("link") or payload.get("url"),
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
    channel_id = slack_channel_for_alert("graylog", payload, text)
    send_to_slack(text, channel_id)
    return jsonify({"ok": True, "source": "graylog", "channel": channel_id, "text": text}), 200


@app.post("/webhook/nms")
def webhook_nms():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True) or {}
    text = render_alert("nms", payload)
    channel_id = slack_channel_for_alert("nms", payload, text)
    send_to_slack(text, channel_id)
    return jsonify({"ok": True, "source": "nms", "channel": channel_id, "text": text}), 200


@app.post("/webhook/mist")
def webhook_mist():
    payload = request.get_json(silent=True) or {}
    text = render_alert("mist", payload)
    channel_id = slack_channel_for_alert("mist", payload, text)
    send_to_slack(text, channel_id)
    return jsonify({"ok": True, "source": "mist", "channel": channel_id, "text": text}), 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5051, debug=False)