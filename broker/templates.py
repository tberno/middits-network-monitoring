ALERT_TEMPLATE = """{header}

Device: {device}
Severity: {severity}{ip_line}{rule_line}{fired_line}{resolved_line}{downtime_line}{details_block}{link_line}"""

DETAILS_TEMPLATE = """

Details:
{details}"""