#!/bin/bash
# Apache vhost for meet.xandertech.llc → Docker 127.0.0.1:8091
# Does NOT touch existing /var/www sites or other vhosts.
set -euo pipefail

PORT="${MEET_HTTP_PORT:-8190}"
CONF="/etc/apache2/sites-available/xander-meet.conf"

sudo tee "$CONF" > /dev/null <<EOF
# Xander Meet — reverse proxy only (meet.xandertech.llc)
<VirtualHost *:80>
    ServerName meet.xandertech.llc
    ServerAlias www.meet.xandertech.llc api.meet.xandertech.llc

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${PORT}/
    ProxyPassReverse / http://127.0.0.1:${PORT}/

    ErrorLog \${APACHE_LOG_DIR}/xander-meet-error.log
    CustomLog \${APACHE_LOG_DIR}/xander-meet-access.log combined
</VirtualHost>
EOF

sudo a2enmod proxy proxy_http headers rewrite 2>/dev/null || true
sudo a2ensite xander-meet.conf
sudo apache2ctl configtest
sudo systemctl reload apache2

echo "OK: xander-meet.conf added on port ${PORT}. Existing sites untouched."
echo "HTTPS: sudo certbot --apache -d meet.xandertech.llc -d www.meet.xandertech.llc -d api.meet.xandertech.llc"
