
ALERT_TEMPLATE = """{state_label} [{source}] {summary}
Device: {device}
Severity: {severity}{ip_line}{rule_line}{fired_line}{resolved_line}{downtime_line}{link_line}{details_block}"""

DETAILS_TEMPLATE = """
Details:
{details}
"""
