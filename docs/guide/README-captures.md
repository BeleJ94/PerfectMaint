# Captures d'écran du guide

Script : [`bin/screenshots.mjs`](../../bin/screenshots.mjs) (Playwright). Lecture seule : il se connecte avec les comptes de démonstration, visite les écrans, ouvre des boîtes de dialogue sans les valider.

## Première installation

```bash
npm install
npx playwright install chromium
```

## Produire les images

Prérequis : Apache et MariaDB démarrés, base peuplée (`composer seed` puis `composer seed:mining`).

```bash
npm run screenshots                               # FR + EN → docs/guide/images/fr|en/*.png
npm run screenshots -- --lang=fr                  # une seule langue
npm run screenshots -- --only=20,32               # captures dont l'identifiant commence par 20 ou 32
PM_URL=http://127.0.0.1:8080 npm run screenshots  # autre adresse (défaut http://localhost/perfectMaint)
```

Les images sont en résolution ×2 (nettes à l'impression). Bureau 1440×900, écrans terrain 390×844 (mobile).
Pour ajouter une capture : ajouter une entrée dans `SHOTS` avec un identifiant, puis la référencer dans [PLAN.md](PLAN.md).
