# Plain-text template — used as fallback/mobile push text only.
# Primary Slack output uses Block Kit via build_alert_blocks() in formatters.py.

ALERT_TEMPLATE = """{header}

*Device:* {device}   *Severity:* {severity}{ip_line}{rule_line}{fired_line}{resolved_line}{downtime_line}{details_block}{link_line}"""

DETAILS_TEMPLATE = """

*Details:*
```{details}```"""
```

---

## `broker/app.py` — only the changed sections

Three targeted edits. Everything else stays identical.

**1. Change the import at the top** (around line 20):
```python
# BEFORE
from broker.formatters import format_slack_alert

# AFTER
from broker.formatters import format_slack_alert, build_alert_blocks
```

**2. Replace `build_slack_payload()`** (find the existing function and replace it entirely):
```python
def build_slack_payload(alert: NormalizedAlert, text: str, channel_id: str) -> dict:
    color = slack_color_for_text(text)
    fallback = slack_fallback_for_text(text)
    blocks = build_alert_blocks(alert)

    return {
        "channel": channel_id,
        "text": fallback,
        "attachments": [
            {
                "color": color,
                "blocks": blocks,
                "fallback": fallback,
            }
        ],
        "unfurl_links": False,
        "unfurl_media": False,
    }
```

**3. Update `post_to_slack()` and `update_slack_message()` signatures** to pass `alert` through:
```python
def post_to_slack(alert: NormalizedAlert, text: str, channel_id: str) -> dict:
    if not channel_id:
        raise RuntimeError("Missing Slack channel ID")
    payload = build_slack_payload(alert, text, channel_id)
    return slack_api_post("chat.postMessage", payload)


def update_slack_message(alert: NormalizedAlert, text: str, channel_id: str, ts: str) -> dict:
    if not channel_id:
        raise RuntimeError("Missing Slack channel ID")
    if not ts:
        raise RuntimeError("Missing Slack message timestamp")
    payload = build_slack_payload(alert, text, channel_id)
    payload["ts"] = ts
    return slack_api_post("chat.update", payload)
```

Then in `send_or_update_slack()`, update the two call sites to pass `alert`:
```python
# BEFORE
update_slack_message(text=text, channel_id=..., ts=...)
data = post_to_slack(text, channel_id)

# AFTER
update_slack_message(alert=alert, text=text, channel_id=..., ts=...)
data = post_to_slack(alert, text, channel_id)
```

Once you save, commit and push from the VS Code terminal, then `git pull` + service restart on raccoon.