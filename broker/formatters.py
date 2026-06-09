from broker.mappings import STATE_LABEL, SOURCE_LABEL, SEVERITY_TO_EMOJI
from broker.templates import ALERT_TEMPLATE, DETAILS_TEMPLATE


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


def _build_header(alert):
    state_label = STATE_LABEL.get((alert.state or "").lower(), (alert.state or "").upper())
    source_label = SOURCE_LABEL.get((alert.source or "").lower(), alert.source or "")
    device = _display(alert.device)
    rule = _display(alert.rule or alert.summary)

    if (alert.state or "").lower() == "resolved":
        emoji = SEVERITY_TO_EMOJI.get("resolved", ":large_green_circle:")
    else:
        emoji = SEVERITY_TO_EMOJI.get((alert.severity or "").lower(), ":black_circle:")

    if device and rule:
        header = f"{state_label} [{source_label}] {device} - {rule}"
    elif device:
        header = f"{state_label} [{source_label}] {device}"
    elif rule:
        header = f"{state_label} [{source_label}] {rule}"
    else:
        header = f"{state_label} [{source_label}] Alert"

    return f"{emoji}  {header}"


def build_alert_blocks(alert):
    """
    Build a Slack Block Kit blocks list from a NormalizedAlert.
    Used inside an attachment to retain the colored left border.
    """
    blocks = []

    # Header
    blocks.append({
        "type": "section",
        "text": {"type": "mrkdwn", "text": f"*{_build_header(alert)}*"},
    })

    blocks.append({"type": "divider"})

    # Device + Severity
    blocks.append({
        "type": "section",
        "fields": [
            {"type": "mrkdwn", "text": f"*Device*\n`{_display(alert.device) or 'unknown'}`"},
            {"type": "mrkdwn", "text": f"*Severity*\n`{(alert.severity or 'unknown').upper()}`"},
        ],
    })

    # IP + Rule
    meta_fields = []
    if alert.ip:
        meta_fields.append({"type": "mrkdwn", "text": f"*IP*\n`{alert.ip}`"})

    rule_val = _display(alert.rule or alert.summary)
    if rule_val:
        meta_fields.append({"type": "mrkdwn", "text": f"*Rule*\n`{rule_val}`"})

    if meta_fields:
        blocks.append({"type": "section", "fields": meta_fields})

    # Fired / Resolved / Duration
    time_fields = []
    if alert.fired_at:
        time_fields.append({"type": "mrkdwn", "text": f"*Fired*\n`{alert.fired_at}`"})
    if alert.resolved_at:
        time_fields.append({"type": "mrkdwn", "text": f"*Resolved*\n`{alert.resolved_at}`"})
    if alert.downtime:
        time_fields.append({"type": "mrkdwn", "text": f"*Duration*\n`{alert.downtime}`"})

    if time_fields:
        blocks.append({"type": "section", "fields": time_fields})

    # Details
    if alert.details:
        details = str(alert.details).strip()
        if details and details not in ("`", "``", "```"):
            truncated = details[:2800]
            if len(details) > 2800:
                truncated += "\n_(truncated)_"
            blocks.append({"type": "divider"})
            blocks.append({
                "type": "section",
                "text": {"type": "mrkdwn", "text": f"*Details*\n```{truncated}```"},
            })

    # View Alert button
    if alert.link:
        blocks.append({
            "type": "actions",
            "elements": [{
                "type": "button",
                "text": {"type": "plain_text", "text": "View Alert", "emoji": True},
                "url": alert.link,
                "action_id": "view_alert",
            }],
        })

    return blocks


def format_slack_alert(alert):
    """Plain-text fallback used for mobile push notifications."""
    details_block = ""

    if alert.details:
        details = str(alert.details).strip()
        if details and details not in ("`", "``", "```"):
            details_block = DETAILS_TEMPLATE.format(details=details)

    return ALERT_TEMPLATE.format(
        header=_build_header(alert),
        device=_inline_code(alert.device),
        severity=_inline_code((alert.severity or "").upper()),
        ip_line=_line("IP", _inline_code(alert.ip)),
        rule_line=_line("Rule", _inline_code(alert.rule or alert.summary)),
        fired_line=_line("Fired", _inline_code(alert.fired_at)),
        resolved_line=_line("Resolved", _inline_code(alert.resolved_at)),
        downtime_line=_line("Duration", _inline_code(alert.downtime)),
        details_block=details_block,
        link_line=_line("Link", alert.link),
    )


# Backward-compatible alias
formatslackalert = format_slack_alert