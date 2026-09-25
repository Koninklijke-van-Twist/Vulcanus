<?php
/**
 * Live Business Central-reads via Mímir, met sample-fallback.
 *
 * Zonder $mimirApi, of met ?sample=1, blijven de bestaande sample_* functies gelden.
 * Interactieve rapporten gebruiken max_age 300 (vijf minuten Mímir-cache).
 */

declare(strict_types=1);

require_once __DIR__ . '/sample_data.php';
require_once __DIR__ . '/../odata.php';

/** Seconden die Mímir een interactieve rapportload mag cachen. */
const VULCANUS_MIMIR_MAX_AGE = 300;

/**
 * @var list<string>
 */
const VULCANUS_WO_LINE_SELECT = [
    'Line_No',
    'LVS_Work_Order_No',
    'Type',
    'No',
    'Description',
    'Quantity',
    'Unit_of_Measure_Code',
    'KVT_Extended_Text',
];

/**
 * @var list<string>
 */
const VULCANUS_ASS_LINE_SELECT = [
    'Line_No',
    'Document_No',
    'Type',
    'No',
    'Description',
    'Quantity',
    'Unit_of_Measure_Code',
    'KVT_Extended_Text',
];

/**
 * @var list<string>
 */
const VULCANUS_WO_HEADER_KEYS = [
    'No',
    'Main_Entity_Description',
    'Component_No',
    'Serial_No',
    'Task_Description',
    'Sell_to_Name',
    'Visit_Address',
    'Memo',
    'Created_By',
    'Created_Date_Time',
    'End_Date',
    'Status',
    'Start_Date',
];

/**
 * @var list<string>
 */
const VULCANUS_ASS_HEADER_KEYS = [
    'No',
    'Item_No',
    'Description',
    'Quantity',
    'Quantity_to_Assemble',
    'Assembled_Quantity',
    'Status',
    'Due_Date',
    'Starting_Date',
    'Location_Code',
    'Bin_Code',
    'Unit_of_Measure_Code',
    'LVS_Job_No',
    'Variant_Code',
];

class VulcanusNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly string $no
    ) {
        parent::__construct('Geen ' . $kind . ' gevonden voor ' . $no . '.');
    }
}

function vulcanus_mimir_enabled(): bool
{
    return mimir_enabled();
}

/**
 * ?sample=1 forceert sample-data, ook als Mímir aan staat.
 */
function vulcanus_sample_forced(): bool
{
    if (!isset($_GET['sample']) || is_array($_GET['sample'])) {
        return false;
    }
    $value = strtolower(trim((string) $_GET['sample']));
    return $value === '1' || $value === 'true' || $value === 'yes';
}

function vulcanus_use_mimir(): bool
{
    return vulcanus_mimir_enabled() && !vulcanus_sample_forced();
}

/**
 * ?_content=1 vraagt de print-HTML. Zonder die vlag komt alleen het laadscherm.
 */
function vulcanus_content_requested(): bool
{
    if (!isset($_GET['_content']) || is_array($_GET['_content'])) {
        return false;
    }
    return trim((string) $_GET['_content']) === '1';
}

/**
 * Auto-detect alleen als het nummer ASS of WO bevat (hoofdletterongevoelig, substring).
 * - ASS → assemblage, ook als er ook WO in staat (nooit werkplaats)
 * - anders WO → werkplaats (nooit assemblage)
 * - geen van beide → null
 *
 * Null betekent: niet gokken. AO, kale cijfers en andere productienummers blijven
 * null; de kiezer op index.php kiest dan het rapporttype.
 *
 * @return 'assemblage'|'werkplaats'|null
 */
function vulcanus_detect_report_type(string $no): ?string
{
    $upper = strtoupper($no);
    if (str_contains($upper, 'ASS')) {
        return 'assemblage';
    }
    if (str_contains($upper, 'WO')) {
        return 'werkplaats';
    }
    return null;
}

/**
 * Zonder ASS/WO blijft de handmatige keuze van de kiezer gelden.
 *
 * @param 'assemblage'|'werkplaats'|string $manual
 * @return 'assemblage'|'werkplaats'
 */
function vulcanus_resolve_report_type(string $no, string $manual): string
{
    $manual = $manual === 'assemblage' ? 'assemblage' : 'werkplaats';
    return vulcanus_detect_report_type($no) ?? $manual;
}

/**
 * Korte uitleg bij de kiezer. Bij null blijft de tekst bij de handmatige keuze.
 */
function vulcanus_type_choice_note(string $no): string
{
    $detected = vulcanus_detect_report_type($no);
    if ($detected === 'assemblage') {
        return 'ASS in het nummer: dit wordt een assemblageopdracht.';
    }
    if ($detected === 'werkplaats') {
        return 'WO in het nummer: dit wordt een werkplaatsorder.';
    }
    return 'Geen ASS of WO in het nummer. Kies zelf het rapporttype; die keuze blijft gelden.';
}

/**
 * Stuur door naar het andere rapport en behoud query-args (zoals sample).
 */
function vulcanus_redirect_to_report(string $type, string $no): never
{
    $target = $type === 'assemblage' ? 'assemblage.php' : 'werkplaatsorder.php';
    $query = $_GET;
    $query['no'] = $no;
    header('Location: ' . $target . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    exit;
}

function vulcanus_source_label(string $source): string
{
    return $source === 'mimir' ? 'live (Mímir)' : 'sample data';
}

/**
 * BC ISO-datum/tijd naar het printformaat van de sample (n/j/Y, tijd als die er is).
 * Middernacht en lege BC-datums (0001-01-01) tonen geen tijd c.q. niets.
 */
function vulcanus_format_bc_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0001-01-01')) {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}):(\d{2}))?/', $value, $match) !== 1) {
        return $value;
    }
    $date = (int) $match[2] . '/' . (int) $match[3] . '/' . $match[1];
    if (!isset($match[4])) {
        return $date;
    }
    $hour = (int) $match[4];
    $minute = $match[5];
    $second = $match[6];
    if ($hour === 0 && $minute === '00' && $second === '00') {
        return $date;
    }
    $ampm = $hour >= 12 ? 'PM' : 'AM';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }
    return $date . ' ' . $hour12 . ':' . $minute . ':' . $second . ' ' . $ampm;
}

function vulcanus_field_looks_like_date(string $key): bool
{
    return str_contains($key, 'Date') || str_contains($key, 'date');
}

function vulcanus_format_number(int|float|string $value): string
{
    if (is_string($value) && !is_numeric($value)) {
        return $value;
    }
    $number = (float) $value;
    if (floor($number) == $number) {
        return (string) (int) $number;
    }
    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

/**
 * Zet BC-velden om naar dezelfde string-keys als sample_data.
 *
 * @param array<string, mixed> $row
 * @return array<string, string>
 */
function vulcanus_normalize_record(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if ($value === null || is_array($value)) {
            $out[$key] = '';
            continue;
        }
        if (is_bool($value)) {
            $out[$key] = $value ? 'true' : 'false';
            continue;
        }
        if (is_int($value) || is_float($value)) {
            if (vulcanus_field_looks_like_date($key)) {
                $out[$key] = vulcanus_format_bc_datetime((string) $value);
            } else {
                $out[$key] = vulcanus_format_number($value);
            }
            continue;
        }
        $string = str_replace(["\r\n", "\r"], "\n", (string) $value);
        if (vulcanus_field_looks_like_date($key)) {
            $string = vulcanus_format_bc_datetime($string);
        }
        $out[$key] = $string;
    }
    return $out;
}

/**
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, string>>
 */
function vulcanus_normalize_lines(array $lines): array
{
    $lineKeys = ['Line_No', 'Type', 'No', 'Description', 'Quantity', 'Unit_of_Measure_Code', 'KVT_Extended_Text'];
    $normalized = [];
    foreach ($lines as $line) {
        $normalized[] = vulcanus_fill_keys(vulcanus_normalize_record($line), $lineKeys);
    }
    usort($normalized, static function (array $a, array $b): int {
        return ((int) ($a['Line_No'] ?? 0)) <=> ((int) ($b['Line_No'] ?? 0));
    });
    return $normalized;
}

/**
 * OData laat null-velden vaak weg. De printpagina leest dezelfde keys als sample_data.
 *
 * @param array<string, string> $row
 * @param list<string> $keys
 * @return array<string, string>
 */
function vulcanus_fill_keys(array $row, array $keys): array
{
    foreach ($keys as $key) {
        if (!isset($row[$key])) {
            $row[$key] = '';
        }
    }
    return $row;
}

/**
 * @param array<string, string> $header
 * @param list<string> $keys
 * @return array<string, string>
 */
function vulcanus_ensure_no(array $header, string $no, array $keys): array
{
    $header = vulcanus_fill_keys($header, $keys);
    if (trim($header['No']) === '') {
        $header['No'] = $no;
    }
    return $header;
}

/**
 * @return array{header: array<string, string>, lines: list<array<string, string>>, source: 'mimir'|'sample'}
 */
function fetch_werkplaatsorder(string $no): array
{
    if (!vulcanus_use_mimir()) {
        return [
            'header' => sample_werkplaatsorder_header($no),
            'lines' => sample_werkplaatsorder_lines(),
            'source' => 'sample',
        ];
    }

    $headers = mimir_query(
        'LVS_MainWorkOrderCard',
        mimir_odata_eq('No', $no),
        [],
        VULCANUS_MIMIR_MAX_AGE
    );
    if ($headers === []) {
        throw new VulcanusNotFoundException('werkplaatsorder', $no);
    }

    $lines = mimir_query(
        'Job_Planning_Lines',
        mimir_odata_eq('LVS_Work_Order_No', $no),
        VULCANUS_WO_LINE_SELECT,
        VULCANUS_MIMIR_MAX_AGE
    );

    return [
        'header' => vulcanus_ensure_no(vulcanus_normalize_record($headers[0]), $no, VULCANUS_WO_HEADER_KEYS),
        'lines' => vulcanus_normalize_lines($lines),
        'source' => 'mimir',
    ];
}

/**
 * @return array{header: array<string, string>, lines: list<array<string, string>>, source: 'mimir'|'sample'}
 */
function fetch_assemblage(string $no): array
{
    if (!vulcanus_use_mimir()) {
        $lines = sample_assemblage_lines();
        foreach ($lines as &$line) {
            $line['Document_No'] = $no;
        }
        unset($line);
        return [
            'header' => sample_assemblage_header($no),
            'lines' => $lines,
            'source' => 'sample',
        ];
    }

    $headers = mimir_query(
        'AssemblageKop',
        mimir_odata_eq('No', $no),
        [],
        VULCANUS_MIMIR_MAX_AGE
    );
    if ($headers === []) {
        throw new VulcanusNotFoundException('assemblageorder', $no);
    }

    $lines = mimir_query(
        'AssemblageRegels',
        mimir_odata_eq('Document_No', $no),
        VULCANUS_ASS_LINE_SELECT,
        VULCANUS_MIMIR_MAX_AGE
    );

    return [
        'header' => vulcanus_ensure_no(vulcanus_normalize_record($headers[0]), $no, VULCANUS_ASS_HEADER_KEYS),
        'lines' => vulcanus_normalize_lines($lines),
        'source' => 'mimir',
    ];
}

/**
 * Laadscherm voor assemblage.php / werkplaatsorder.php.
 * JavaScript haalt dezelfde URL met _content=1 op en vervangt het document.
 */
function vulcanus_report_loading_document(string $no): string
{
    $noEsc = h($no);
    $backHref = vulcanus_sample_forced() ? 'index.php?sample=1' : 'index.php';
    $backEsc = h($backHref);

    $query = $_GET;
    unset($query['_content']);
    $query['_content'] = '1';
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '' || $script === '.' || $script === '..') {
        $script = 'index.php';
    }
    $contentHref = h($script . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

    return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vulcanus – {$noEsc}</title>
  <link rel="stylesheet" href="assets/print.css">
</head>
<body>
  <div class="chooser is-loading screen-only" id="report-loading" aria-busy="true">
    <img class="chooser-logo" src="assets/kvt-crown.svg" alt="KVT" width="84" height="84">
    <p class="entered-no">{$noEsc}</p>
    <p class="loading-status" id="loading-status" role="status">
      <span class="spinner" aria-hidden="true"></span>
      Opdracht ophalen…
    </p>
    <div id="loading-error" hidden>
      <p class="loading-error">De opdracht kon niet worden opgehaald.</p>
      <p class="hint"><a href="{$backEsc}">← Terug naar Vulcanus</a></p>
    </div>
    <noscript>
      <style>#loading-status{display:none}</style>
      <p class="hint"><a href="{$contentHref}">Opdracht openen</a></p>
    </noscript>
  </div>
  <script>
  (function () {
    var statusEl = document.getElementById('loading-status');
    var errorEl = document.getElementById('loading-error');

    function showError() {
      if (statusEl) {
        statusEl.hidden = true;
      }
      if (errorEl) {
        errorEl.hidden = false;
      }
    }

    window.addEventListener('pageshow', function (event) {
      if (event.persisted && document.getElementById('loading-status')) {
        window.location.reload();
      }
    });

    var url;
    try {
      url = new URL(window.location.href);
      url.searchParams.set('_content', '1');
    } catch (ignore) {
      showError();
      return;
    }

    fetch(url.toString(), { credentials: 'same-origin' })
      .then(function (response) {
        return response.text();
      })
      .then(function (html) {
        if (!html || !String(html).trim()) {
          throw new Error('empty');
        }
        document.open();
        document.write(html);
        document.close();
      })
      .catch(function () {
        showError();
      });
  })();
  </script>
</body>
</html>
HTML;
}

function vulcanus_render_report_loading(string $no): never
{
    echo vulcanus_report_loading_document($no);
    exit;
}

function vulcanus_render_message_page(int $status, string $title, string $message, string $detail = ''): never
{
    http_response_code($status);
    $titleEsc = h($title);
    $messageEsc = h($message);
    $detailHtml = $detail !== '' ? '<p class="hint">' . h($detail) . '</p>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vulcanus – {$titleEsc}</title>
  <link rel="stylesheet" href="assets/print.css">
</head>
<body>
  <div class="chooser">
    <h1>{$titleEsc}</h1>
    <p class="lead">{$messageEsc}</p>
    {$detailHtml}
    <p class="hint"><a href="index.php">← Terug naar Vulcanus</a></p>
  </div>
</body>
</html>
HTML;
    exit;
}

function vulcanus_render_not_found(VulcanusNotFoundException $error): never
{
    vulcanus_render_message_page(404, 'Niet gevonden', $error->getMessage());
}

function vulcanus_render_mimir_error(Throwable $error): never
{
    vulcanus_render_message_page(
        502,
        'Mímir-fout',
        'De live gegevens konden niet worden opgehaald via Mímir.',
        $error->getMessage()
    );
}
