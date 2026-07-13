from broker.mappings import STATE_LABEL, SEVERITY_TO_EMOJI
from broker.templates import ALERT_TEMPLATE, DETAILS_TEMPLATE
import os


def _line(label: str, value):
    return f"\n*{label}:* {value}" if value else ""


def _inline_code(value):
    if value is None:
        return ""

    value = str(value).strip()

    if not value or value in ("`", "``", "```"):
        return ""

    value = value.replace("`", "'")

    return f"`{value}`"


def _display(value):
    if value is None:
        return ""

    return str(value).strip()


def _rule_value(alert):
    rule = _display(alert.rule)
    summary = _display(alert.summary)

    if not rule or rule == summary:
        return ""

    return rule


def _build_header(alert):
    state_label = STATE_LABEL.get((alert.state or "").lower(), (alert.state or "").upper())
    device = _display(alert.device)
    summary = _display(alert.summary) or _rule_value(alert) or "Alert"

    if (alert.state or "").lower() == "resolved":
        emoji = SEVERITY_TO_EMOJI.get("resolved", ":large_green_circle:")
    else:
        emoji = SEVERITY_TO_EMOJI.get((alert.severity or "").lower(), ":black_circle:")

    if device and summary:
        header = f"{state_label} {device} - {summary}"
    elif device:
        header = f"{state_label} {device}"
    elif summary:
        header = f"{state_label} {summary}"
    else:
        header = f"{state_label} Alert"

    return f"{emoji}  {header}"




def build_alert_blocks(alert):
    """Build mobile-friendly Slack blocks.

    Top line is compact for phone notifications:
      red dot / green dot + device + down/up

    Details are stacked vertically underneath. No Slack fields.
    """
    state = (getattr(alert, "state", "") or "").lower()
    is_resolved = state in ("resolved", "ok", "up", "clear", "cleared")

    device = _display(getattr(alert, "device", "")) or "unknown-device"
    severity = (_display(getattr(alert, "severity", "")) or "unknown").upper()
    ip = _display(getattr(alert, "ip", "")) or ""
    fired_at = _display(getattr(alert, "fired_at", "")) or ""
    resolved_at = _display(getattr(alert, "resolved_at", "")) or ""
    downtime = _display(getattr(alert, "downtime", "")) or ""
    link = _display(getattr(alert, "link", "")) or ""

    if is_resolved:
        icon = _status_icon(True)
        status_word = "got back up"
        status_label = "UP"
    else:
        icon = _status_icon(False)
        status_word = "needs attention"
        status_label = "DOWN"

    title = f"{icon} {device} {status_word}"

    lines = [
        f"*Device:* `{device}`",
        f"*Status:* `{status_label}`",
        f"*Severity:* `{severity}`",
    ]

    if ip:
        lines.append(f"*IP:* `{ip}`")
    if fired_at:
        lines.append(f"*Fired:* `{fired_at}`")
    if resolved_at:
        lines.append(f"*Resolved:* `{resolved_at}`")
    if downtime:
        lines.append(f"*Duration:* `{downtime}`")
    if link:
        lines.append(f"*Link:* {link}")

    blocks = [
        {
            "type": "section",
            "text": {"type": "mrkdwn", "text": "\n".join(lines)},
        },
    ]

    details = _display(getattr(alert, "details", "")) or ""
    if details:
        truncated = details[:2500]
        blocks.append({
            "type": "section",
            "text": {"type": "mrkdwn", "text": f"*Details:*\n```{truncated}```"},
        })

    if link:
        blocks.append({
            "type": "actions",
            "elements": [
                {
                    "type": "button",
                    "text": {"type": "plain_text", "text": "View Alert", "emoji": True},
                    "url": link,
                }
            ],
        })

    return blocks






def _status_icon(is_resolved: bool) -> str:
    """Return Slack status icon.

    Defaults are intentionally visible/fun:
      up:   🎉
      down: :warning:

    Can be overridden with:
      SLACK_ALERT_UP_ICON=:alert_up:
      SLACK_ALERT_DOWN_ICON=:alert_down:
    """
    if is_resolved:
        return os.environ.get("SLACK_ALERT_UP_ICON", "🎉")
    return os.environ.get("SLACK_ALERT_DOWN_ICON", ":red_circle:")





def format_slack_alert(alert):
    """Compact top-level Slack fallback text."""
    state = (getattr(alert, "state", "") or "").lower()
    severity = (getattr(alert, "severity", "") or "unknown").upper()
    device = _display(getattr(alert, "device", "")) or "unknown-device"

    is_resolved = state in ("resolved", "ok", "up", "clear", "cleared")
    is_warning = severity in ("WARNING", "WARN", "HIGH", "MINOR", "NOTICE", "INFO", "INFORMATIONAL")

    if is_resolved:
        return f":large_green_circle: {device} recovered"

    if is_warning:
        return f":warning: {device} needs attention"

    return f":red_circle: {device} down"


def build_alert_attachments(alert):
    """Wrap mobile-friendly blocks in a Slack attachment for color bar."""
    state = (getattr(alert, "state", "") or "").lower()
    is_resolved = state in ("resolved", "ok", "up", "clear", "cleared")
    severity = (_display(getattr(alert, "severity", "")) or "unknown").lower()

    if is_resolved:
        color = "#39FF14"   # neon green
    elif severity in ("warning", "warn", "high", "minor"):
        color = "#FF9F1C"   # neon orange
    else:
        color = "#FF3131"   # neon red

    return [{
        "color": color,
        "fallback": format_slack_alert(alert),
        "blocks": build_alert_blocks(alert),
    }]


