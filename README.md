# Abitare la parola

Landing page del corso di formazione «Abitare la parola» (Palermo, 7–8 novembre 2026).

Sito statico pubblicato su GitHub Pages dal workflow `.github/workflows/pages.yml` a ogni push su `main`.
Il workflow sostituisce il segnaposto `__SITE_URL__` (in `index.html`, `robots.txt` e `sitemap.xml`) con l'indirizzo
reale del sito, quindi non va scritto a mano. Vengono pubblicati solo `index.html`, `assets/`, `robots.txt` e `sitemap.xml`.

Indirizzo attuale: https://dadaops.dev/abitarelaparola/

## Passaggio al dominio abitarelaparola.it

1. Dal pannello del registrar, imposta i DNS:

   | Nome  | Tipo  | Valore |
   |-------|-------|--------|
   | `@`   | A     | `185.199.108.153`, `185.199.109.153`, `185.199.110.153`, `185.199.111.153` |
   | `@`   | AAAA  | `2606:50c0:8000::153`, `2606:50c0:8001::153`, `2606:50c0:8002::153`, `2606:50c0:8003::153` |
   | `www` | CNAME | `dadaops.github.io` |

2. Quando `dig +short abitarelaparola.it` restituisce gli indirizzi qui sopra, collega il dominio e rilancia il workflow:

   ```bash
   gh api -X PUT repos/dadaops/abitarelaparola/pages -f cname=abitarelaparola.it
   gh workflow run pages.yml --repo dadaops/abitarelaparola
   ```

3. Quando GitHub ha emesso il certificato (di solito entro un'ora), attiva HTTPS obbligatorio:

   ```bash
   gh api -X PUT repos/dadaops/abitarelaparola/pages -F https_enforced=true
   ```

Il vecchio indirizzo https://dadaops.dev/abitarelaparola/ dovrebbe reindirizzare al nuovo dominio: verificalo dopo il passaggio.
Infine aggiungi https://abitarelaparola.it/ a Google Search Console e invia `https://abitarelaparola.it/sitemap.xml`.
