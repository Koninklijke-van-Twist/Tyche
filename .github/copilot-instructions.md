# Copilot-instructies voor Daedalus

## Scope en architectuur
- Deze applicatie draait volledig vanuit de map `/web/` en wordt gepubliceerd onder `https://sleutels.kvt.nl/tyche`.
- Houd links daarom relatief (`index.php`, `odata.php?...`) of vanaf `/tyche/...` als absolute paden nodig zijn.

## Favicon
- Implementeer op elke nieuwe pagina de favicon met het bedrijfslogo
- Pas het favicon ook toe op pagina's waar hij nog blijkt te ontbreken

## Niet wijzigen
- Bestand `web/logincheck.php` niet aanpassen.
- Bestand `web/odata.php` niet aanpassen.
- Bestand `web/auth.php` alleen aanpassen na expliciete gebruikersvraag.

## Data en logica
- Hoofdflow staat in `web/index.php`.
- Helpers staan in hun eigen bestand.
- De email van de gebruiker is beschikbaar in `$_SESSION['user']['email']`.
- Gebruikerskoppeling loopt via `AppResource` (`E_Mail`) met fallback via `AppUserSetup` (`Email` -> `User_ID` -> `AppResource.KVT_User_ID`).

## Code-structuur en refactorregels (PHP en JS)
- Pas bij refactors in PHP/JS altijd dezelfde sectievolgorde toe, en alleen als de sectie inhoud heeft:
  - `Includes/requires` (of vergelijkbare naam zoals `Imports`)
  - `Constants`
  - `Variabelen`
  - `Functies`
  - `Page load` (alle top-level uitvoerbare code die niet in functies staat)
- Gebruik voor secties een duidelijke blokcomment-stijl, bijvoorbeeld:
  - `/**` + `* Functies` + `*/`
- Voeg geen lege secties toe. Een ontbrekende sectie betekent: niet opnemen.
- Functioneel gedrag mag niet wijzigen door de refactor:
  - geen wijziging in logica, filters, output, routes, sessiegedrag of side-effects
  - alleen herordenen/annoteren en waar nodig veilig opsplitsen zonder gedragswijziging
- Houd top-level uitvoerbare code geconcentreerd in de `Page load`-sectie.
- Classes moeten altijd in een eigen bestand staan:
  - maximaal 1 class per bestand
  - bestandsnaam sluit aan op classnaam
  - geen class-definities tussen page-load code in gecombineerde scriptbestanden
- Respecteer altijd bestaande uitzonderingen uit deze instructies:
  - `web/logincheck.php` niet aanpassen
  - `web/odata.php` niet aanpassen
  - `web/auth.php` alleen aanpassen na expliciete gebruikersvraag
