# Plain-text template — used as fallback/mobile push text only.
# Primary Slack output uses Block Kit via build_alert_blocks() in formatters.py.

ALERT_TEMPLATE = """{header}

*Device:* {device}   *Severity:* {severity}{ip_line}{rule_line}{fired_line}{resolved_line}{downtime_line}{details_block}{link_line}"""

<<<<<<< Updated upstream
DETAILS_TEMPLATE = "\n\n*Details:*\n\x60\x60\x60{details}\x60\x60\x60"
=======
DETAILS_TEMPLATE = "\n\n*Details:*\n\x60\x60\x60{details}\x60\x60\x60"
>>>>>>> Stashed changes
