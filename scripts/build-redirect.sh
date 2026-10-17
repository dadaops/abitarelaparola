#!/usr/bin/env bash
# Prepara una versione del sito che reindirizza ogni indirizzo verso il sito definitivo,
# mantenendo il percorso: .../abitarelaparola/assets/x.pdf -> https://www.abitarelaparola.it/assets/x.pdf
# Uso: scripts/build-redirect.sh https://www.abitarelaparola.it /abitarelaparola [cartella-di-uscita]
set -euo pipefail

target="${1:?Indica l’indirizzo del sito definitivo, per esempio https://abitarelaparola.it}"
base_path="${2-}"
out="${3:-_site}"
target="${target%/}"
base_path="${base_path%/}"
case "$target" in
  https://*) ;;
  *) echo "Indirizzo non valido: $target (deve iniziare con https://)" >&2; exit 1 ;;
esac
case "$out" in
  ""|"/"|".") echo "Cartella di uscita non valida: $out" >&2; exit 1 ;;
esac

rm -rf "$out"
mkdir -p "$out"

page() {
  cat <<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Abitare la parola si è trasferito</title>
<link rel="canonical" href="$target/">
<meta http-equiv="refresh" content="0; url=$target/">
<script>
(function () {
  var base = '$base_path';
  var path = location.pathname;
  if (base && path.indexOf(base) === 0) path = path.slice(base.length);
  location.replace('$target' + (path || '/') + location.search + location.hash);
})();
</script>
</head>
<body>
<p>Il sito di Abitare la parola è ora su <a href="$target/">$target</a>.</p>
</body>
</html>
HTML
}

page > "$out/index.html"
page > "$out/404.html"
echo "Reindirizzamento pronto in $out verso $target (percorso base: ${base_path:-/})"
