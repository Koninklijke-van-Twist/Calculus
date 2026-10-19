# Calculus

BASCALC-calculaties inladen op Business Central-projectbasislijnen via RapidStart-pakket `NEWBUILD_CALCULATIE`.

Live: `https://sleutels.kvt.nl/calculus/` ← `web/` → `/var/www/html/calculus/` (FTP op push naar `master`).

## Doel

ICT-medewerker:

1. Uploadt een BASCALC-`.xlsx`
2. Vult projectnummer in (`PRJ…`)
3. Kiest het BC-bedrijf (de omgeving volgt uit dat bedrijf; zie [docs/COMPANIES.md](docs/COMPANIES.md))
4. Ziet een voorbeeld: geparste regels, totalen, eventuele bestaande BC-regels, hash-waarschuwing
5. Bevestigt → RapidStart-pakket downloaden of Automation API apply in BC

## Gekozen route

**Route A — Automation API configuration packages** (zelfde werkwijze als handmatig).

Bron-tabblad in Excel: **`Invoer BC`** (niet Basisblad).  
BC-tabel: LogicVision **`LVS_JobChngeOrderBudgetLne`** / UI **Projectbasislijnregel** (tabel-ID `11332917`).  
De preview van bestaande regels leest de gepubliceerde OData-entity **`JobBaselineLines`** met filter **`Job_No`** (zelfde service als FinRap). De AL-objectnaam, de UI-caption en spellingvarianten daarvan zijn geen entity sets.  
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
  lib/BcAutomation.php   Automation API + OData-preview (Mímir, anders eigen OData)
  lib/MimirClient.php    Mímir query-client en circuit breaker
  lib/CompanyCatalog.php bedrijvenlijst en bedrijf → environment
  templates/NEWBUILD_CALCULATIE_template.xlsx
  data/                  runtime (sqlite, packages) — niet in git, HTTP dicht
  cache/                 runtime — HTTP dicht
  uploads/               geüploade BASCALC — HTTP dicht
```

## Afscherming

`data/`, `data/packages/`, `cache/` en `uploads/` antwoorden met 403 (`Options -Indexes`, `Require all denied`, `Deny from all`) plus een lege `index.html` / `index.php`. De SQLite-audit en de RapidStart-pakketten blijven op de server; de FTP-spiegel slaat die mappen over. Alleen de afschermingsbestanden gaan mee via `put`, zonder `--delete`.

Een ingelogde gebruiker downloadt een pakket via `?action=download_package` (zelfde login als de UI: `login/lib.php` en de allowlist). Na die check gaat het sessiebestand dicht. CLI en een optioneel runnergeheim (`$calculusRunnerSecret` of `CALCULUS_RUNNER_SECRET`, header `X-Calculus-Secret`) slaan de login over; een leeg geheim telt niet. Calculus heeft geen `odata.php` en geen `cache_status`.

**Gebruikersbeheer:** alleen `opesket@kvt.nl`, `tfalken@kvt.nl` en `milanscheenloop@kvt.nl` zien in de UI de knop *Gebruikersbeheer* (`?action=admin`). Zij voegen e-mailadressen toe of trekken toegang in; dat staat in SQLite (`allowed_users`), niet in `auth.php`. Zolang die tabel leeg is, geldt nog `$allowedUsers` uit `auth.php` (lege lijst = elke ingelogde gebruiker). Zodra er rijen staan, zijn alleen die adressen plus de vaste admins welkom.

## Configuratie

```bash
cp web/auth_TEMPLATE.php web/auth.php
# vul $auth_list, $environment (actieve databases), $allowedUsers (ICT)
# optioneel $mimirApi (bedrijvenlijst én basislijnregels), $calculusDefaultCompany, $calculusBaselineODataEntities
```

`$mimirApi` en de BC-credentials horen naast elkaar. Dezelfde key levert ook de bedrijvenlijst (`GET /companies.php`); zonder key komt die lijst uit de Automation API. Staat de key er, dan leest de preview van bestaande basislijnregels eerst Mímir (`query.php`, `max_age` 600, `$top` 500, entity `JobBaselineLines`, filter `Job_No`, tenzij `$calculusBaselineODataEntities` een andere gepubliceerde servicenaam zet). Faalt die aanroep, dan dezelfde read via de eigen OData van de environment van het gekozen bedrijf (zelfde entity, filter `Job_No`). Na die eerste fout slaat dit PHP-verzoek Mímir over. Wijkt `meta.environment` af van die environment, dan ook eigen OData, zonder het circuit te openen. Zonder `$mimirApi` blijft alleen die directe OData. Ontbreken bruikbare BC-credentials, dan komt de oorspronkelijke Mímir-fout terug. Pakket-apply blijft de Automation API; dat is geen OData-read.

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
- OData-preview van bestaande regels probeert eerst Mímir op `JobBaselineLines` (of de namen in `$calculusBaselineODataEntities`). Filter is `Job_No` voor Mímir en voor de eigen OData. Een 404 op een geconfigureerde naam probeert de volgende nog via Mímir; andere Mímir-fouten vallen meteen terug op eigen OData. Zonder die gepubliceerde Web Service zie je een korte waarschuwing; voorbeeld en download gaan door.
- BASCALC moet gecachete Excel-waarden hebben (bestand ooit in Excel geopend/opgeslagen). Formules zonder cache → harde fout.
- Naam **Daedalus** was al bezet (werkorders); deze app heet **Calculus**.
