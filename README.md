# Vulcanus

Printbare **Werkplaatsopdracht** en **Assemblageopdracht** (A4) voor sleutels.kvt.nl.
Vervangt Power BI Report Builder-rapporten met hybrides HTML/CSS/PHP (compacte PDF-header + sterke regelstabel, Code128-barcode).

| Rapport | Pagina | Bronnen (later via Mímir) |
|---------|--------|---------------------------|
| Werkplaatsorder | `web/werkplaatsorder.php` | `LVS_MainWorkOrderCard` + `Job_Planning_Lines` |
| Assemblage | `web/assemblage.php` | `AssemblageKop` + `AssemblageRegels` |

Nu: sample-data. Live Mímir-koppeling volgt.

## Lokaal preview

```bash
php -S localhost:8765 -t web
```

Open: <http://localhost:8765/>

- Kiezer: `index.php`
- Sample WO: `werkplaatsorder.php?no=WO26091234`
- Sample AO: `assemblage.php?no=ASS26094567`

> Lokaal: kopieer `web/auth_TEMPLATE.php` → `web/auth.php` (staat in `.gitignore`) met minimaal `$allowedUsers`. Trusted localhost slaat logincheck over.

## Productie (sleutels.kvt.nl)

Pagina-root is **`web/`** (FTP-deploy spiegelt `web/` naar de remote dir).

Tim levert zelf:

1. **`web/auth.php`** (niet in git) — zie `auth_TEMPLATE.php` (`$allowedUsers`, optioneel `$mimirApi` / `$mimirBase`)
2. **`thumbnail.png`** in de repo-root (portaltegels)

Deploy: `.github/workflows/deploy-ftp.yml` op push naar `master`. Repo-secrets: `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_DIR`. `auth.php` en cache/sqlite worden uitgesloten.

## Layout

- **Header:** sentence-case titel, print-tijdstempel, KVT-logo, label:value grid, ordernr + Code128 (alleen cijfers uit `No`)
- **Regels:** Aantal | Nr. | Omschrijving; `KVT_Extended_Text` als ondertitel; `INSTRUCTIE` verbergt qty; `HR` → UUR
- Footer: Pagina N

## Volgende stap

Mímir-API voor de tabellen hierboven; parameter `?no=` behouden; auth via bestaande KVT-sessie.
