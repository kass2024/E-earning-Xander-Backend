#!/bin/bash
# Add ONE Apache vhost for xanderglobalacademy.com → Docker 127.0.0.1:8090
# Safe: does NOT touch /var/www DocumentRoots or other sites.
set -euo pipefail

PORT="${PARROT_HTTP_PORT:-8090}"
CONF="/etc/apache2/sites-available/xander-academy-elearning.conf"

echo "==> Existing /var/www (left unchanged):"
ls -la /var/www 2>/dev/null || true

sudo tee "$CONF" > /dev/null <<EOF
# Xander Global Academy e-learning — reverse proxy only
# Docker: 127.0.0.1:${PORT} — Apache keeps 80/443 for other /var/www sites
<VirtualHost *:80>
    ServerName xanderglobalacademy.com
    ServerAlias www.xanderglobalacademy.com api.xanderglobalacademy.com

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${PORT}/
    ProxyPassReverse / http://127.0.0.1:${PORT}/

    ErrorLog \${APACHE_LOG_DIR}/xander-academy-elearning-error.log
    CustomLog \${APACHE_LOG_DIR}/xander-academy-elearning-access.log combined
</VirtualHost>
EOF

sudo a2enmod proxy proxy_http headers rewrite
sudo a2ensite xander-academy-elearning.conf
sudo apache2ctl configtest
sudo systemctl reload apache2

echo "OK: only xander-academy-elearning.conf added. /var/www untouched."
echo "HTTPS: sudo certbot --apache -d xanderglobalacademy.com -d www.xanderglobalacademy.com -d api.xanderglobalacademy.com"
