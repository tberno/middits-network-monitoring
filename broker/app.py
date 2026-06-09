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
import re
import requests
import tempfile

from broker.models import NormalizedAlert
from broker.formatters import format_slack_alert
from broker.eip_enrich import enrich_dhcp_message

# -----------------------------------------------------------------------------
# Flask app
# -----------------------------------------------------------------------------

app = Flask(__name__)

# -----------------------------------------------------------------------------
# Slack configuration
# -----------------------------------------------------------------------------

SLACK_BOT_TOKEN = os.getenv("SLACK_BOT_TOKEN")

# Default/fallback channel.
SLACK_CHANNEL_ID = os.getenv("SLACK_CHANNEL_ID")

# Specific destinations. If a specific variable is missing, fall back to default.
SLACK_NMS_CHANNEL_ID = os.getenv("SLACK_NMS_CHANNEL_ID") or SLACK_CHANNEL_ID
SLACK_WIFI_CHANNEL_ID = os.getenv("SLACK_WIFI_CHANNEL_ID") or SLACK_CHANNEL_ID
SLACK_DNS_CHANNEL_ID = os.getenv("DNS_SLACK_CHANNEL") or os.getenv("SLACK_DNS_CHANNEL_ID") or SLACK_CHANNEL_ID

# Slack message state file. This stores alert keys -> Slack channel/timestamp.
STATE_FILE = os.getenv("STATEFILE") or "/var/lib/alert-broker/slack-state.json"

# -----------------------------------------------------------------------------
# General helpers
# -----------------------------------------------------------------------------

def _first_nonempty(*values):
    """
    Return the first useful value from a list of possible values.

    This is used mostly for Graylog because different Graylog notification
    templates can send different field names.
    """
    for value in values:
        if value is None:
            continue

        cleaned = str(value).strip()

        # Treat empty strings and stray Slack formatting characters as empty.
        if cleaned and cleaned not in ("`", "``", "```"):
            return cleaned

    return None

# -----------------------------------------------------------------------------
# Slack color selection
# -----------------------------------------------------------------------------

def slack_color_for_text(text: str) -> str:
    upper = text.upper()

    if "RESOLVED" in upper:
        return "#2EB67D"

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
# notifications use. Keep this simple: just the first rendered line.
# -----------------------------------------------------------------------------

def slack_fallback_for_text(text: str) -> str:
    first_line = text.splitlines()[0].strip() if text else "Network alert"
    return first_line or "Network alert"

# -----------------------------------------------------------------------------
# Slack attachment body
# -----------------------------------------------------------------------------

def slack_attachment_text(text: str) -> str:
    lines = text.splitlines()

    if len(lines) <= 1:
        return text

    return "\n".join(lines[1:]).strip()

# -----------------------------------------------------------------------------
# Slack state storage
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
        # mrkdwn_in enables Slack formatting/backticks inside attachment text.
        "attachments": [
            {
                "color": color,
                "text": attachment_text,
                "fallback": fallback,
                "mrkdwn_in": ["text", "fallback"],
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
# Routing helper
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
# -----------------------------------------------------------------------------


def graylog_backlog_message(payload: dict) -> dict:
    """Return first useful Graylog backlog message object, if present."""
    backlog = payload.get("backlog") or payload.get("backlog_messages") or []
    if not isinstance(backlog, list):
        return {}

    for item in backlog:
        if not isinstance(item, dict):
            continue
        msg = item.get("message", item)
        if isinstance(msg, dict) and msg.get("message"):
            return msg

    return {}


def parse_bgp_details(message: str) -> dict:
    """Extract useful BGP peer transition fields from Junos-style syslog text."""
    if not message:
        return {}

    result = {}

    peer_match = re.search(
        r"BGP peer (?P<peer>\d+\.\d+\.\d+\.\d+) "
        r"\(External AS (?P<asn>\d+)\) changed state from "
        r"(?P<from_state>\S+) to (?P<to_state>\S+) "
        r"\(event (?P<event>[^)]+)\)",
        message,
    )

    if peer_match:
        result.update(peer_match.groupdict())
        return result

    notify_match = re.search(
        r"NOTIFICATION (?:sent to|received from) "
        r"(?P<peer>\d+\.\d+\.\d+\.\d+) "
        r"\(External AS (?P<asn>\d+)\): .*?(?P<event>BFD Down|Hard Reset|Cease|Connection Collision Resolution)",
        message,
    )

    if notify_match:
        result.update(notify_match.groupdict())

    return result


def enrich_graylog_bgp(payload: dict, device: str, summary: str, details: str):
    """Improve Graylog routing/BGP alerts using event/backlog message content."""
    event = payload.get("event") if isinstance(payload.get("event"), dict) else {}
    backlog_msg = graylog_backlog_message(payload)

    raw_message = (
        event.get("message")
        or backlog_msg.get("message")
        or payload.get("message")
        or payload.get("description")
        or ""
    )

    raw_source = (
        event.get("source")
        or backlog_msg.get("source")
        or payload.get("source")
        or payload.get("source_name")
        or device
    )

    if raw_source and device == "unknown-device":
        device = str(raw_source)

    bgp = parse_bgp_details(str(raw_message))

    if bgp:
        peer = bgp.get("peer")
        asn = bgp.get("asn")
        from_state = bgp.get("from_state")
        to_state = bgp.get("to_state")
        event_name = bgp.get("event")

        summary = f"BGP peer {peer} {from_state or ''}->{to_state or ''}".strip()

        detail_lines = [
            f"Router: {device}",
            f"Peer: {peer}",
            f"Remote AS: {asn}",
        ]

        if from_state or to_state:
            detail_lines.append(f"State: {from_state} -> {to_state}")

        if event_name:
            detail_lines.append(f"Event: {event_name}")

        if raw_message:
            detail_lines.append("")
            detail_lines.append(str(raw_message))

        details = "\n".join(detail_lines)

    elif raw_message and not details:
        details = str(raw_message)

    return device, summary, details

def normalize_graylog(payload: dict) -> NormalizedAlert:
    """
    Convert Graylog JSON into the common NormalizedAlert shape.

    Graylog notification templates vary a lot. This tries several common field
    names so we do not end up with unknown-device or empty details when Graylog
    sends event-specific keys.

    EfficientIP/DHCP payloads use: dhcp_network, dhcp_server_ip, alert_summary,
    alert_type, dhcp_normalized_message.

    EfficientIP enrichment: enrich_dhcp_message() is called before building the
    NormalizedAlert. It performs a cached REST API lookup to resolve dhcp_network
    (e.g. "default-netv4") into a real subnet CIDR (e.g. "10.32.4.0/22") and
    adds it as dhcp_subnet in the payload dict.
    """
    # Make a shallow copy so we do not mutate the original request payload,
    # then enrich with subnet info from the EfficientIP REST API (cached).
    payload = enrich_dhcp_message(dict(payload))

    device = _first_nonempty(
        payload.get("device"),
        payload.get("host"),
        payload.get("hostname"),
        payload.get("source"),
        payload.get("event_source"),
        payload.get("event.source"),
        payload.get("gl2_source_input"),
        payload.get("gl2_source_node"),
        payload.get("origin"),
        # EfficientIP / DHCP — prefer resolved subnet over pool name
        payload.get("dhcp_subnet"),
        payload.get("dhcp_network"),
        payload.get("dhcp_server_ip"),
    ) or "unknown-device"

    summary = _first_nonempty(
        payload.get("summary"),
        payload.get("title"),
        payload.get("event_definition_title"),
        payload.get("event_definition"),
        payload.get("eventtype"),
        payload.get("event_type"),
        payload.get("alert_title"),
        # EfficientIP / DHCP
        payload.get("alert_summary"),
        payload.get("alert_type"),
    ) or "Graylog alert"

    # Build base details from standard fields, falling back to DHCP message.
    _base_details = _first_nonempty(
        payload.get("details"),
        payload.get("message"),
        payload.get("event_message"),
        payload.get("description"),
        payload.get("alert_description"),
        payload.get("backlog_message"),
        payload.get("fields.message"),
        # EfficientIP / DHCP
        payload.get("dhcp_normalized_message"),
    ) or ""

    # Append resolved subnet CIDR to details so scope exhaustion alerts
    # show the actual network range, not just the pool name.
    _subnet = payload.get("dhcp_subnet")
    _network = payload.get("dhcp_network")

    if _subnet and _network and _subnet != _network:
        details = "{}\nSubnet: {} ({})".format(_base_details, _subnet, _network)
    elif _subnet:
        details = "{}\nSubnet: {}".format(_base_details, _subnet)
    else:
        details = _base_details

    severity = _first_nonempty(
        payload.get("severity"),
        payload.get("priority"),
        payload.get("event_priority"),
    ) or "critical"

    severity_map = {
        "1": "critical",
        "2": "critical",
        "3": "critical",
        "4": "warning",
        "5": "info",
    }

    severity = severity_map.get(str(severity).lower(), str(severity))

    event_fields = payload.get("event_fields") if isinstance(payload.get("event_fields"), dict) else {}
    fields = payload.get("fields") if isinstance(payload.get("fields"), dict) else {}

    graylog_state = _first_nonempty(
        payload.get("state"),
        payload.get("event_status"),
        event_fields.get("event_status"),
        event_fields.get("state"),
        fields.get("event_status"),
        fields.get("state"),
    ) or "alert"

    if str(graylog_state).lower() in ("resolved", "resolve", "closed", "ok"):
        state = "resolved"
    else:
        state = "alert"

    fired_at = _first_nonempty(
        payload.get("firedat"),
        payload.get("fired_at"),
        payload.get("event_fired_at"),
        event_fields.get("firedat"),
        event_fields.get("fired_at"),
        event_fields.get("event_fired_at"),
        fields.get("firedat"),
        fields.get("fired_at"),
        fields.get("event_fired_at"),
    )

    resolved_at = _first_nonempty(
        payload.get("resolvedat"),
        payload.get("resolved_at"),
        payload.get("event_resolved_at"),
        event_fields.get("resolvedat"),
        event_fields.get("resolved_at"),
        event_fields.get("event_resolved_at"),
        fields.get("resolvedat"),
        fields.get("resolved_at"),
        fields.get("event_resolved_at"),
    )

    event_timestamp = payload.get("timestamp") or payload.get("event_timestamp")

    if state == "resolved":
        resolved_at = resolved_at or event_timestamp
    else:
        fired_at = fired_at or event_timestamp

    duration = _first_nonempty(
        payload.get("duration"),
        payload.get("elapsed"),
        event_fields.get("duration"),
        event_fields.get("elapsed"),
        fields.get("duration"),
        fields.get("elapsed"),
    )

    if duration:
        payload["duration"] = duration
        payload["elapsed"] = duration

    device, summary, details = enrich_graylog_bgp(payload, device, summary, details)

    return NormalizedAlert(
        source="graylog",
        event_type=payload.get("eventtype") or payload.get("event_type") or payload.get("alert_type") or "graylog-event",
        state=state,
        severity=severity,
        device=device,
        summary=summary,
        details=details,
        ip=payload.get("dhcp_subnet"),
        alert_id=payload.get("alertid") or payload.get("id") or payload.get("event_id"),
        fired_at=fired_at,
        resolved_at=resolved_at,
        link=payload.get("link") or payload.get("url"),
        metadata=payload,
    )

# -----------------------------------------------------------------------------
# NMS / LibreNMS helper functions
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

    fired_at = payload.get("firedat")
    resolved_at = payload.get("resolvedat")

    # LibreNMS/NMS often sends only "timestamp" on both alert and recovery.
    # For alert state, timestamp means Fired.
    # For resolved state, timestamp means Resolved.
    if state == "alert":
        fired_at = fired_at or payload.get("timestamp")
    else:
        resolved_at = resolved_at or payload.get("timestamp")

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
        fired_at=fired_at,
        resolved_at=resolved_at,
        downtime=payload.get("elapsed") or payload.get("downtime"),
        link=payload.get("link") or payload.get("url"),
        metadata=payload,
    )

# -----------------------------------------------------------------------------
# Mist normalizer
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
# -----------------------------------------------------------------------------

@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200

# -----------------------------------------------------------------------------
# Shared webhook processor
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
# -----------------------------------------------------------------------------

@app.post("/webhook/graylog")
def webhook_graylog():
    payload = request.get_json(silent=True) or {}
    app.logger.info("GRAYLOG RAW PAYLOAD: %s", json.dumps(payload, default=str))
    return process_webhook("graylog", payload)

# -----------------------------------------------------------------------------
# NMS / LibreNMS webhook
# -----------------------------------------------------------------------------

@app.post("/webhook/nms")
def webhook_nms():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True) or {}
    return process_webhook("nms", payload)

# -----------------------------------------------------------------------------
# Mist webhook
# -----------------------------------------------------------------------------

@app.post("/webhook/mist")
def webhook_mist():
    payload = request.get_json(silent=True) or {}
    return process_webhook("mist", payload)

# -----------------------------------------------------------------------------
# Local development entry point
# -----------------------------------------------------------------------------

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5051, debug=False)

@app.route("/webhook/dns", methods=["POST"])
def webhook_dns():
    payload = request.get_json(silent=True) or request.form.to_dict(flat=True)

    title = payload.get("title") or payload.get("summary") or "DNS update"
    source = payload.get("source") or "SOLIDserver / Graylog"
    severity = (payload.get("severity") or "info").upper()
    details = payload.get("details") or payload.get("message") or ""
    link = payload.get("url") or payload.get("link") or ""

    text = f":large_blue_circle:  DNS UPDATE - {title}"

    fields = [
        {"type": "mrkdwn", "text": f"*Source:*\n`{source}`"},
        {"type": "mrkdwn", "text": f"*Severity:*\n`{severity}`"},
    ]

    blocks = [
        {
            "type": "section",
            "text": {"type": "mrkdwn", "text": text},
        },
        {
            "type": "section",
            "fields": fields,
        },
    ]

    if details:
        blocks.append({
            "type": "section",
            "text": {"type": "mrkdwn", "text": f"*Details:*\n```{details}```"},
        })

    if link:
        blocks.append({
            "type": "section",
            "text": {"type": "mrkdwn", "text": f"*Link:* {link}"},
        })

    result = slack_api_post("chat.postMessage", {
        "channel": SLACK_DNS_CHANNEL_ID,
        "text": text,
        "blocks": blocks,
    })

    return jsonify({
        "ok": bool(result.get("ok")),
        "source": "dns",
        "channel": SLACK_DNS_CHANNEL_ID,
        "slack_response": result,
    })

