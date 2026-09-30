#!/usr/bin/env bash
# Télécharge les librairies front imposées par la stack dans public/assets/vendor.
# Versions épinglées : toute montée de version passe par ce fichier.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VENDOR="$ROOT/public/assets/vendor"
FONTS="$ROOT/public/assets/fonts"
mkdir -p "$VENDOR" "$FONTS"

fetch() { # url destination
  echo "→ $2"
  curl -fsSL --retry 3 "$1" -o "$2"
}

fetch https://code.jquery.com/jquery-3.7.1.min.js                                          "$VENDOR/jquery.min.js"
fetch https://cdn.datatables.net/2.1.8/js/dataTables.min.js                                "$VENDOR/dataTables.min.js"
fetch https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css                   "$VENDOR/dataTables.min.css"
fetch https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js                    "$VENDOR/chart.umd.min.js"
fetch https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js                              "$VENDOR/lucide.min.js"
fetch https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js         "$VENDOR/sweetalert2.all.min.js"
fetch https://cdn.jsdelivr.net/npm/pdfmake@0.2.14/build/pdfmake.min.js                     "$VENDOR/pdfmake.min.js"
fetch https://cdn.jsdelivr.net/npm/pdfmake@0.2.14/build/vfs_fonts.js                       "$VENDOR/vfs_fonts.js"
fetch https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js                       "$VENDOR/exceljs.min.js"

for w in 400 500 600; do
  fetch "https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-sans@5.1.0/files/ibm-plex-sans-latin-$w-normal.woff2" \
        "$FONTS/ibm-plex-sans-latin-$w-normal.woff2"
done

echo "Assets front installés dans $VENDOR"
