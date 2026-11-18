#!/bin/sh
# Submits the site's URLs to IndexNow (Bing, Yandex, Seznam and partners), so a deploy or a new
# public image is discovered without waiting for a crawl.
#
# Usage:
#   scripts/indexnow.sh                 # every URL in /sitemap.xml
#   scripts/indexnow.sh <url> [url...]  # specific URLs
#   BASE=https://imgbasket.com scripts/indexnow.sh
#
# The key file must be reachable at https://<host>/<key>.txt (it is, when it sits in public_html).
set -eu

BASE="${BASE:-https://imgbasket.com}"
HOST=$(printf '%s' "$BASE" | sed -E 's#^https?://##; s#/.*$##')

# The key file is the <32 hex>.txt in the repository root
KEY_FILE=$(ls "$(dirname "$0")"/../[0-9a-f][0-9a-f]*.txt 2>/dev/null | head -1 || true)
if [ -z "$KEY_FILE" ]; then
  echo "No IndexNow key file found in the repository root." >&2
  exit 1
fi
KEY=$(cat "$KEY_FILE")

urls=""
if [ "$#" -gt 0 ]; then
  for u in "$@"; do urls="$urls${urls:+,}\"$u\""; done
else
  sitemap=$(curl -fsS "$BASE/sitemap.xml")
  for u in $(printf '%s' "$sitemap" | grep -o '<loc>[^<]*</loc>' | sed -E 's#</?loc>##g'); do
    urls="$urls${urls:+,}\"$u\""
  done
fi

if [ -z "$urls" ]; then
  echo "No URLs to submit." >&2
  exit 1
fi

payload=$(printf '{"host":"%s","key":"%s","keyLocation":"%s/%s.txt","urlList":[%s]}' \
  "$HOST" "$KEY" "$BASE" "$KEY" "$urls")

echo "Submitting to IndexNow for $HOST…"
curl -fsS -X POST "https://api.indexnow.org/indexnow" \
  -H 'Content-Type: application/json; charset=utf-8' \
  --data-binary "$payload" \
  -w '\nHTTP %{http_code}\n'
