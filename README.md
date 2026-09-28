# Tychet

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git), mét de Business Central-gegevens ernaast:

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
// verplicht als fallback: $base, $auth / $auth_list, $environment en $baseUrl
```

Met `$mimirApi` gezet gaan OData-fetches eerst naar Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Tyche dezelfde data op via de oude Business Central-route (`$base`, of `$baseUrl` + `$environment`, plus `$auth` / `$auth_list` en de lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Houd die BC-gegevens in `auth.php` naast `$mimirApi`; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft alleen de bestaande BC-route actief.

De live pagina is `web/index.php`. Er is geen apart nightly-script; CLI- en cron-aanroepen die `web/odata.php` gebruiken moeten eveneens `web/auth.php` laden zodat die BC-gegevens in scope zijn. De Mímir-timeout is in CLI 600s en in webrequests ongeveer 90s (connect-timeout 10s).
