# Vulcanus

Printbare **Werkplaatsopdracht** en **Assemblageopdracht** (A4) voor sleutels.kvt.nl.
Vervangt Power BI Report Builder-rapporten met hybrides HTML/CSS/PHP (compacte PDF-header + sterke regelstabel, Code128-barcode).

| Rapport | Pagina | Bronnen (Mímir / Business Central, company `Koninklijke van Twist`) |
|---------|--------|----------------------------------------------------------------------|
| Werkplaatsorder | `web/werkplaatsorder.php` | `LVS_MainWorkOrderCard` + `Job_Planning_Lines` |
| Assemblage | `web/assemblage.php` | `AssemblageKop` + `AssemblageRegels` |

Live data via Mímir wanneer `$mimirApi` in `web/auth.php` staat (niet in git; Tim heeft dit in productie).
Zonder `$mimirApi` vallen de rapporten terug op sample-data, zodat lokaal previewen blijft werken.
`?sample=1` forceert sample-data ook als Mímir aan staat.

`index.php` vraagt alleen om een ordernummer (logo, veld, Doorgaan). Bij verzenden toont de kiezer meteen het logo, het nummer en **Opdracht ophalen…** (CSS-spinner). Het veld en de knop gaan direct uit, zodat de opdracht niet twee keer vertrekt. Herkenning is hoofdletterongevoelig, als substring — in PHP (`vulcanus_detect_report_type`) en in diezelfde volgorde in de kiezer-JavaScript:

- bevat **ASS** → meteen `assemblage.php` (nooit werkplaats)
- bevat **WO** → meteen `werkplaatsorder.php` (nooit assemblage)
- geen van beide → `null`. Geen gok (`AO…` is geen ASS). Index toont dan twee knoppen: Assemblageopdracht en Werkplaatsopdracht, met het ingevoerde nummer ter controle. Een klik toont dezelfde laadstatus en opent daarna het rapport.

Zonder JavaScript blijft het formulier naar `index.php` gaan. Een dieplink `index.php?no=ASS…` of `?no=WO…` routeert meteen. Zonder die letters opent `?no=` het keuzescherm. Een verkeerde rapportpagina stuurt alleen door als `no` duidelijk ASS of WO bevat, en behoudt overige query-args zoals `sample`.

De rapportpagina's antwoorden eerst met een laadscherm (zelfde kiezer-stijl) en halen de print-HTML daarna op via `fetch` van dezelfde URL met `_content=1` (`credentials: 'same-origin'`). Mímir laat het scherm zo niet blanco. Een netwerkfout toont een melding met een link terug naar de kiezer. `?_content=1` zelf is het bestaande rapport, inclusief niet-gevonden en Mímir-fout. Zonder JavaScript staat op het laadscherm een link naar diezelfde URL.

## Lokaal preview

```bash
php -S localhost:8765 -t web
```

Open: <http://localhost:8765/>

- Invoer: `index.php` (bij ASS/WO meteen het rapport; anders de twee knoppen)
- Voorbeeld WO: `werkplaatsorder.php?no=WO26091234`
- Voorbeeld ASS: `assemblage.php?no=ASS26094567`

> Lokaal: kopieer `web/auth_TEMPLATE.php` → `web/auth.php` (staat in `.gitignore`) met minimaal `$allowedUsers`. Trusted localhost slaat logincheck over. Laat `$mimirApi` weg voor sample-data.

Tests (geen netwerk, geen `auth.php`):

```bash
php tests/mimir_live_test.php
```

## Productie (sleutels.kvt.nl)

Pagina-root is **`web/`** (FTP-deploy spiegelt `web/` naar de remote dir).

Tim levert zelf:

1. **`web/auth.php`** (niet in git) — zie `auth_TEMPLATE.php`. Voor live rapporten is `$mimirApi` genoeg; `$mimirBase` en `$mimirCompany` zijn optioneel (`https://sleutels.kvt.nl/mimir/api` en `Koninklijke van Twist`).
2. **`thumbnail.png`** in de repo-root (portaltegels)

Deploy: `.github/workflows/deploy-ftp.yml` op push naar `master`. Repo-secrets: `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_DIR`. `auth.php` en cache/sqlite worden uitgesloten.

## Layout

- **Header:** sentence-case titel, print-tijdstempel, KVT-logo, label:value grid, ordernr + Code128 (alleen cijfers uit `No`)
- **Regels:** Aantal | Nr. | Omschrijving; `KVT_Extended_Text` als ondertitel; `INSTRUCTIE` verbergt qty; `HR` → UUR
- Footer: Pagina N via de CSS-paginateller bij afdrukken (niet hardcoded). Lange `KVT_Extended_Text` mag midden in een regel over de pagina lopen; lege regels zonder nr/omschrijving worden niet afgedrukt.
- Schermhint: `live (Mímir)` of `sample data`
