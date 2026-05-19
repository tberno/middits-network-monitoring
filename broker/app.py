"""
Alert Broker Flask App

Purpose:
    Receives alerts from NMS/LibreNMS, Graylog, and Mist.
    Normalizes those source-specific payloads into a common NormalizedAlert.
    Formats the normalized alert using broker.formatters.format_slack_alert().
    Routes the alert to the correct Slack channel.
    Sends the alert to Slack using chat.postMessage.

Main endpoints:
    GET  /health
    POST /webhook/nms
    POST /webhook/graylog
    POST /webhook/mist
"""

from flask import Flask, jsonify, request
import os
import requests

from broker.models import NormalizedAlert
from broker.formatters import format_slack_alert


# -----------------------------------------------------------------------------
# Flask app
# -----------------------------------------------------------------------------

app = Flask(__name__)


# -----------------------------------------------------------------------------
# Slack configuration
#
# These values come from /etc/alert-broker.env through systemd EnvironmentFile.
#
# Required:
#   SLACK_BOT_TOKEN       Bot token used for Slack chat.postMessage.
#
# Channel variables:
#   SLACK_CHANNEL_ID      Default fallback channel.
#   SLACK_NMS_CHANNEL_ID  NMS/network/switch alerts.
#   SLACK_WIFI_CHANNEL_ID Mist WiFi/AP/client/wireless alerts.
# -----------------------------------------------------------------------------

SLACK_BOT_TOKEN = os.getenv("SLACK_BOT_TOKEN")

# Default/fallback channel.
SLACK_CHANNEL_ID = os.getenv("SLACK_CHANNEL_ID")

# Specific destinations. If a specific variable is missing, fall back to default.
SLACK_NMS_CHANNEL_ID = os.getenv("SLACK_NMS_CHANNEL_ID") or SLACK_CHANNEL_ID
SLACK_WIFI_CHANNEL_ID = os.getenv("SLACK_WIFI_CHANNEL_ID") or SLACK_CHANNEL_ID


# -----------------------------------------------------------------------------
# Slack color selection
#
# Slack attachment colors:
#   "good"    = green
#   "danger"  = red
#   "warning" = yellow
#   "#1f6feb" = blue
#   "#808080" = gray
#
# This function looks at the final rendered alert text and chooses the side-bar
# color for the Slack attachment.
# -----------------------------------------------------------------------------

def slack_color_for_text(text: str) -> str:
    upper = text.upper()

    if "RESOLVED" in upper:
        return "good"

    if "WARNING" in upper or "WARN" in upper:
        return "#ff9900"

    if "INFO" in upper or "INFORMATIONAL" in upper:
        return "#1f6feb"

    if "CRITICAL" in upper or "DOWN" in upper:
        return "danger"

    return "#808080"

# -----------------------------------------------------------------------------
# Slack notification / fallback text
#
# Top-level Slack "text" is what mobile push notifications and desktop toast
# notifications use.
#
# We want this to be useful, so use the first line of the alert:
#   🔴 ALERT [NMS] Devices up / down
#
# Earlier we used "Network alert" to prevent duplicate display in Slack. That
# fixed duplication, but made phone notifications useless. The better approach is
# to use the title here, then remove that same first line from the attachment body
# using slack_attachment_text().
# -----------------------------------------------------------------------------

def slack_fallback_for_text(text: str) -> str:
    first_line = text.splitlines()[0].strip() if text else "Network alert"
    return first_line or "Network alert"


# -----------------------------------------------------------------------------
# Slack attachment body
#
# Slack displays top-level "text" above the attachment. If the attachment also
# contains the first line, the channel shows a duplicate title.
#
# This removes the first line from the attachment body while keeping the full
# useful title in mobile/toast notifications.
# -----------------------------------------------------------------------------

def slack_attachment_text(text: str) -> str:
    lines = text.splitlines()

    if len(lines) <= 1:
        return text

    return "\n".join(lines[1:]).strip()


# -----------------------------------------------------------------------------
# Routing helper: combine source + rendered alert + original payload
#
# Used by slack_channel_for_alert() to make a routing decision. We include the
# rendered text and every payload value so terms like "ap_down", "wireless",
# "switch", "gateway", "ssid", etc. can be matched.
# -----------------------------------------------------------------------------

def _combined_payload_text(source: str, payload: dict, text: str) -> str:
    payload_values = " ".join(
        str(value).lower()
        for value in payload.values()
        if value is not None
    )

    return f"{source or ''} {text or ''} {payload_values}".lower()


# -----------------------------------------------------------------------------
# Slack channel routing
#
# Rules:
#   - NMS/LibreNMS alerts go to the NMS/network channel.
#   - Graylog alerts go to the NMS/network channel.
#   - Mist AP/WiFi/client/wireless alerts go to the WiFi channel.
#   - Mist switch/routing/interface alerts go to the NMS/network channel.
#
# The Mist bot already sends useful fields like eventtype, device, summary,
# details, site, ip, mac, firedat, resolvedat, and link. We use those fields to
# decide where the alert should go.
# -----------------------------------------------------------------------------

def slack_channel_for_alert(source: str, payload: dict, text: str) -> str:
    source_key = (source or "").lower()
    combined = _combined_payload_text(source_key, payload, text)

    # NMS and Graylog are network/switch/server style alerts.
    if source_key in ("nms", "graylog"):
        return SLACK_NMS_CHANNEL_ID

    # Mist can produce both WiFi/AP alerts and switch/gateway alerts.
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

        # If it clearly looks like a switch/network event, send to NMS alerts.
        if any(term in combined for term in switch_terms):
            return SLACK_NMS_CHANNEL_ID

        # If it clearly looks like a WiFi/AP/client event, send to WiFi alerts.
        if any(term in combined for term in wifi_terms):
            return SLACK_WIFI_CHANNEL_ID

        # Default Mist to WiFi unless it clearly matches switch/network terms.
        return SLACK_WIFI_CHANNEL_ID

    # Unknown sources go to the default channel.
    return SLACK_CHANNEL_ID


# -----------------------------------------------------------------------------
# Slack sender
#
# Sends the final message to Slack using chat.postMessage.
#
# Important:
#   channel_id is passed in by the route after slack_channel_for_alert()
#   decides the correct destination.
# -----------------------------------------------------------------------------

def send_to_slack(text: str, channel_id: str):
    if not SLACK_BOT_TOKEN or not channel_id:
        raise RuntimeError("Missing SLACK_BOT_TOKEN or Slack channel ID")

    color = slack_color_for_text(text)
    fallback = slack_fallback_for_text(text)
    attachment_text = slack_attachment_text(text)

    resp = requests.post(
        "https://slack.com/api/chat.postMessage",
        headers={
            "Authorization": f"Bearer {SLACK_BOT_TOKEN}",
            "Content-Type": "application/json; charset=utf-8",
        },
        json={
            "channel": channel_id,

            # Used by phone/toast notifications.
            "text": fallback,

            # Visible Slack message body with colored side bar.
            "attachments": [
                {
                    "color": color,
                    "text": attachment_text,
                    "fallback": fallback,
                }
            ],

            # Prevent Slack from expanding URLs.
            "unfurl_links": False,
            "unfurl_media": False,
        },
        timeout=10,
    )

    data = resp.json()

    if not data.get("ok"):
        raise RuntimeError(f"Slack API error: {data}")


# -----------------------------------------------------------------------------
# Graylog normalizer
#
# Converts Graylog JSON into the common NormalizedAlert shape.
#
# Graylog field names can vary, so this accepts multiple aliases:
#   device / host / source
#   details / message
#   firedat / timestamp
#   link / url
# -----------------------------------------------------------------------------

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


# -----------------------------------------------------------------------------
# NMS / LibreNMS normalizer
#
# Converts LibreNMS form-encoded data into the common NormalizedAlert shape.
#
# LibreNMS state convention:
#   state=1 -> alert
#   anything else -> resolved
#
# Details:
#   Prefer a parsed "faults" list if present.
#   Otherwise use details= from the LibreNMS transport body.
# -----------------------------------------------------------------------------

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


# -----------------------------------------------------------------------------
# Mist normalizer
#
# Converts Mist bot JSON into the common NormalizedAlert shape.
#
# Mist bot already sends:
#   eventtype, state, severity, device, summary, details, alertid,
#   firedat, resolvedat, link, and metadata fields like site/ip/mac.
# -----------------------------------------------------------------------------

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


# -----------------------------------------------------------------------------
# Alert renderer
#
# Picks the correct normalizer by source, then passes the normalized alert into
# broker.formatters.format_slack_alert().
#
# This keeps rendering/formatting separate from app routing and HTTP handling.
# -----------------------------------------------------------------------------

def render_alert(source: str, payload: dict) -> str:
    normalizers = {
        "graylog": normalize_graylog,
        "nms": normalize_nms,
        "mist": normalize_mist,
    }

    alert = normalizers[source](payload)
    return format_slack_alert(alert)


# -----------------------------------------------------------------------------
# Health endpoint
#
# Used by curl and monitoring to confirm the broker process is responding.
# -----------------------------------------------------------------------------

@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200


# -----------------------------------------------------------------------------
# Graylog webhook
#
# Flow:
#   1. Read JSON payload.
#   2. Normalize/render alert text.
#   3. Choose Slack channel.
#   4. Send to Slack.
#   5. Return JSON including chosen channel for troubleshooting.
# -----------------------------------------------------------------------------

@app.post("/webhook/graylog")
def webhook_graylog():
    payload = request.get_json(silent=True) or {}
    text = render_alert("graylog", payload)
    channel_id = slack_channel_for_alert("graylog", payload, text)
    send_to_slack(text, channel_id)

    return jsonify({
        "ok": True,
        "source": "graylog",
        "channel": channel_id,
        "text": text,
    }), 200


# -----------------------------------------------------------------------------
# NMS / LibreNMS webhook
#
# Flow:
#   1. Read JSON if present; otherwise read form-encoded data.
#   2. Normalize/render alert text.
#   3. Route to NMS/network Slack channel.
#   4. Send to Slack.
#   5. Return JSON including chosen channel for troubleshooting.
# -----------------------------------------------------------------------------

@app.post("/webhook/nms")
def webhook_nms():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True) or {}
    text = render_alert("nms", payload)
    channel_id = slack_channel_for_alert("nms", payload, text)
    send_to_slack(text, channel_id)

    return jsonify({
        "ok": True,
        "source": "nms",
        "channel": channel_id,
        "text": text,
    }), 200


# -----------------------------------------------------------------------------
# Mist webhook
#
# Flow:
#   1. Read JSON payload from Mist bot.
#   2. Normalize/render alert text.
#   3. Route WiFi/AP alerts to WiFi channel or switch/network alerts to NMS.
#   4. Send to Slack.
#   5. Return JSON including chosen channel for troubleshooting.
# -----------------------------------------------------------------------------

@app.post("/webhook/mist")
def webhook_mist():
    payload = request.get_json(silent=True) or {}
    text = render_alert("mist", payload)
    channel_id = slack_channel_for_alert("mist", payload, text)
    send_to_slack(text, channel_id)

    return jsonify({
        "ok": True,
        "source": "mist",
        "channel": channel_id,
        "text": text,
    }), 200


# -----------------------------------------------------------------------------
# Local development entry point
#
# Production does not use this directly. Production runs:
#   gunicorn --bind 127.0.0.1:5052 --workers 2 --threads 4 broker.app:app
# -----------------------------------------------------------------------------

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5051, debug=False)