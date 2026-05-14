import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from broker.app import render_alert

SAMPLES = ROOT / "tests" / "samples"


def load(name):
    with open(SAMPLES / name, "r", encoding="utf-8") as f:
        return json.load(f)


def main():
    for source, filename in [
        ("graylog", "graylog.json"),
        ("nms", "nms.json"),
        ("mist", "mist.json"),
    ]:
        payload = load(filename)
        print("=" * 80)
        print(render_alert(source, payload))
        print()


if __name__ == "__main__":
    main()