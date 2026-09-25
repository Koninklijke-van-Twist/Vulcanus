<?php
/**
 * Lokale tests voor Mímir-filterquoting, ASS/WO-herkenning en sample-fallback.
 * Geen netwerk en geen web/auth.php.
 *
 *   php tests/mimir_live_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../web/lib/live_data.php';

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "OK  {$message}\n");
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function reset_mimir_state(): void
{
    $GLOBALS['mimirApi'] = '';
    unset($GLOBALS['mimirBase'], $GLOBALS['mimirCompany']);
    unset($_GET['sample'], $_GET['_content']);
    mimir_set_transport(null);
}

function capture_transport(callable $responder): stdClass
{
    $captured = new stdClass();
    $captured->calls = [];
    $captured->options = [];
    $captured->urls = [];
    mimir_set_transport(static function (string $url, array $options) use ($captured, $responder): array {
        $body = json_decode((string) ($options[CURLOPT_POSTFIELDS] ?? ''), true);
        if (!is_array($body)) {
            $body = [];
        }
        $captured->calls[] = $body;
        $captured->options[] = $options;
        $captured->urls[] = $url;
        return $responder($body);
    });
    return $captured;
}

reset_mimir_state();

check(mimir_odata_literal('WO26091234') === "'WO26091234'", 'quote plain order number');
check(mimir_odata_literal("O'Brien") === "'O''Brien'", 'double embedded single quote');
check(mimir_odata_literal("a''b") === "'a''''b'", 'double each existing quote');
check(mimir_odata_eq('No', "WO'1") === "No eq 'WO''1'", 'eq filter quotes the value');
check(mimir_odata_eq('LVS_Work_Order_No', 'WO26091234') === "LVS_Work_Order_No eq 'WO26091234'", 'work-order line filter');
check(mimir_odata_eq('Document_No', 'ASS26094567') === "Document_No eq 'ASS26094567'", 'assemblage line filter');

check(vulcanus_detect_report_type('ASS26094567') === 'assemblage', 'ASS prefix is assemblage');
check(vulcanus_detect_report_type('ass26094567') === 'assemblage', 'ass is case-insensitive');
check(vulcanus_detect_report_type('xxASSyy') === 'assemblage', 'ASS substring is assemblage');
check(vulcanus_detect_report_type('WO26091234') === 'werkplaats', 'WO prefix is werkplaats');
check(vulcanus_detect_report_type('wo99') === 'werkplaats', 'wo is case-insensitive');
check(vulcanus_detect_report_type('preWO') === 'werkplaats', 'WO substring is werkplaats');
check(vulcanus_detect_report_type('WO-ASS-1') === 'assemblage', 'ASS wins when both markers are present');
check(vulcanus_detect_report_type('12345') === null, 'neither marker keeps manual choice');
check(vulcanus_detect_report_type('') === null, 'empty number is not detected');
check(vulcanus_detect_report_type('AO26094567') === null, 'AO is not ASS and is not auto-detected');
check(vulcanus_detect_report_type('104582') === null, 'plain production number is not auto-detected');
check(vulcanus_detect_report_type('20-15202129') === null, 'job-like number is not auto-detected');
check(vulcanus_detect_report_type('W01234') === null, 'W followed by zero is not the letters WO');
check(vulcanus_resolve_report_type('104582', 'assemblage') === 'assemblage', 'neither keeps the assemblage choice');
check(vulcanus_resolve_report_type('104582', 'werkplaats') === 'werkplaats', 'neither keeps the werkplaats choice');
check(vulcanus_resolve_report_type('AO2609', 'assemblage') === 'assemblage', 'AO still follows the selector');
check(vulcanus_resolve_report_type('ASS1', 'werkplaats') === 'assemblage', 'ASS overrides a werkplaats choice');
check(vulcanus_resolve_report_type('wo9', 'assemblage') === 'werkplaats', 'WO overrides an assemblage choice');
check(str_contains(vulcanus_type_choice_note('104582'), 'Kies zelf'), 'neither note asks for a manual choice');
check(str_contains(vulcanus_type_choice_note('ASS1'), 'assemblage'), 'ASS note names assemblage');
check(str_contains(vulcanus_type_choice_note('WO1'), 'werkplaats'), 'WO note names werkplaats');
check(vulcanus_mimir_enabled() === false, 'empty key disables Mímir');

$sampleWo = fetch_werkplaatsorder('WO26091234');
check($sampleWo['source'] === 'sample', 'werkplaats uses sample when Mímir is off');
check($sampleWo['header']['No'] === 'WO26091234', 'sample werkplaats header keeps the requested number');
check(($sampleWo['lines'][0]['No'] ?? '') === 'PK-T414086', 'sample werkplaats lines stay available');

$sampleAss = fetch_assemblage('ASS999');
check($sampleAss['source'] === 'sample', 'assemblage uses sample when Mímir is off');
check($sampleAss['header']['No'] === 'ASS999', 'sample assemblage header keeps the requested number');
check(($sampleAss['lines'][0]['Document_No'] ?? '') === 'ASS999', 'sample assemblage lines adopt the requested number');

$mimirApi = 'mimir_test_key';
$_GET['sample'] = '1';
$sampleForcedCalled = false;
mimir_set_transport(static function () use (&$sampleForcedCalled): array {
    $sampleForcedCalled = true;
    return ['code' => 500, 'raw' => '{}'];
});
$forced = fetch_werkplaatsorder('WO26091234');
check($forced['source'] === 'sample' && $sampleForcedCalled === false, '?sample=1 forces sample even with Mímir on');
check(vulcanus_sample_forced() === true, 'sample=1 is recognised');

reset_mimir_state();
$mimirApi = 'mimir_test_key';
check(vulcanus_mimir_enabled() === true, 'trimmed key enables Mímir');

$captured = capture_transport(static function (array $body): array {
    $table = (string) ($body['table'] ?? '');
    if ($table === 'LVS_MainWorkOrderCard') {
        return [
            'code' => 200,
            'raw' => json_encode([
                'value' => [[
                    'No' => "WO'1",
                    'Created_Date_Time' => '2026-03-02T14:05:06Z',
                    'End_Date' => '2026-02-28T00:00:00Z',
                    'Sell_to_Name' => "O'Brien",
                    'Memo' => "regel 1\r\nregel 2",
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }
    if ($table === 'Job_Planning_Lines') {
        return [
            'code' => 200,
            'raw' => json_encode([
                'value' => [
                    [
                        'Line_No' => 30000,
                        'No' => 'INSTRUCTIE',
                        'Description' => 'Lees dit',
                        'Quantity' => 1,
                        'Unit_of_Measure_Code' => 'ST',
                        'KVT_Extended_Text' => "a\r\nb",
                    ],
                    [
                        'Line_No' => 10000,
                        'No' => 'MWORKSHOP',
                        'Description' => 'Uren',
                        'Quantity' => 6,
                        'Unit_of_Measure_Code' => 'HR',
                        'KVT_Extended_Text' => '',
                    ],
                ],
            ]),
        ];
    }
    return ['code' => 500, 'raw' => json_encode(['error' => 'unexpected table ' . $table])];
});

$liveWo = fetch_werkplaatsorder("WO'1");
check($liveWo['source'] === 'mimir', 'werkplaats source is mimir when enabled');
check(count($captured->calls) === 2, 'werkplaats issues header and line queries');
check(($captured->calls[0]['table'] ?? '') === 'LVS_MainWorkOrderCard', 'header table is LVS_MainWorkOrderCard');
check(($captured->calls[0]['filter'] ?? '') === "No eq 'WO''1'", 'header filter quotes the apostrophe');
check(!array_key_exists('select', $captured->calls[0]), 'header query does not send select');
check(($captured->calls[0]['company'] ?? '') === 'Koninklijke van Twist', 'default company');
check(($captured->calls[0]['max_age'] ?? null) === 300, 'interactive max_age is 300');
check(($captured->calls[0]['top'] ?? null) === 0, 'top 0 asks Mímir for the full set');
check(($captured->calls[1]['table'] ?? '') === 'Job_Planning_Lines', 'line table is Job_Planning_Lines');
check(($captured->calls[1]['filter'] ?? '') === "LVS_Work_Order_No eq 'WO''1'", 'line filter uses LVS_Work_Order_No');
check(($captured->calls[1]['select'] ?? null) === VULCANUS_WO_LINE_SELECT, 'werkplaats line select');
check(str_ends_with($captured->urls[0] ?? '', '/query.php'), 'posts to query.php');
check(($captured->urls[0] ?? '') === 'https://sleutels.kvt.nl/mimir/api/query.php', 'default Mímir base URL');
check(($captured->options[0][CURLOPT_FOLLOWLOCATION] ?? null) === false, 'redirects are not followed');
check(($captured->options[0][CURLOPT_USERAGENT] ?? '') === 'Vulcanus-MimirClient/1.0', 'Vulcanus user agent');
check(($captured->options[0][CURLOPT_CONNECTTIMEOUT] ?? 0) === 30, 'connect timeout 30');
check(($captured->options[0][CURLOPT_TIMEOUT] ?? 0) === 600, 'total timeout 600');
$headers = $captured->options[0][CURLOPT_HTTPHEADER] ?? [];
check(in_array('Authorization: Bearer mimir_test_key', $headers, true), 'bearer auth header');
check(in_array('X-API-Key: mimir_test_key', $headers, true), 'X-API-Key header');
check(in_array('Accept: application/json', $headers, true), 'Accept json');
check(in_array('Content-Type: application/json', $headers, true), 'Content-Type json');
check($liveWo['header']['Created_Date_Time'] === '3/2/2026 2:05:06 PM', 'datetime prints like the sample clock');
check($liveWo['header']['End_Date'] === '2/28/2026', 'midnight datetime prints as a date');
check($liveWo['header']['Memo'] === "regel 1\nregel 2", 'CRLF in memo becomes LF');
check(($liveWo['lines'][0]['No'] ?? '') === 'MWORKSHOP', 'lines sort by Line_No ascending');
check(($liveWo['lines'][1]['No'] ?? '') === 'INSTRUCTIE', 'later Line_No stays second');
check(format_werkplaats_qty($liveWo['lines'][0]) === '6 UUR', 'HR still prints as UUR');
check(format_werkplaats_qty($liveWo['lines'][1]) === '', 'INSTRUCTIE still hides quantity');
check(vulcanus_source_label('mimir') === 'live (Mímir)', 'live screen label');
check(vulcanus_source_label('sample') === 'sample data', 'sample screen label');

reset_mimir_state();
$mimirApi = 'mimir_test_key';
$mimirBase = 'https://mimir.test/api/';
$mimirCompany = 'Andere BV';
$assCaptured = capture_transport(static function (array $body): array {
    $table = (string) ($body['table'] ?? '');
    if ($table === 'AssemblageKop') {
        return [
            'code' => 200,
            'raw' => json_encode(['value' => [[
                'No' => 'ASS26094567',
                'Description' => 'Set',
                'Starting_Date' => '2026-10-08',
                'Due_Date' => '0001-01-01T00:00:00Z',
                'Quantity' => 3,
                'Quantity_to_Assemble' => 0,
                'Assembled_Quantity' => 3.0,
            ]]]),
        ];
    }
    return [
        'code' => 200,
        'raw' => json_encode(['value' => [
            ['Line_No' => 20, 'Document_No' => 'ASS26094567', 'No' => 'B', 'Description' => 'twee', 'Quantity' => 1, 'Unit_of_Measure_Code' => 'ST'],
            ['Line_No' => 10, 'Document_No' => 'ASS26094567', 'No' => 'A', 'Description' => 'een', 'Quantity' => 2, 'Unit_of_Measure_Code' => 'ST'],
        ]]),
    ];
});
$liveAss = fetch_assemblage('ASS26094567');
check($liveAss['source'] === 'mimir', 'assemblage source is mimir');
check(($assCaptured->urls[0] ?? '') === 'https://mimir.test/api/query.php', 'mimirBase is trimmed and used');
check(($assCaptured->calls[0]['company'] ?? '') === 'Andere BV', 'mimirCompany override');
check(($assCaptured->calls[0]['table'] ?? '') === 'AssemblageKop', 'assemblage header table');
check(($assCaptured->calls[0]['filter'] ?? '') === "No eq 'ASS26094567'", 'assemblage header filter');
check(($assCaptured->calls[1]['table'] ?? '') === 'AssemblageRegels', 'assemblage line table');
check(($assCaptured->calls[1]['filter'] ?? '') === "Document_No eq 'ASS26094567'", 'assemblage line filter');
check(($assCaptured->calls[1]['select'] ?? null) === VULCANUS_ASS_LINE_SELECT, 'assemblage line select');
check($liveAss['header']['Starting_Date'] === '10/8/2026', 'ISO date prints month/day/year');
check($liveAss['header']['Variant_Code'] === '', 'missing header fields stay empty strings');
check($liveAss['header']['Due_Date'] === '', 'BC blank date 0001-01-01 is empty');
check($liveAss['header']['Quantity'] === '3', 'whole-number quantity stays a plain integer string');
check($liveAss['header']['Assembled_Quantity'] === '3', 'float 3.0 prints as 3');
check(($liveAss['lines'][0]['No'] ?? '') === 'A', 'assemblage lines sort by Line_No');

reset_mimir_state();
$mimirBase = 'HTTPS://mimir.test/api/';
check(mimir_base_url() === 'HTTPS://mimir.test/api', 'https scheme is accepted regardless of case');

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://mimir.test/api/';
$cleartextCalled = false;
mimir_set_transport(static function () use (&$cleartextCalled): array {
    $cleartextCalled = true;
    return ['code' => 200, 'raw' => '{"value":[]}'];
});
$rejectedCleartext = false;
try {
    mimir_query('AssemblageKop', mimir_odata_eq('No', 'ASS1'), [], 300);
} catch (Exception $error) {
    $rejectedCleartext = $error->getMessage() === 'Mímir-basis-URL moet https zijn ($mimirBase).';
}
check($rejectedCleartext && $cleartextCalled === false, 'non-https mimirBase is rejected before credentials are sent');

reset_mimir_state();
$mimirApi = 'mimir_test_key';
mimir_set_transport(static function (): array {
    return ['code' => 200, 'raw' => json_encode(['value' => []])];
});
$notFound = false;
try {
    fetch_assemblage('ASS-MISSING');
} catch (VulcanusNotFoundException $error) {
    $notFound = $error->getMessage() === 'Geen assemblageorder gevonden voor ASS-MISSING.';
}
check($notFound, 'empty header raises a Dutch not-found error');

reset_mimir_state();
$mimirApi = '  mimir_test_key  ';
mimir_set_transport(static function (): array {
    return ['code' => 503, 'raw' => json_encode(['error' => 'bezet'])];
});
$httpError = false;
try {
    fetch_werkplaatsorder('WO1');
} catch (VulcanusNotFoundException $error) {
    $httpError = false;
} catch (Exception $error) {
    $httpError = str_contains($error->getMessage(), 'Mímir HTTP 503: bezet');
}
check($httpError, 'HTTP errors surface as exceptions');
check(mimir_api_key() === 'mimir_test_key', 'API key is trimmed and not rewritten');

reset_mimir_state();
$mimirApi = 'mimir_test_key';
mimir_set_transport(static function (): array {
    return ['code' => 200, 'raw' => '{'];
});
$badJson = false;
try {
    mimir_query('AssemblageKop', mimir_odata_eq('No', 'ASS1'), [], 300);
} catch (Exception $error) {
    $badJson = $error->getMessage() === 'Mímir gaf ongeldige JSON terug.';
}
check($badJson, 'invalid JSON is an error');

reset_mimir_state();
unset($_GET['_content']);
check(vulcanus_content_requested() === false, 'missing _content stays on the loading shell');
$_GET['_content'] = '1';
check(vulcanus_content_requested() === true, '_content=1 requests the report body');
$_GET['_content'] = ' 1 ';
check(vulcanus_content_requested() === true, 'surrounding space still counts as _content=1');
$_GET['_content'] = '0';
check(vulcanus_content_requested() === false, '_content=0 stays on the loading shell');
$_GET['_content'] = 'true';
check(vulcanus_content_requested() === false, 'only _content=1 skips the loading shell');
$_GET['_content'] = ['1'];
check(vulcanus_content_requested() === false, 'array _content is ignored');

$_GET = ['no' => 'ASS<1>', 'sample' => '1'];
$_SERVER['SCRIPT_NAME'] = '/assemblage.php';
$loading = vulcanus_report_loading_document('ASS<1>');
check(str_contains($loading, 'Opdracht ophalen…'), 'loading shell says the order is being fetched');
check(str_contains($loading, 'class="spinner"'), 'loading shell includes the spinner');
check(str_contains($loading, "credentials: 'same-origin'"), 'content fetch stays same-origin');
check(str_contains($loading, "searchParams.set('_content', '1')"), 'content fetch sets _content=1');
check(str_contains($loading, 'document.open'), 'fetched HTML replaces the document');
check(str_contains($loading, 'ASS&lt;1&gt;'), 'order number is escaped on the loading shell');
check(str_contains($loading, 'index.php?sample=1'), 'sample=1 survives the back link');
check(str_contains($loading, 'assemblage.php?no=ASS%3C1%3E&amp;sample=1&amp;_content=1'), 'noscript link keeps no, sample and _content');
check(str_contains($loading, 'De opdracht kon niet worden opgehaald.'), 'network failure copy is Dutch');
check(!str_contains($loading, 'class="lines"'), 'loading shell is not the print layout');
check(!str_contains($loading, 'fetch_assemblage'), 'loading shell does not run the report fetch');

$_GET = ['sample' => 'yes'];
check(str_contains(vulcanus_report_loading_document('WO1'), 'href="index.php?sample=1"'), 'sample=yes still links back with sample=1');
$_GET = [];
$plainBack = vulcanus_report_loading_document('104582');
check(str_contains($plainBack, 'href="index.php"'), 'back link omits sample when it was not forced');
check(!str_contains($plainBack, 'sample=1'), 'unstyled load does not invent sample=1');

$indexSource = file_get_contents(__DIR__ . '/../web/index.php');
check(is_string($indexSource) && str_contains($indexSource, "indexOf('ASS')") && str_contains($indexSource, "indexOf('WO')"), 'chooser script checks ASS before WO');
check(is_string($indexSource) && str_contains($indexSource, 'assemblage.php') && str_contains($indexSource, 'werkplaatsorder.php'), 'chooser script targets both report pages');
check(is_string($indexSource) && str_contains($indexSource, 'Opdracht ophalen…'), 'chooser script shows the Dutch loading text');
$css = file_get_contents(__DIR__ . '/../web/assets/print.css');
check(is_string($css) && str_contains($css, '@keyframes vulcanus-spin'), 'spinner animation lives in print.css');
check(is_string($css) && str_contains($css, '.spinner') && str_contains($css, 'display: none !important'), 'print rules hide the spinner');

reset_mimir_state();

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "all passed\n");
exit(0);
