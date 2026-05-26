# LibreNMS Oxidized Download Config Button

Adds a LibreNMS device-page action called Download Config.

The button downloads the current Oxidized config as a .txt file.

Architecture:

LibreNMS device page
  -> Download Config device action
  -> https://raccoon.middlebury.edu/oxidized-download/<group>/<hostname>
  -> nginx reverse proxy
  -> http://127.0.0.1:8888/node/fetch/<group>/<hostname>
  -> browser downloads <hostname>.txt

Confirmed values:

LibreNMS path:        /opt/librenms
Nginx SSL config:     /etc/nginx/conf.d/librenms-ssl.conf
Oxidized URL:         http://127.0.0.1:8888
Oxidized group mode:  enabled
Download extension:   .txt

Known working groups:

junos
panos
arubaos-cx

Nginx location:

location ~ ^/oxidized-download/(?<group>[^/]+)/(?<node>[^/]+)$ {
    proxy_pass http://127.0.0.1:8888/node/fetch/$group/$node;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    default_type text/plain;
    add_header Content-Disposition "attachment; filename=$node.txt" always;
}

LibreNMS device link:

{
  "url": "https://raccoon.middlebury.edu/oxidized-download/{{ $device->os }}/{{ rawurlencode($device->hostname) }}",
  "title": "Download Config",
  "icon": "fa-download",
  "external": true,
  "action": true
}

Verify:

curl -k -I "https://raccoon.middlebury.edu/oxidized-download/junos/140.233.100.10"

Expected:

HTTP/1.1 200 OK
Content-Disposition: attachment; filename=140.233.100.10.txt

Check LibreNMS custom links:

cd /opt/librenms
sudo -u librenms ./lnms config:get html.device.links

Notes:

Do not commit the full live nginx config from /etc/nginx/conf.d/librenms-ssl.conf.
That file can contain unrelated internal routes or secrets.
