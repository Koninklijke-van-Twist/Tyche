<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php tests/test_mimir_odata_routing.php
 */

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/web/odata.php';

/**
 * Variabelen
 */
$failures = 0;
$mockPort = 18947;
$mockLog = sys_get_temp_dir() . '/tyche-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/tyche-mimir-mock.php';
$indexRunner = sys_get_temp_dir() . '/tyche-mimir-index-runner.php';
$authPath = dirname(__DIR__) . '/web/auth.php';
$cacheDir = dirname(__DIR__) . '/web/cache/odata';

/**
 * Functies
 */
function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_tyche_url(string $base, string $entity, array $query = []): string
{
    $url = rtrim($base, '/') . '/' . $entity;
    if ($query === []) {
        return $url;
    }

    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir/api/redirect.php')) {
    header('Location: /evil', true, 302);
    echo json_encode(['error' => 'redirect']);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'kvtmdlive_aad'],
        ['name' => "Van Twist's", 'environment' => 'kvtmdlive_aad'],
        ['name' => 'Hunter van Twist', 'environment' => 'otherenv'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $table = (string) ($body['table'] ?? '');
    if ($table === 'SalesQuotes') {
        echo json_encode(['value' => [
            ['No' => 'Q2', 'Quote_Valid_Until_Date' => '2026-01-01'],
            ['No' => 'Q1', 'Quote_Valid_Until_Date' => '2026-06-01'],
        ]]);
        exit;
    }
    if ($table === 'AppSalesPerson') {
        echo json_encode(['value' => [[
            'Code' => 'SV',
            'Name' => 'Sanne Voorbeeld',
            'company' => (string) ($body['company'] ?? ''),
            'table' => $table,
            'select' => $body['select'] ?? [],
            'filter' => (string) ($body['filter'] ?? ''),
            'max_age' => $body['max_age'] ?? null,
            'top' => $body['top'] ?? null,
        ]]]);
        exit;
    }
    echo json_encode(['value' => []]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

function test_cache_json_files(): array
{
    global $cacheDir;
    $files = glob($cacheDir . '/*.json');
    return is_array($files) ? $files : [];
}

/**
 * Page load
 */
test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default base is sleutels',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$base = "https://bc.example/kvtmdlive_aad/ODataV4/Company('Koninklijke van Twist')";
$spaceUrl = test_tyche_url($base, 'AppSalesPerson', [
    '$select' => 'Code,Name',
    '$filter' => "Code eq 'SV'",
    '$orderby' => 'Name asc',
    '$top' => '1',
]);
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'AppSalesPerson'
        && ($parsedSpace['query']['$select'] ?? '') === 'Code,Name'
        && ($parsedSpace['query']['$filter'] ?? '') === "Code eq 'SV'"
        && ($parsedSpace['query']['$orderby'] ?? '') === 'Name asc'
        && ($parsedSpace['query']['$top'] ?? '') === '1',
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

$apostropheBase = "https://bc.example/kvtmdlive_aad/ODataV4/Company('Van Twist%27s')";
$parsedApostrophe = odata_mimir_parse_entity_url(test_tyche_url($apostropheBase, 'SalesQuotes'));
test_assert(
    'gecodeerde apostrof in bedrijfsnaam',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'SalesQuotes',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = 'https://bc.example/kvtmdlive_aad/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'kvtmdlive_aad',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
unset($auth);

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}
$authWritten = false;

try {
    $beforeCache = test_cache_json_files();
    $rows = odata_get_all($spaceUrl, [], 60);
    test_assert(
        'odata_get_all via Mímir zonder BC-auth',
        is_array($rows[0] ?? null)
            && ($rows[0]['Name'] ?? '') === 'Sanne Voorbeeld'
            && ($rows[0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($rows[0]['table'] ?? '') === 'AppSalesPerson'
            && ($rows[0]['filter'] ?? '') === "Code eq 'SV'"
            && ($rows[0]['max_age'] ?? null) === 60
            && ($rows[0]['top'] ?? null) === 0
            && in_array('Code', $rows[0]['select'] ?? [], true)
            && in_array('Name', $rows[0]['select'] ?? [], true),
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    $afterCache = test_cache_json_files();
    test_assert('Mímir slaat Tyche-filecache over', count($afterCache) === count($beforeCache));

    $companyRows = odata_get_all($companiesUrl, [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery gaat naar Mímir en filtert op environment',
        $companyNames === ['Koninklijke van Twist', "Van Twist's"],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $quotesUrl = test_tyche_url($base, 'SalesQuotes', [
        '$orderby' => 'Quote_Valid_Until_Date desc',
        '$top' => '1',
    ]);
    $quoteRows = odata_get_all($quotesUrl, [], 30);
    test_assert(
        'orderby en top blijven lokaal gelden',
        count($quoteRows) === 1 && ($quoteRows[0]['No'] ?? '') === 'Q1',
        json_encode($quoteRows, JSON_UNESCAPED_UNICODE)
    );

    $redirectThrew = false;
    $redirectMessage = '';
    try {
        odata_mimir_request('GET', 'redirect.php');
    } catch (Exception $error) {
        $redirectThrew = str_contains($error->getMessage(), 'HTTP 302');
        $redirectMessage = $error->getMessage();
    }
    test_assert('Mímir volgt geen redirect', $redirectThrew, $redirectMessage);

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    $sawEvil = false;
    $sawBearer = false;
    foreach ($requests as $request) {
        $uri = (string) ($request['uri'] ?? '');
        if (str_contains($uri, 'bc.example')) {
            $hitBcHost = true;
        }
        if (str_contains($uri, '/evil')) {
            $sawEvil = true;
        }
        if (($request['ua'] ?? '') === 'Tyche-MimirClient/1.0' && str_contains($uri, '/mimir/api/')) {
            $sawMimirUa = true;
        }
        if (str_starts_with((string) ($request['authorization'] ?? ''), 'Bearer mimir_test_key')
            && (string) ($request['api_key'] ?? '') === 'mimir_test_key') {
            $sawBearer = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('redirect-doel wordt niet opgehaald', $sawEvil === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('Mímir-key als Bearer en X-API-Key', $sawBearer);

    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents($authPath, "<?php\n"
        . '$mimirApi = ' . var_export($mimirApi, true) . ";\n"
        . '$mimirBase = ' . var_export($mimirBase, true) . ";\n"
        . '$base = ' . var_export($base, true) . ";\n");
    file_put_contents($indexRunner, "<?php\n"
        . '$_SERVER[\'REMOTE_ADDR\'] = \'127.0.0.1\';' . "\n"
        . '$_SERVER[\'SERVER_ADDR\'] = \'127.0.0.1\';' . "\n"
        . '$_SERVER[\'REQUEST_METHOD\'] = \'GET\';' . "\n"
        . '$_GET = [];' . "\n"
        . 'require ' . var_export(dirname(__DIR__) . '/web/index.php', true) . ";\n");
    $indexOutput = [];
    $indexExit = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($indexRunner) . ' 2>&1', $indexOutput, $indexExit);
    $indexHtml = implode("\n", $indexOutput);
    test_assert(
        'pagina laadt via Mímir zonder $auth',
        $indexExit === 0
            && str_contains($indexHtml, 'Sanne Voorbeeld')
            && !str_contains($indexHtml, '<div class="error">'),
        'exit=' . $indexExit . ' ' . substr($indexHtml, 0, 500)
    );

    $mimirApi = '';
    $auth = [
        'mode' => 'basic',
        'user' => 'bcuser',
        'pass' => 'bcpass',
    ];
    file_put_contents($authPath, "<?php\n\$environment = 'Production';\n\$mimirApi = '';\n");
    @unlink($mockLog);
    $beforeBcCache = test_cache_json_files();

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcUrl = 'http://127.0.0.1:' . $mockPort . '/Production/ODataV4/Companies?$select=Name';
    $bcRows = odata_get_all($bcUrl, $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
    $afterBcCache = test_cache_json_files();
    test_assert('BC-pad schrijft nog filecache', count($afterBcCache) === count($beforeBcCache) + 1);

    $created = array_diff($afterBcCache, $beforeBcCache);
    foreach ($created as $createdFile) {
        @unlink($createdFile);
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    @unlink($mockScript);
    @unlink($mockLog);
    @unlink($indexRunner);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
