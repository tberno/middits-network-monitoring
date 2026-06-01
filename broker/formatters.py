from broker.mappings import SEVERITY_TO_EMOJI, STATE_LABEL, SOURCE_LABEL
from broker.templates import ALERT_TEMPLATE, DETAILS_TEMPLATE


def _code_value(value):
    """
    Format values in Slack inline-code style.

    This makes the right-side field values use Slack's code-style font,
    similar to the native Graylog alert formatting.
    """
    if not value:
        return ""

    cleaned = str(value).strip()

    # Treat empty strings and stray formatting characters as empty.
    if not cleaned or cleaned in ("`", "``", "```"):
        return ""

    # Avoid breaking Slack inline-code formatting if the value contains backticks.
    cleaned = cleaned.replace("`", "'")

    return f"`{cleaned}`"


def _line(label: str, value, code: bool = True):
    if not value:
        return ""

    rendered_value = _code_value(value) if code else value

    if not rendered_value:
        return ""

    return f"\n{label}: {rendered_value}"


def formatslackalert(alert):
    severity_key = (alert.severity or "").lower()
    state_key = (alert.state or "").lower()

    emoji = SEVERITY_TO_EMOJI.get(
        "resolved" if state_key == "resolved" else severity_key,
        ":red_circle:",
    )
    state_label = STATE_LABEL.get(state_key, alert.state.upper())
    source_label = SOURCE_LABEL.get(alert.source.lower(), alert.source)

    details_block = ""
    coded_details = _code_value(alert.details)

    if coded_details:
        details_block = DETAILS_TEMPLATE.format(details=coded_details)

    return ALERT_TEMPLATE.format(
        emoji=emoji,
        state_label=state_label,
        source=source_label,
        summary=alert.summary,

        # Right-side values formatted as Slack inline code.
        device=_code_value(alert.device),
        severity=_code_value(alert.severity.upper()),

        details_block=details_block,
        ip_line=_line("IP", alert.ip),
        rule_line=_line("Rule", alert.rule),
        fired_line=_line("Fired", alert.fired_at),
        resolved_line=_line("Resolved", alert.resolved_at),
        downtime_line=_line("Downtime", alert.downtime),

        # Keep links clickable instead of wrapping them in code.
        link_line=_line("Link", alert.link, code=False),
    )


# Backward-compatible name expected by broker/app.py
format_slack_alert = formatslackalert