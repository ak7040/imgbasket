#!/bin/sh
# Creates imgbasket.zip: everything to extract into public_html on the host.
# Leaves out app/config.php and app/storage/, so updates never overwrite them.
cd "$(dirname "$0")/.." || exit 1
rm -f imgbasket.zip
zip -rq imgbasket.zip .htaccess .user.ini index.php site.css site.js icons.js favicon.svg \
  apple-touch-icon.png icon-192.png icon-512.png og-image.png site.webmanifest assets app \
  -x 'app/config.php' 'app/storage/*' '*.DS_Store'
echo "Created imgbasket.zip"

# The two bridge files for an admin subdomain that has its own folder (see README)
rm -f admin-subdomain.zip
(cd extras/admin-subdomain && zip -q ../../admin-subdomain.zip index.php .htaccess)
echo "Created admin-subdomain.zip"
