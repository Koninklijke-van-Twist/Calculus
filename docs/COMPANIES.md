# Bedrijf → environment

De Calculus-UI heeft geen environment-kiezer. De gebruiker kiest een BC-bedrijf. Dat bedrijf wijst naar precies één environment; daarmee gaan preview en apply naar de juiste database.

Dit is dezelfde regel als Mímir en Penates (`auth_get_environment_for_company`): een aanroep noemt het bedrijf, niet de database.

## Bron van de dropdown

Actieve environments zijn `$environment` gesneden met de sleutels van `$auth_list` (alleen databases waar Calculus credentials voor heeft).

1. Staat `$mimirApi` in `auth.php`, dan is de lijst `GET {mimirBase}/companies.php` (standaard `https://sleutels.kvt.nl/mimir/api/companies.php`). Het antwoord is `{ "value": [ { "name", "environment" } ] }`, de nightly-catalogus van Mímir. Alleen rijen waarvan `environment` actief is in Calculus komen in de dropdown.
2. Zonder sleutel, of als Mímir leeg of onbereikbaar is, haalt Calculus per actieve environment de bedrijven op via de Automation API: `{baseUrl}/{environment}/api/microsoft/automation/v2.0/companies` (`BcAutomation::listCompanies()`).

Een bedrijfsnaam mag, hoofdletterongevoelig, maar in één actieve environment voorkomen. Dezelfde naam in twee databases is een fout (geen stille voorkeur). De lijst blijft een uur in `web/cache/bc-companies.json` (niet in git). Lukt verversen niet, dan blijft de vorige lijst bruikbaar.

## Bekende KvT-verdeling

De dropdown volgt de live lijst, niet een vaste tabel. Onderstaande verdeling is wat Mímir voor deze NST documenteert. De BC-weergavenaam kan afwijken (Mímir-voorbeelden gebruiken `Koninklijke van Twist` en `KVT Germany`); het environment ligt vast per database.

| Bedrijven op die database | Environment | Credentials |
|---|---|---|
| KVT en HVT (Nederlandse database) | `kvtmdlive_aad` | eigen entry in `$auth_list` |
| KVT Germany | `kvtgermanylive_aad` | eigen entry in `$auth_list` |
| FAT-kopie van de Nederlandse database | `kvtmdlive_fat` | eigen entry in `$auth_list` |

KVT en HVT delen `kvtmdlive_aad`. Germany is een aparte database. FAT is optioneel.

FAT en live bevatten vaak dezelfde bedrijfsnaam. Zet dan maar één van die twee in `$environment`. Staan beide aan én heet het bedrijf in allebei bijvoorbeeld `KVT`, dan weigert Calculus de lijst — net als Mímir.

## Apply

Na de keuze zoekt Calculus het environment op en roept `BcAutomation::fromGlobals($environment, $company)` aan. Het formulier post geen environment.

Een environment zonder `fat` in de naam (dus `kvtmdlive_aad` en `kvtgermanylive_aad`) blijft een live-schrijfactie en vraagt de extra bevestiging. `kvtmdlive_fat` niet: de controle is het fragment `fat`, omdat `mdlive` in beide Nederlandse databasenamen staat.
