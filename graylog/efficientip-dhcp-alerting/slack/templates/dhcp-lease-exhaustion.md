:red_circle: *ALERT | DHCP Lease Exhaustion*

*DHCP Server IP:* `${event.fields.dhcp_server_ip}`
*Graylog Sender IP:* `${event.fields.gl2_remote_ip}`
*DHCP Network Label:* `${event.fields.dhcp_network}`
*VM Interface:* `${event.fields.dhcp_interface}`
*Client MAC:* `${event.fields.dhcp_client_mac}`
*Action:* `${event.fields.dhcp_action}`
*Status:* `${event.fields.dhcp_status}`
*Priority:* `${event.priority}`
*Fired:* `${event.timestamp}`

*Message:*
`${event.message}`

${event.url}
