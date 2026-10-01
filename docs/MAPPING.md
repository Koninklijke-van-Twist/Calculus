# Veldmapping BASCALC → BC

Bron: tab **Invoer BC** (Excel-tabel).  
Doel: RapidStart-pakket `NEWBUILD_CALCULATIE` / sheet `Projectbasislijnregel` / XML `LVS_JobChngeOrderBudgetLne`.

| Excel (`Invoer BC`) | RapidStart-header | XML-veld |
|---|---|---|
| Projectnr. | Projectnr. | `JobNo` |
| Project subordernr. | Project subordernr. | `JobChangeOrderNo` |
| Basislijnversienr. | Basislijnversienr. | `BaselineVersionNo` |
| Projecttaaknr. | Projecttaaknr. | `JobTaskNo` |
| Configuratienr. | Configuratienr. | `ConfigurationNo` |
| Configuratieversienr. | Configuratieversienr. | `ConfigurationVersionNo` |
| Configuratieregelnr. | Configuratieregelnr. | `ConfigurationLineNo` |
| Regelnr. | Regelnr. | `LineNo` |
| Regelsoort | Regelsoort | `LineType` |
| Soort | Soort | `Type` |
| Nr. | Nr. | `No` |
| Omschrijving | Omschrijving | `Description` |
| Vestiging | Vestiging | `LocationCode` |
| Werksoort | Werksoort | `WorkTypeCode` |
| Aantal | Aantal | `Quantity` |
| Kostprijs (LV) | Kostprijs | `UnitCost` |
| Directe Kostprijs (LV) | Directe kostprijs (LV) | `DirectUnitCostLCY` |
| Opslaglocatie | Opslaglocatie | `BinCode` |
| Basislijnversie omschrijving | … | `BaselineVersionDescription` |
| Basislijnversie in filter | … | `BaselineVersioninFilter` |
| (leeg) | Basislijnversie omschrijving 2 | `BaselineVersionDescription2` |

**Basislijn (totale kostprijs)** in de BC-UI = `Quantity × UnitCost`.

Helperkolommen V/W op `Invoer BC` worden **niet** geïmporteerd.
