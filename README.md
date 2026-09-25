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

Ordernummers worden alleen automatisch herkend als ze ASS of WO bevatten (hoofdletterongevoelig, als substring):

- bevat **ASS** → assemblage (nooit werkplaats)
- bevat **WO** → werkplaatsorder (nooit assemblage)
- geen van beide → `vulcanus_detect_report_type` geeft `null`. Dat zijn echte productienummers (ook `AO…` is geen ASS). De kiezer op `index.php` blijft zichtbaar en bepaalt het rapporttype; er wordt niet gegokt.

Een verkeerde pagina stuurt alleen door als `no` duidelijk ASS of WO bevat (`werkplaatsorder.php?no=ASS…` of omgekeerd) en behoudt overige query-args. Zonder die letters blijft de geopende pagina staan.

## Lokaal preview

```bash
php -S localhost:8765 -t web
```

Open: <http://localhost:8765/>

- Kiezer: `index.php`
- Sample WO: `werkplaatsorder.php?no=WO26091234`
- Sample ASS: `assemblage.php?no=ASS26094567`

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

- **Header:** sentence-case titel, print-tijdstempel, KVT-crown, label:value grid, ordernr + Code128 (alleen cijfers uit `No`)
- **Regels:** Aantal | Nr. | Omschrijving; `KVT_Extended_Text` als ondertitel; `INSTRUCTIE` verbergt qty; `HR` → UUR
- Footer: Pagina N
- Schermhint: `live (Mímir)` of `sample data`
