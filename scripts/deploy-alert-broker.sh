#!/usr/bin/env bash
set -euo pipefail

echo
echo "=== Alert Broker Deploy on raccoon ==="

REPO_DIR="$HOME/middits-network-monitoring"
BROKER_PROD_DIR="/opt/alert-broker/broker"
PYTHON_BIN="/opt/alert-broker/venv/bin/python"

cd "$REPO_DIR"

echo
echo "Pulling latest code from GitHub..."
git pull origin main

echo
echo "Copying broker files to production..."
sudo cp broker/app.py "$BROKER_PROD_DIR/app.py"
sudo cp broker/bot.py "$BROKER_PROD_DIR/bot.py"
sudo cp broker/formatters.py "$BROKER_PROD_DIR/formatters.py"
sudo cp broker/templates.py "$BROKER_PROD_DIR/templates.py"
sudo cp broker/mappings.py "$BROKER_PROD_DIR/mappings.py"
sudo cp broker/models.py "$BROKER_PROD_DIR/models.py"
sudo cp broker/requirements.txt "$BROKER_PROD_DIR/requirements.txt"

echo
echo "Fixing ownership..."
sudo chown -R alert-broker:alert-broker "$BROKER_PROD_DIR"

echo
echo "Compiling production Python files..."
sudo "$PYTHON_BIN" -m py_compile "$BROKER_PROD_DIR/app.py"
sudo "$PYTHON_BIN" -m py_compile "$BROKER_PROD_DIR/bot.py"
sudo "$PYTHON_BIN" -m py_compile "$BROKER_PROD_DIR/formatters.py"
sudo "$PYTHON_BIN" -m py_compile "$BROKER_PROD_DIR/templates.py"
sudo "$PYTHON_BIN" -m py_compile "$BROKER_PROD_DIR/mappings.py"
sudo "$PYTHON_BIN" -m py_compile "$BROKER_PROD_DIR/models.py"

echo
echo "Restarting alert broker services..."
sudo systemctl restart alert-broker

if systemctl list-unit-files | grep -q '^alert-broker-bot.service'; then
    sudo systemctl restart alert-broker-bot
fi

echo
echo "Checking health endpoint..."
curl -i http://127.0.0.1:5052/health

echo
echo "Checking service status..."
sudo systemctl status alert-broker --no-pager -l

if systemctl list-unit-files | grep -q '^alert-broker-bot.service'; then
    sudo systemctl status alert-broker-bot --no-pager -l
fi

echo
echo "Deploy complete."
