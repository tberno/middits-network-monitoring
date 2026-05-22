"""
Alert Broker Slack Bot

Runs in Slack Socket Mode.

This process does not expose any inbound HTTP endpoint. It opens an outbound
connection to Slack and listens for bot mentions such as:

    @NMS-Alert-Bot help
    @NMS-Alert-Bot status
    @NMS-Alert-Bot state
"""

import json
import os
import subprocess

from slack_bolt import App
from slack_bolt.adapter.socket_mode import SocketModeHandler


SLACK_BOT_TOKEN = os.getenv("SLACK_BOT_TOKEN")
SLACK_APP_TOKEN = os.getenv("SLACK_APP_TOKEN")
STATE_FILE = os.getenv("STATEFILE") or "/var/lib/alert-broker/slack-state.json"


app = App(token=SLACK_BOT_TOKEN)


def load_state() -> dict:
    if not os.path.exists(STATE_FILE):
        return {}

    try:
        with open(STATE_FILE, "r") as f:
            return json.load(f)
    except Exception:
        return {}


def service_status(service_name: str) -> str:
    try:
        result = subprocess.run(
            ["systemctl", "is-active", service_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            universal_newlines=True,
            timeout=5,
        )
        return result.stdout.strip() or "unknown"
    except Exception:
        return "unknown"


def broker_status_text() -> str:
    state = load_state()

    return "\n".join([
        "*Alert Broker Status*",
        "Alert broker service: `{}`".format(service_status("alert-broker")),
        "Bot listener service: `{}`".format(service_status("alert-broker-bot")),
        "State file: `{}`".format(STATE_FILE),
        "Tracked open alerts: `{}`".format(len(state)),
        "Bot mode: `Socket Mode`",
    ])


def broker_state_text() -> str:
    state = load_state()

    if not state:
        return "*Alert Broker State*\nNo currently tracked Slack alerts."

    lines = [
        "*Alert Broker State*",
        "Currently tracked Slack alerts:",
    ]

    for key, value in sorted(state.items()):
        channel = value.get("channel", "unknown-channel")
        ts = value.get("ts", "unknown-ts")
        lines.append("- `{}` → channel `{}`, ts `{}`".format(key, channel, ts))

    return "\n".join(lines)


def broker_help_text() -> str:
    return "\n".join([
        "*Alert Broker Bot Commands*",
        "`@NMS-Alert-Bot status` - show broker health and tracked alert count",
        "`@NMS-Alert-Bot state` - show currently tracked Slack alert updates",
        "`@NMS-Alert-Bot help` - show this help message",
    ])


def handle_command(text: str) -> str:
    cleaned = (text or "").strip().lower()

    # Slack mention text starts with something like <@U123ABC>.
    parts = cleaned.split(maxsplit=1)
    command = parts[1] if len(parts) > 1 else "help"
    command = command.strip()

    if command in ("", "help", "-h", "--help"):
        return broker_help_text()

    if command == "status":
        return broker_status_text()

    if command == "state":
        return broker_state_text()

    return "Unknown command `{}`.\n\n{}".format(command, broker_help_text())


@app.event("app_mention")
def handle_app_mention(event, say):
    response = handle_command(event.get("text", ""))

    say(
        text=response,
        thread_ts=event.get("ts"),
    )


if __name__ == "__main__":
    if not SLACK_BOT_TOKEN:
        raise SystemExit("Missing SLACK_BOT_TOKEN")

    if not SLACK_APP_TOKEN:
        raise SystemExit("Missing SLACK_APP_TOKEN")

    SocketModeHandler(app, SLACK_APP_TOKEN).start()