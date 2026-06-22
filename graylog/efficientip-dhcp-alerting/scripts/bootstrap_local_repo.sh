#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="${REPO_DIR:-$HOME/src/graylog-efficientip-dhcp-alerting}"

mkdir -p "$(dirname "$REPO_DIR")"
cp -a "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" "$REPO_DIR"

cd "$REPO_DIR"
git init
git branch -M main
git add .
git commit -m "Add Graylog EfficientIP DHCP lease exhaustion parser" || true

cat <<'MSG'

Local repo is ready.

To push to GitHub:
  cd "$REPO_DIR"
  git remote add origin git@github.com:YOUR_ORG/YOUR_REPO.git
  git push -u origin main

To apply to Graylog on raccoon:
  export GRAYLOG_URL="http://127.0.0.1:9000"
  export GRAYLOG_USER="YOUR_GRAYLOG_USER"
  export GRAYLOG_PASSWORD="YOUR_GRAYLOG_PASSWORD"
  ./scripts/apply_graylog_rule.py

MSG
