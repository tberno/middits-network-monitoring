from broker.mappings import STATE_LABEL, SOURCE_LABEL
from broker.templates import ALERT_TEMPLATE, DETAILS_TEMPLATE


def _line(label: str, value):
    return f"\n{label}: {value}" if value else ""


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
    """
    Keep the Slack top line clean.

    Example:
        ALERT [NMS] test-switch-name

    The rule/summary still appears in the body as Rule.
    """
    state_label = STATE_LABEL.get((alert.state or "").lower(), (alert.state or "").upper())
    source_label = SOURCE_LABEL.get((alert.source or "").lower(), alert.source or "")
    device = _display(alert.device)

    if device:
        return f"{state_label} [{source_label}] {device}"

    summary = _display(alert.summary)

    if summary:
        return f"{state_label} [{source_label}] {summary}"

    return f"{state_label} [{source_label}] Alert"


def formatslackalert(alert):
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
        downtime_line=_line("Downtime", _inline_code(alert.downtime)),
        details_block=details_block,

        # Keep link clickable and at the bottom.
        link_line=_line("Link", alert.link),
    )


# Backward-compatible name expected by broker/app.py
format_slack_alert = formatslackalert