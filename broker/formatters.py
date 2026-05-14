
from broker.mappings import SEVERITY_TO_EMOJI, STATE_LABEL, SOURCE_LABEL
from broker.templates import ALERT_TEMPLATE, DETAILS_TEMPLATE


def _line(label: str, value):
    return f"\n{label}: {value}" if value else ""


def format_slack_alert(alert):
    severity_key = (alert.severity or "").lower()
    state_key = (alert.state or "").lower()

    emoji = SEVERITY_TO_EMOJI.get(
        "resolved" if state_key == "resolved" else severity_key,
        ":red_circle:",
    )
    state_label = STATE_LABEL.get(state_key, alert.state.upper())
    source_label = SOURCE_LABEL.get(alert.source.lower(), alert.source)

    details_block = ""
    if alert.details:
        details_block = DETAILS_TEMPLATE.format(details=alert.details.strip())

    return ALERT_TEMPLATE.format(
        emoji=emoji,
        state_label=state_label,
        source=source_label,
        summary=alert.summary,
        device=alert.device,
        severity=alert.severity.upper(),
        ip_line=_line("IP", alert.ip),
        rule_line=_line("Rule", alert.rule),
        fired_line=_line("Fired", alert.fired_at),
        resolved_line=_line("Resolved", alert.resolved_at),
        downtime_line=_line("Downtime", alert.downtime),
        link_line=_line("Link", alert.link),
        details_block=details_block,
    )
