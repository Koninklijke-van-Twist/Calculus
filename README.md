# Calculus

BASCALC-calculaties inladen op Business Central-projectbasislijnen via RapidStart-pakket `NEWBUILD_CALCULATIE`.

Live: `https://sleutels.kvt.nl/calculus/` ← `web/` → `/var/www/html/calculus/` (FTP op push naar `master`).

## Doel

ICT-medewerker:

1. Kiest Asclepius-ticket (xlsx-bijlage) of uploadt een BASCALC-`.xlsx`
2. Vult projectnummer in (`PRJ…`)
3. Kiest het BC-bedrijf (de omgeving volgt uit dat bedrijf; zie [docs/COMPANIES.md](docs/COMPANIES.md))
4. Ziet dry-run: geparste regels, totalen, eventuele bestaande BC-regels, hash-waarschuwing
5. Bevestigt expliciet → RapidStart-pakket bouwen, optioneel Automation API apply
6. Krijgt resultaat + optionele ticketreactie

## Gekozen route

**Route A — Automation API configuration packages** (zelfde werkwijze als handmatig).

Bron-tabblad in Excel: **`Invoer BC`** (niet Basisblad).  
BC-tabel: LogicVision **`LVS_JobChngeOrderBudgetLne`** / UI **Projectbasislijnregel** (tabel-ID `11332917`).  
UI-kolom **Basislijn (totale kostprijs)** = `Aantal × Kostprijs` (`Quantity × UnitCost`), geen apart veld.

Zie veldmapping in [docs/MAPPING.md](docs/MAPPING.md).

Geen environment-kiezer. De dropdown toont BC-bedrijven; het bedrijf bepaalt de database (`kvtmdlive_aad` voor KVT/HVT, `kvtgermanylive_aad` voor KVT Germany, `kvtmdlive_fat` voor FAT). Een environment zonder `fat` in de naam schrijft alleen na de live-checkbox. Zie [docs/COMPANIES.md](docs/COMPANIES.md).

## Structuur

```
web/
  index.php              UI + acties
  logincheck.php         SSO via ../login/lib.php
  auth_TEMPLATE.php      → kopieer naar auth.php (niet in git)
  lib/BascalcParser.php
  lib/RapidStartBuilder.php
  lib/ImportStore.php    SQLite audit + idempotentie
  lib/AsclepiusClient.php
  lib/BcAutomation.php   Automation API + OData-preview
  lib/CompanyCatalog.php bedrijvenlijst en bedrijf → environment
  templates/NEWBUILD_CALCULATIE_template.xlsx
  data/                  runtime (sqlite, packages) — niet in git
```

## Configuratie

```bash
cp web/auth_TEMPLATE.php web/auth.php
# vul $auth_list, $environment (actieve databases), $allowedUsers (ICT)
# optioneel $asclepiusApiKey, $mimirApi (bedrijvenlijst), $calculusDefaultCompany
```

FTP-secrets op de GitHub-repo (zoals andere sleutels-apps):

| Secret | Voorbeeld |
|--------|-----------|
| `FTP_HOST` | FTP-host |
| `FTP_USERNAME` | gebruiker |
| `FTP_PASSWORD` | wachtwoord |
| `FTP_REMOTE_DIR` | `/var/www/html/calculus` |

## Terugdraaien

Calculus **verwijdert nooit** bestaande regels. Terugdraaien gebeurt handmatig in BC (projectbasislijn / change order) of door een gecorrigeerd pakket te importeren. Auditregels blijven in `web/data/calculus.sqlite`.

## Beperkingen / open

- Live BC apply hangt af van Automation API-rechten op de service-account en exacte upload-endpoints van jullie NST-versie; faalt apply, dan blijft het pakket downloadbaar voor handmatige import.
- OData-preview van bestaande regels vereist een gepubliceerde page/API op de LVS-tabel; anders zie je een waarschuwing i.p.v. de lijst.
- BASCALC moet gecachete Excel-waarden hebben (bestand ooit in Excel geopend/opgeslagen). Formules zonder cache → harde fout.
- Naam **Daedalus** was al bezet (werkorders); deze app heet **Calculus**.
