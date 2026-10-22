#!/usr/bin/env bash
# Prepara il sito da pubblicare: copia site/ nella cartella di uscita e sostituisce
# __SITE_URL__ e __LASTMOD__ nelle pagine HTML, in robots.txt e in sitemap.xml.
# Uso: scripts/build.sh https://www.abitarelaparola.it [cartella-di-uscita]
set -euo pipefail

site_url="${1:?Indica l’indirizzo del sito, per esempio https://abitarelaparola.it}"
out="${2:-_site}"
site_url="${site_url%/}"
case "$site_url" in
  http://*|https://*) ;;
  *) echo "Indirizzo non valido: $site_url (deve iniziare con https://)" >&2; exit 1 ;;
esac
case "$out" in
  ""|"/"|".") echo "Cartella di uscita non valida: $out" >&2; exit 1 ;;
esac

root="$(cd "$(dirname "$0")/.." && pwd)"
lastmod="$(git -C "$root" log -1 --format=%cs -- site 2>/dev/null || true)"
lastmod="${lastmod:-$(date +%F)}"

rm -rf "$out"
mkdir -p "$out"
cp -R "$root/site/." "$out/"
find "$out" -name .DS_Store -delete

find "$out" -type f \( -name '*.html' -o -name robots.txt -o -name sitemap.xml \) | while read -r f; do
  sed -i.bak -e "s|__SITE_URL__|$site_url|g" -e "s|__LASTMOD__|$lastmod|g" "$f"
  rm "$f.bak"
done

if grep -rl -e __SITE_URL__ -e __LASTMOD__ "$out"; then
  echo "Segnaposto non sostituiti nei file qui sopra" >&2
  exit 1
fi
echo "Sito pronto in $out per $site_url"
