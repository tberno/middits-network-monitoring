#!/usr/bin/env bash
set -euo pipefail

NGINX_CONF="/etc/nginx/conf.d/librenms-ssl.conf"
GRAYLOG_CONF="/etc/graylog/server/server.conf"
TS="$(date +%Y%m%d-%H%M%S)"

echo "== Backing up configs =="
sudo cp -a "$NGINX_CONF" "${NGINX_CONF}.bak-${TS}"
sudo cp -a "$GRAYLOG_CONF" "${GRAYLOG_CONF}.bak-${TS}"

echo "== Rewriting Nginx librenms-ssl.conf =="
sudo tee "$NGINX_CONF" > /dev/null <<'NGINXEOF'
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name raccoon.middlebury.edu;

    root /opt/librenms/html;
    index index.php;

    ssl_certificate /etc/letsencrypt/raccoon.middlebury.edu_ecc/fullchain.cer;
    ssl_certificate_key /etc/letsencrypt/raccoon.middlebury.edu_ecc/raccoon.middlebury.edu.key;

    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    client_max_body_size 32m;

    access_log /var/log/nginx/librenms-ssl-access.log;
    error_log  /var/log/nginx/librenms-ssl-error.log;

    # Grafana via Traefik
    location = /views {
        return 301 /views/;
    }

    location ^~ /views/ {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port 443;

        proxy_redirect off;
    }

    # Graylog on subpath
    location ^~ /graylog/ {
        proxy_pass http://127.0.0.1:9000/;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port 443;
        proxy_set_header X-Forwarded-Prefix /graylog/;

        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";

        proxy_redirect off;
    }

    # LibreNMS
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location ~ /\.ht {
        deny all;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf)$ {
        expires 30d;
        access_log off;
        log_not_found off;
    }
}

server {
    listen 80;
    listen [::]:80;
    server_name raccoon.middlebury.edu;
    return 301 https://$host$request_uri;
}
NGINXEOF

echo "== Updating Graylog server.conf =="
sudo python3 - <<'PY'
from pathlib import Path
import re

path = Path("/etc/graylog/server/server.conf")
text = path.read_text()

desired = {
    "http_bind_address": "0.0.0.0:9000",
    "http_publish_uri": "https://raccoon.middlebury.edu/graylog/",
    "http_external_uri": "https://raccoon.middlebury.edu/graylog/",
    "http_enable_tls": "false",
    "http_trusted_proxies": "127.0.0.1",
}

for key, value in desired.items():
    pattern = rf'^[#\s]*{re.escape(key)}\s*=.*$'
    replacement = f"{key} = {value}"
    if re.search(pattern, text, flags=re.M):
        text = re.sub(pattern, replacement, text, flags=re.M)
    else:
        text += f"\n{replacement}\n"

path.write_text(text)
PY

echo "== Optional: disabling default SSL vhost if present =="
if [ -f /etc/nginx/conf.d/zzz-default-ssl.conf ]; then
    sudo mv /etc/nginx/conf.d/zzz-default-ssl.conf "/etc/nginx/conf.d/zzz-default-ssl.conf.disabled-${TS}"
fi

echo "== Testing nginx config =="
sudo nginx -t

echo "== Reloading nginx =="
sudo systemctl reload nginx

echo "== Restarting graylog-server =="
sudo systemctl restart graylog-server

echo "== Waiting 15 seconds =="
sleep 15

echo "== Quick checks =="
echo "-- Graylog local --"
curl -I http://127.0.0.1:9000/ || true
echo
echo "-- Nginx subpath --"
curl -k -I https://raccoon.middlebury.edu/graylog/ || true
echo
echo "Done."
echo "Backups:"
echo "  ${NGINX_CONF}.bak-${TS}"
echo "  ${GRAYLOG_CONF}.bak-${TS}"
