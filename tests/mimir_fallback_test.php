<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Geen web/auth.php. Run: php tests/mimir_fallback_test.php
 */

declare(strict_types=1);

$logFile = sys_get_temp_dir() . '/vulcanus-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$mimirCompany = 'Koninklijke van Twist';
$baseUrl = 'https://api.businesscentral.dynamics.com/v2.0/tenant/Production/ODataV4/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['VULCANUS_ODATA_BC_FETCH'] = static function (string $url, array $bcAuth) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($bcAuth['user'] ?? ''),
    ];
    return [['No' => 'WO-1', 'Description' => 'fallback']];
};

require dirname(__DIR__) . '/web/lib/live_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Vulcanus] Mímir failed, falling back to direct OData:');
}

function assert_no_secrets(string $log): void
{
    if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
        fail('log bevat een geheim: ' . $log);
    }
}

if (vulcanus_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (vulcanus_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s zijn');
}
if (
    vulcanus_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90
    || vulcanus_mimir_timeout_seconds_for_sapi('apache2handler') !== 90
) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && vulcanus_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}
$curlOptions = mimir_curl_options('{}', ['Accept: application/json']);
if (($curlOptions[CURLOPT_CONNECTTIMEOUT] ?? 0) !== 10) {
    fail('Mímir CURLOPT_CONNECTTIMEOUT moet 10 zijn');
}
if (PHP_SAPI === 'cli' && ($curlOptions[CURLOPT_TIMEOUT] ?? 0) !== 600) {
    fail('Mímir CURLOPT_TIMEOUT moet op CLI 600 zijn');
}

if (vulcanus_bc_odata_root() !== 'https://api.businesscentral.dynamics.com/v2.0/tenant/Production/ODataV4') {
    fail('SaaS-$baseUrl moet de ODataV4-root blijven: ' . (string) vulcanus_bc_odata_root());
}
$baseUrl = 'https://bc.example:7148/';
if (vulcanus_bc_odata_root() !== 'https://bc.example:7148/Production/ODataV4') {
    fail('host-only $baseUrl moet environment + ODataV4 krijgen: ' . (string) vulcanus_bc_odata_root());
}
$baseUrl = 'https://api.businesscentral.dynamics.com/v2.0/tenant/Production/ODataV4/';

$plainAuth = ['user' => 'bcuser', 'pass' => 'bc-secret'];
if (!vulcanus_bc_auth_is_usable($plainAuth) || !vulcanus_bc_auth_is_usable(['mode' => 'ntlm', 'user' => 'bcuser', 'pass' => 'x'])) {
    fail('basic zonder mode en ntlm moeten bruikbaar zijn');
}
if (vulcanus_bc_auth_is_usable(['mode' => 'basic', 'user' => '', 'pass' => 'x'])) {
    fail('lege user is geen BC-credential');
}

$liveSource = (string) file_get_contents(dirname(__DIR__) . '/web/lib/live_data.php');
if (strpos($liveSource, 'mimir_query(') !== false) {
    fail('live_data.php roept mimir_query nog rechtstreeks aan');
}
if (substr_count($liveSource, 'vulcanus_odata_query(') !== 4) {
    fail('werkplaats en assemblage moeten alle vier de queries via de helper doen');
}
foreach (['assemblage.php', 'werkplaatsorder.php'] as $page) {
    $pageSource = (string) file_get_contents(dirname(__DIR__) . '/web/' . $page);
    if (strpos($pageSource, 'mimir_query(') !== false || strpos($pageSource, 'mimir_post(') !== false) {
        fail($page . ' haalt zelf Mímir op');
    }
}

$rows = vulcanus_odata_query('LVS_MainWorkOrderCard', "No eq 'WO1'", ['No'], 300);
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('curl-fout naar 127.0.0.1:9 viel niet terug op de stub');
}
if (!vulcanus_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || ($calls[0]['user'] ?? '') !== 'bcuser') {
    fail('directe fetch gebruikte niet de BC-user: ' . json_encode($calls));
}
$expectedPrefix = "https://api.businesscentral.dynamics.com/v2.0/tenant/Production/ODataV4/Company('Koninklijke%20van%20Twist')/LVS_MainWorkOrderCard?";
if (strpos($calls[0]['url'], $expectedPrefix) !== 0) {
    fail('directe URL klopt niet: ' . $calls[0]['url']);
}
if (strpos($calls[0]['url'], 'No%20eq%20%27WO1%27') === false || strpos($calls[0]['url'], 'select=No') === false) {
    fail('filter of select ontbreekt in de directe URL: ' . $calls[0]['url']);
}
if (fallback_count() !== 1 || strpos(fallback_log(), '[Vulcanus] Mímir failed, falling back to direct OData:') === false) {
    fail('fallback werd niet gelogd: ' . fallback_log());
}
assert_no_secrets(fallback_log());

$transportCalled = false;
mimir_set_transport(static function () use (&$transportCalled): array {
    $transportCalled = true;
    sleep(3);
    return ['code' => 500, 'raw' => '{"error":"slow"}'];
});
$started = microtime(true);
$second = vulcanus_odata_query('Job_Planning_Lines', "LVS_Work_Order_No eq 'WO1'", ['No', 'Description'], 300);
$elapsed = microtime(true) - $started;
if ($transportCalled || $elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($second[0]['No'] ?? '') !== 'WO-1' || count($calls) !== 2 || fallback_count() !== 2) {
    fail('tweede fallback miste stub of log, calls=' . count($calls) . ' logs=' . fallback_count());
}
assert_no_secrets(fallback_log());
if (strpos($calls[1]['url'], "/Job_Planning_Lines?") === false) {
    fail('tweede directe URL is niet de regel-tabel: ' . $calls[1]['url']);
}

vulcanus_mimir_circuit_reset();
mimir_set_transport(null);
$mimirHits = 0;
mimir_set_transport(static function () use (&$mimirHits): array {
    $mimirHits++;
    return ['code' => 500, 'raw' => '{"error":"down"}'];
});
$beforeFetch = count($calls);
$loggedBeforeFetch = fallback_count();
$fetchStarted = microtime(true);
$report = fetch_werkplaatsorder('WO1');
$fetchElapsed = microtime(true) - $fetchStarted;
if ($mimirHits !== 1) {
    fail('fetch_werkplaatsorder probeerde Mímir ' . $mimirHits . ' keer; de tweede query moet het circuit volgen');
}
if ($fetchElapsed >= 2.0) {
    fail('werkplaats-fetch bleef op Mímir hangen');
}
if (($report['source'] ?? '') !== 'bc' || ($report['header']['No'] ?? '') !== 'WO-1') {
    fail('werkplaats-fetch kwam niet uit de BC-fallback: ' . json_encode($report['source'] ?? null));
}
if (count($calls) !== $beforeFetch + 2 || fallback_count() !== $loggedBeforeFetch + 2) {
    fail('werkplaats-fetch deed geen twee directe queries');
}
if (strpos($calls[$beforeFetch]['url'], '/LVS_MainWorkOrderCard?') === false) {
    fail('eerste fetch-query is niet de kop: ' . $calls[$beforeFetch]['url']);
}
if (strpos($calls[$beforeFetch + 1]['url'], '/Job_Planning_Lines?') === false) {
    fail('tweede fetch-query is niet de regel: ' . $calls[$beforeFetch + 1]['url']);
}

vulcanus_mimir_circuit_reset();
$mimirHits = 0;
$beforeAss = count($calls);
$assReport = fetch_assemblage('ASS1');
if ($mimirHits !== 1 || ($assReport['source'] ?? '') !== 'bc' || count($calls) !== $beforeAss + 2) {
    fail('assemblage-fetch viel niet terug via het circuit');
}
if (strpos($calls[$beforeAss]['url'], '/AssemblageKop?') === false || strpos($calls[$beforeAss + 1]['url'], '/AssemblageRegels?') === false) {
    fail('assemblage-fetch gebruikte niet beide tabellen');
}

$failureCases = [
    'http' => ['code' => 503, 'raw' => json_encode(['error' => 'bezet'])],
    'json' => ['code' => 200, 'raw' => '{'],
    'payload' => ['code' => 200, 'raw' => json_encode(['error' => 'kapot ' . $mimirApi, 'value' => [['No' => 'van-mimir']]])],
];
foreach ($failureCases as $name => $response) {
    vulcanus_mimir_circuit_reset();
    $before = count($calls);
    $logged = fallback_count();
    mimir_set_transport(static function () use ($response): array {
        return $response;
    });
    $fallbackRows = vulcanus_odata_query('AssemblageKop', "No eq 'ASS1'", [], 30);
    if (($fallbackRows[0]['No'] ?? '') !== 'WO-1' || count($calls) !== $before + 1 || !vulcanus_mimir_circuit_open()) {
        fail($name . '-fout viel niet terug op BC');
    }
    if (fallback_count() !== $logged + 1) {
        fail($name . '-fout logde de fallback niet');
    }
    assert_no_secrets(fallback_log());
}

vulcanus_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
$rethrowHits = 0;
mimir_set_transport(static function () use (&$rethrowHits): array {
    $rethrowHits++;
    return ['code' => 502, 'raw' => json_encode(['error' => 'bezet'])];
});
$rethrown = null;
try {
    vulcanus_odata_query('AssemblageKop', "No eq 'X'", [], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if ($rethrowHits !== 1 || count($calls) !== $callsBeforeRethrow || fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen directe fetch of fallback-log zijn');
}
if (!vulcanus_mimir_circuit_open()) {
    fail('circuit blijft open zodat een volgend verzoek Mímir niet opnieuw wacht');
}
$started = microtime(true);
try {
    vulcanus_odata_query('AssemblageRegels', "Document_No eq 'X'", [], 30);
    fail('tweede aanroep zonder credentials moet ook de Mímir-fout gooien');
} catch (Throwable $again) {
    if ($again->getMessage() !== $rethrown->getMessage()) {
        fail('tweede aanroep gooide een andere fout: ' . $again->getMessage());
    }
}
if ($rethrowHits !== 1 || microtime(true) - $started >= 2.0) {
    fail('circuit sloeg de tweede Mímir-poging zonder credentials niet over');
}

vulcanus_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = [];
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'listuser', 'pass' => 'bc-secret']];
$loggedBeforeDirect = fallback_count();
$directTransport = false;
mimir_set_transport(static function () use (&$directTransport): array {
    $directTransport = true;
    return ['code' => 500, 'raw' => '{}'];
});
$directRows = vulcanus_odata_query('AssemblageKop', "No eq 'ASS1'", ['No'], 45);
$directCall = $calls[count($calls) - 1] ?? null;
if (vulcanus_mimir_circuit_open() || $directTransport || fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag Mímir niet proberen en geen fallback loggen');
}
if (
    ($directRows[0]['No'] ?? '') !== 'WO-1'
    || !is_array($directCall)
    || ($directCall['user'] ?? '') !== 'listuser'
    || strpos($directCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AssemblageKop?") !== 0
) {
    fail('lege $mimirApi moet meteen de directe route gebruiken: ' . json_encode($directCall));
}

$mimirApi = '';
$baseUrl = '';
$environment = '';
$auth = [];
$auth_list = [];
$beforeSample = count($calls);
$sampleReport = fetch_assemblage('ASS999');
if (($sampleReport['source'] ?? '') !== 'sample' || count($calls) !== $beforeSample) {
    fail('zonder Mímir en zonder BC blijven rapporten op sample-data');
}

$mimirApi = 'mimir_test_key_should_not_leak';
$baseUrl = 'https://api.businesscentral.dynamics.com/v2.0/tenant/Production/ODataV4/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
vulcanus_mimir_circuit_reset();
mimir_set_transport(static function (): array {
    return ['code' => 200, 'raw' => json_encode(['value' => [['No' => 'live']]])];
});
$beforeLive = count($calls);
$loggedBeforeLive = fallback_count();
$liveRows = vulcanus_odata_query('AssemblageKop', "No eq 'ASS1'", [], 30);
if (($liveRows[0]['No'] ?? '') !== 'live' || count($calls) !== $beforeLive || vulcanus_mimir_circuit_open()) {
    fail('een geslaagde Mímir-call mag niet terugvallen');
}
if (fallback_count() !== $loggedBeforeLive) {
    fail('een geslaagde Mímir-call mag niets loggen');
}

$root = 'https://bc.example/ODataV4';
if (!vulcanus_bc_same_origin('https://bc.example/ODataV4/Company(\'K\')/T?$skip=20', $root)) {
    fail('zelfde https-origin moet toegestaan zijn');
}
if (!vulcanus_bc_same_origin('https://bc.example:443/next', $root)) {
    fail('impliciete en expliciete poort 443 zijn dezelfde origin');
}
foreach ([
    'http://bc.example/ODataV4/next',
    'https://evil.example/ODataV4/next',
    'https://bc.example:8443/next',
    'https://bcuser:bc-secret@bc.example/next',
    '//bc.example/next',
] as $foreign) {
    if (vulcanus_bc_same_origin($foreign, $root)) {
        fail('credentials mogen niet naar ' . $foreign);
    }
}

$savedBase = $baseUrl;
$savedEnv = $environment;
$savedAuth = $auth;
$savedList = $auth_list;
$baseUrl = 'https://bc.example/ODataV4';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$httpCalls = [];
$savedFetch = $GLOBALS['VULCANUS_ODATA_BC_FETCH'];
unset($GLOBALS['VULCANUS_ODATA_BC_FETCH']);
$GLOBALS['VULCANUS_ODATA_BC_HTTP'] = static function (string $url, array $bcAuth) use (&$httpCalls): array {
    $httpCalls[] = ['url' => $url, 'user' => (string) ($bcAuth['user'] ?? '')];
    if (count($httpCalls) === 1) {
        return [
            'value' => [['No' => 'A']],
            '@odata.nextLink' => 'https://evil.example/ODataV4/steal',
        ];
    }
    return ['value' => [['No' => 'B']]];
};
$nextError = null;
try {
    vulcanus_bc_fetch_all(vulcanus_bc_entity_url('AssemblageKop', "No eq 'A'", []), $auth);
    fail('een nextLink naar een andere host moet stoppen');
} catch (Throwable $exception) {
    $nextError = $exception;
}
if (count($httpCalls) !== 1) {
    fail('de vreemde nextLink kreeg toch credentials, calls=' . count($httpCalls));
}
if ($nextError === null || strpos($nextError->getMessage(), 'onveilige origin') === false) {
    fail('nextLink-fout mist de origin-melding');
}
if (strpos($nextError->getMessage(), 'bc-secret') !== false || strpos($nextError->getMessage(), 'Authorization') !== false) {
    fail('nextLink-fout bevat credentials: ' . $nextError->getMessage());
}
$httpCalls = [];
$GLOBALS['VULCANUS_ODATA_BC_HTTP'] = static function (string $url, array $bcAuth) use (&$httpCalls): array {
    $httpCalls[] = $url;
    if (count($httpCalls) === 1) {
        return [
            'value' => [['No' => 'A']],
            '@odata.nextLink' => 'https://bc.example/ODataV4/Company(\'K\')/AssemblageKop?$skip=20',
        ];
    }
    return ['value' => [['No' => 'B']]];
};
$paged = vulcanus_bc_fetch_all(vulcanus_bc_entity_url('AssemblageKop', '', []), $auth);
if (count($paged) !== 2 || ($paged[1]['No'] ?? '') !== 'B' || count($httpCalls) !== 2) {
    fail('een nextLink op dezelfde https-origin moet wel gevolgd worden');
}
unset($GLOBALS['VULCANUS_ODATA_BC_HTTP']);
$GLOBALS['VULCANUS_ODATA_BC_FETCH'] = $savedFetch;

$basic = base64_encode('bcuser:bc-secret');
$redacted = vulcanus_redact_sensitive(
    'Authorization: Basic ' . $basic . "\nBearer mimir_test_key_should_not_leak https://bcuser:bc-secret@bc.example/x"
);
if (strpos($redacted, 'bc-secret') !== false || strpos($redacted, $basic) !== false || strpos($redacted, 'mimir_test_key_should_not_leak') !== false) {
    fail('redactie liet een geheim staan: ' . $redacted);
}
if (strpos($redacted, 'bcuser@') !== false) {
    fail('userinfo bleef in de URL staan: ' . $redacted);
}
$loggedBeforeRedact = fallback_count();
vulcanus_mimir_log_fallback(new Exception('Authorization: Basic ' . $basic . ' bc-secret'));
if (fallback_count() !== $loggedBeforeRedact + 1) {
    fail('fallback-log schreef de regel niet');
}
assert_no_secrets(fallback_log());
if (strpos(fallback_log(), $basic) !== false) {
    fail('log bevat een Authorization-token');
}

vulcanus_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$mimirCompany = 'Koninklijke van Twist';
$baseUrl = 'https://bc.example:7148/';
$environment = ['Production', 'Sandbox'];
$auth = ['mode' => 'basic', 'user' => 'primary', 'pass' => 'bc-secret'];
$auth_list = [
    'Production' => $auth,
    'Sandbox' => [
        'mode' => 'basic',
        'user' => 'sandboxuser',
        'pass' => 'bc-secret',
        'companies' => ['Koninklijke van Twist'],
    ],
];
$beforeCompany = count($calls);
mimir_set_transport(null);
$companyRows = vulcanus_odata_query('AssemblageKop', "No eq 'ASS1'", ['No'], 30);
$companyCall = $calls[count($calls) - 1] ?? null;
if (($companyRows[0]['No'] ?? '') !== 'WO-1' || !is_array($companyCall) || ($companyCall['user'] ?? '') !== 'sandboxuser') {
    fail('fallback gebruikte niet de auth_list-entry van het bedrijf: ' . json_encode($companyCall));
}
if (!is_array($companyCall) || strpos($companyCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company(') !== 0) {
    fail('fallback gebruikte niet de environment van het bedrijf: ' . json_encode($companyCall));
}
if (count($calls) !== $beforeCompany + 1) {
    fail('company-environment fallback deed niet precies één directe call');
}

$auth_list = [
    'Production' => $auth,
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandboxuser', 'pass' => 'bc-secret'],
];
if (vulcanus_bc_environment_for_company('Koninklijke van Twist') !== null) {
    fail('zonder bedrijfskoppeling mag de primaire environment niet gegokt worden');
}
vulcanus_mimir_circuit_reset();
$callsBeforeGuess = count($calls);
$guessError = null;
try {
    vulcanus_odata_query('AssemblageKop', "No eq 'ASS1'", [], 30);
    fail('zonder environment voor het bedrijf moet de Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $guessError = $exception;
}
if ($guessError === null || strpos($guessError->getMessage(), 'Mímir') === false || count($calls) !== $callsBeforeGuess) {
    fail('onbekende company-environment viel toch terug op de primaire auth');
}

$publishedBase = $GLOBALS['baseUrl'] ?? null;
$publishedEnv = $GLOBALS['environment'] ?? null;
vulcanus_publish_bc_auth_globals([
    'baseUrl' => 'https://published.example/ODataV4',
    'environment' => 'Sandbox',
    'auth' => ['mode' => 'basic', 'user' => 'from-file', 'pass' => 'bc-secret'],
    'noise' => 'niet-publiceren',
]);
if (($GLOBALS['baseUrl'] ?? '') !== 'https://published.example/ODataV4' || ($GLOBALS['environment'] ?? '') !== 'Sandbox') {
    fail('lazy auth.php-variabelen werden niet naar $GLOBALS gekopieerd');
}
if (isset($GLOBALS['noise'])) {
    fail('publish kopieerde een variabele buiten de BC-lijst');
}
$GLOBALS['baseUrl'] = $publishedBase;
$GLOBALS['environment'] = $publishedEnv;
$baseUrl = $savedBase;
$environment = $savedEnv;
$auth = $savedAuth;
$auth_list = $savedList;

echo "OK\n";
