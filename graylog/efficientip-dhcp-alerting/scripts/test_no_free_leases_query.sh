#!/usr/bin/env bash
set -euo pipefail

OPENSEARCH_URL="${OPENSEARCH_URL:-http://127.0.0.1:9200}"

cat > /tmp/no_free_query.json <<'EOF'
{
  "size": 5,
  "sort": [
    {
      "timestamp": {
        "order": "desc"
      }
    }
  ],
  "_source": [
    "timestamp",
    "source",
    "message",
    "full_message",
    "gl2_remote_ip",
    "gl2_remote_port",
    "facility",
    "level",
    "application_name",
    "process_id",
    "dhcp_pid",
    "dhcp_action",
    "dhcp_network",
    "dhcp_interface",
    "dhcp_client_mac",
    "dhcp_status",
    "dhcp_process",
    "dhcp_server_ip",
    "dhcp_normalized_message",
    "alert_type",
    "alert_service",
    "alert_summary"
  ],
  "query": {
    "match_phrase": {
      "message": "no free leases"
    }
  }
}
EOF

curl -sS -H 'Content-Type: application/json' \
  "${OPENSEARCH_URL}/graylog_*/_search?filter_path=hits.hits._source" \
  -d @/tmp/no_free_query.json | python3 -m json.tool
