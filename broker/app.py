"""
Alert Broker Flask App

Purpose:
    Receives alerts from NMS/LibreNMS, Graylog, and Mist.
    Normalizes those source-specific payloads into a common NormalizedAlert.
    Formats the normalized alert using broker.formatters.format_slack_alert().
    Routes the alert to the correct Slack channel.
    Sends new alerts to Slack using chat.postMessage.
    Updates existing Slack messages on recovery using chat.update.

Main endpoints:
    GET  /health
    POST /webhook/nms
    POST /webhook/graylog
    POST /webhook/mist
"""

from flask import Flask, jsonify, request
import json
import os
import requests
import tempfile

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
#   SLACK_BOT_TOKEN       Bot token used for Slack chat.postMessage/chat.update.
#
# Channel variables:
#   SLACK_CHANNEL_ID      Default fallback channel.
#   SLACK_NMS_CHANNEL_ID  NMS/network/switch alerts.
#   SLACK_WIFI_CHANNEL_ID Mist WiFi/AP/client/wireless alerts.
#
# State:
#   STATEFILE             Optional path to JSON state file.
#                         Defaults to /var/lib/alert-broker/slack-state.json.
# -----------------------------------------------------------------------------

SLACK_BOT_TOKEN = os.getenv("SLACK_BOT_TOKEN")

# Default/fallback channel.
SLACK_CHANNEL_ID = os.getenv("SLACK_CHANNEL_ID")

# Specific destinations. If a specific variable is missing, fall back to default.
SLACK_NMS_CHANNEL_ID = os.getenv("SLACK_NMS_CHANNEL_ID") or SLACK_CHANNEL_ID
SLACK_WIFI_CHANNEL_ID = os.getenv("SLACK_WIFI_CHANNEL_ID") or SLACK_CHANNEL_ID

# Slack message state file. This stores alert keys -> Slack channel/timestamp.
STATE_FILE = os.getenv("STATEFILE") or "/var/lib/alert-broker/slack-state.json"


# -----------------------------------------------------------------------------
# Slack color selection
#
# Slack attachment colors:
#   "good"    = green
#   "danger"  = red
#   "#ff9900" = orange
#   "#1f6feb" = blue
#   "#808080" = gray
#
# This function looks at the final rendered alert text and chooses the side-bar
# color for the Slack attachment.
#
# Important:
#   WARNING must be checked before DOWN because some rule names contain text like
#   "UPS up/down". If DOWN is checked first, warning UPS alerts become red.
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
# We use the first line of the alert so phone/toast notifications are useful:
#   ALERT [NMS] Devices up / down
#
# To avoid duplicate display in-channel, slack_attachment_text() removes that
# same first line from the visible attachment body.
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
# This removes the first line from the attachment body while keeping the useful
# title in mobile/toast notifications.
# -----------------------------------------------------------------------------

def slack_attachment_text(text: str) -> str:
    lines = text.splitlines()

    if len(lines) <= 1:
        return text

    return "\n".join(lines[1:]).strip()


# -----------------------------------------------------------------------------
# Slack state storage
#
# Used to update the original Slack message when a resolved event arrives.
#
# Flow:
#   ALERT:
#       chat.postMessage
#       save returned Slack channel + ts in STATE_FILE
#
#   RESOLVED:
#       look up saved channel + ts
#       chat.update original message
#       remove saved state entry
#
# If no saved entry exists for a resolved event, we fall back to posting a new
# message so the recovery is not lost.
# -----------------------------------------------------------------------------

def load_state() -> dict:
    if not os.path.exists(STATE_FILE):
        return {}

    try:
        with open(STATE_FILE, "r") as f:
            return json.load(f)
    except Exception:
        # If the state file is corrupt/unreadable, do not crash alert delivery.
        return {}


def save_state(state: dict) -> None:
    state_dir = os.path.dirname(STATE_FILE)

    if state_dir:
        os.makedirs(state_dir, exist_ok=True)

    fd, tmp_path = tempfile.mkstemp(
        prefix="slack-state-",
        suffix=".json",
        dir=state_dir or None,
    )

    try:
        with os.fdopen(fd, "w") as f:
            json.dump(state, f, indent=2, sort_keys=True)
            f.write("\n")

        os.replace(tmp_path, STATE_FILE)
    finally:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)


def alert_state_key(alert: NormalizedAlert) -> str:
    """
    Build a stable key used to match an alert with its recovery.

    Important:
        For NMS/LibreNMS, do not use alert_id first. LibreNMS $uid can differ
        between the alert and recovery notification, which causes the recovery
        to post as a new Slack message instead of updating the original.

    NMS/LibreNMS:
        source + device + rule/summary

    Other sources:
        source + alert_id when available, otherwise source + device + rule/summary
    """
    source = (alert.source or "unknown").lower()

    device = str(alert.device or "unknown-device").strip().lower()
    rule = str(alert.rule or alert.summary or alert.event_type or "unknown-rule").strip().lower()

    if source == "nms":
        return "{}:device:{}:rule:{}".format(source, device, rule)

    if alert.alert_id:
        return "{}:id:{}".format(source, str(alert.alert_id).strip().lower())

    return "{}:device:{}:rule:{}".format(source, device, rule)


def is_resolved_alert(alert: NormalizedAlert) -> bool:
    return str(alert.state or "").lower() == "resolved"


# -----------------------------------------------------------------------------
# Slack payload builder
#
# Used by both chat.postMessage and chat.update so alert and resolved messages
# have the same formatting.
# -----------------------------------------------------------------------------

def build_slack_payload(text: str, channel_id: str) -> dict:
    color = slack_color_for_text(text)
    fallback = slack_fallback_for_text(text)
    attachment_text = slack_attachment_text(text)

    return {
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
    }


# -----------------------------------------------------------------------------
# Slack API helpers
# -----------------------------------------------------------------------------

def slack_api_post(method: str, payload: dict) -> dict:
    if not SLACK_BOT_TOKEN:
        raise RuntimeError("Missing SLACK_BOT_TOKEN")

    resp = requests.post(
        "https://slack.com/api/{}".format(method),
        headers={
            "Authorization": "Bearer {}".format(SLACK_BOT_TOKEN),
            "Content-Type": "application/json; charset=utf-8",
        },
        json=payload,
        timeout=10,
    )

    data = resp.json()

    if not data.get("ok"):
        raise RuntimeError("Slack API error from {}: {}".format(method, data))

    return data


def post_to_slack(text: str, channel_id: str) -> dict:
    if not channel_id:
        raise RuntimeError("Missing Slack channel ID")

    payload = build_slack_payload(text, channel_id)
    return slack_api_post("chat.postMessage", payload)


def update_slack_message(text: str, channel_id: str, ts: str) -> dict:
    if not channel_id:
        raise RuntimeError("Missing Slack channel ID")

    if not ts:
        raise RuntimeError("Missing Slack message timestamp")

    payload = build_slack_payload(text, channel_id)
    payload["ts"] = ts

    return slack_api_post("chat.update", payload)


def send_or_update_slack(alert: NormalizedAlert, text: str, channel_id: str) -> dict:
    """
    Send a new Slack message or update the original message on recovery.

    Returns a small result dict that gets included in the webhook JSON response.
    """
    key = alert_state_key(alert)
    state = load_state()

    # Recovery path: update original Slack message if we have saved state.
    if is_resolved_alert(alert):
        existing = state.get(key)

        if existing:
            update_slack_message(
                text=text,
                channel_id=existing.get("channel") or channel_id,
                ts=existing.get("ts"),
            )

            state.pop(key, None)
            save_state(state)

            return {
                "action": "updated",
                "state_key": key,
                "channel": existing.get("channel") or channel_id,
                "ts": existing.get("ts"),
            }

        # No state was found. Post the recovery as a fallback so it is not lost.
        data = post_to_slack(text, channel_id)

        return {
            "action": "posted_resolved_without_state",
            "state_key": key,
            "channel": data.get("channel") or channel_id,
            "ts": data.get("ts"),
        }

    # Alert path: post a new Slack message and save Slack channel/ts.
    data = post_to_slack(text, channel_id)

    state[key] = {
        "channel": data.get("channel") or channel_id,
        "ts": data.get("ts"),
    }
    save_state(state)

    return {
        "action": "posted",
        "state_key": key,
        "channel": state[key]["channel"],
        "ts": state[key]["ts"],
    }


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

    return "{} {} {}".format(source or "", text or "", payload_values).lower()


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
# NMS / LibreNMS helper functions
#
# LibreNMS may send hostname as an IP address depending on how the device is
# stored. For Slack display, we prefer sysName/sysname when it exists, then a
# non-IP hostname, then device/name, and only fall back to an IP if that is all
# we have.
# -----------------------------------------------------------------------------

def is_ip_like(value: str) -> bool:
    if not value:
        return False

    value = str(value).strip()

    # Simple IPv4 check. This is enough for deciding display name vs IP.
    parts = value.split(".")
    if len(parts) == 4 and all(part.isdigit() and 0 <= int(part) <= 255 for part in parts):
        return True

    return False


def best_device_name(payload: dict) -> str:
    """
    Pick the best display name for an NMS/LibreNMS alert.

    LibreNMS can sometimes send hostname as an IP address. Prefer sysName/sysname
    when present, then hostname if it is not just an IP, then device/name, then IP.
    """
    candidates = [
        payload.get("sysName"),
        payload.get("sysname"),
        payload.get("hostname"),
        payload.get("device"),
        payload.get("name"),
    ]

    # First pass: prefer real names over IP addresses.
    for value in candidates:
        if value and not is_ip_like(value):
            return str(value).strip()

    # Second pass: allow IP if that is all we have.
    for value in candidates:
        if value:
            return str(value).strip()

    return payload.get("ip") or "unknown-device"


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
        device=best_device_name(payload),
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
# Alert normalizer/renderer
#
# Picks the correct normalizer by source, then passes the normalized alert into
# broker.formatters.format_slack_alert().
#
# Returns both:
#   alert: normalized alert object used for state key/update behavior
#   text:  rendered Slack text
# -----------------------------------------------------------------------------

def normalize_and_render_alert(source: str, payload: dict):
    normalizers = {
        "graylog": normalize_graylog,
        "nms": normalize_nms,
        "mist": normalize_mist,
    }

    alert = normalizers[source](payload)
    text = format_slack_alert(alert)

    return alert, text


# -----------------------------------------------------------------------------
# Health endpoint
#
# Used by curl and monitoring to confirm the broker process is responding.
# -----------------------------------------------------------------------------

@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200


# -----------------------------------------------------------------------------
# Shared webhook processor
#
# All source-specific routes call this after reading their payload.
# -----------------------------------------------------------------------------

def process_webhook(source: str, payload: dict):
    alert, text = normalize_and_render_alert(source, payload)
    channel_id = slack_channel_for_alert(source, payload, text)
    slack_result = send_or_update_slack(alert, text, channel_id)

    return jsonify({
        "ok": True,
        "source": source,
        "channel": slack_result.get("channel") or channel_id,
        "slack_action": slack_result.get("action"),
        "state_key": slack_result.get("state_key"),
        "ts": slack_result.get("ts"),
        "text": text,
    }), 200


# -----------------------------------------------------------------------------
# Graylog webhook
#
# Flow:
#   1. Read JSON payload.
#   2. Normalize/render alert text.
#   3. Choose Slack channel.
#   4. Post new Slack alert or update original message on recovery.
#   5. Return JSON including chosen channel/action for troubleshooting.
# -----------------------------------------------------------------------------

@app.post("/webhook/graylog")
def webhook_graylog():
    payload = request.get_json(silent=True) or {}
    return process_webhook("graylog", payload)


# -----------------------------------------------------------------------------
# NMS / LibreNMS webhook
#
# Flow:
#   1. Read JSON if present; otherwise read form-encoded data.
#   2. Normalize/render alert text.
#   3. Route to NMS/network Slack channel.
#   4. Post new Slack alert or update original message on recovery.
#   5. Return JSON including chosen channel/action for troubleshooting.
# -----------------------------------------------------------------------------

@app.post("/webhook/nms")
def webhook_nms():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True) or {}
    return process_webhook("nms", payload)


# -----------------------------------------------------------------------------
# Mist webhook
#
# Flow:
#   1. Read JSON payload from Mist bot.
#   2. Normalize/render alert text.
#   3. Route WiFi/AP alerts to WiFi channel or switch/network alerts to NMS.
#   4. Post new Slack alert or update original message on recovery.
#   5. Return JSON including chosen channel/action for troubleshooting.
# -----------------------------------------------------------------------------

@app.post("/webhook/mist")
def webhook_mist():
    payload = request.get_json(silent=True) or {}
    return process_webhook("mist", payload)


# -----------------------------------------------------------------------------
# Local development entry point
#
# Production does not use this directly. Production runs:
#   gunicorn --bind 127.0.0.1:5052 --workers 2 --threads 4 broker.app:app
# -----------------------------------------------------------------------------

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5051, debug=False)