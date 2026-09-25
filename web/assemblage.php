<?php
/**
 * Vulcanus – Assemblageopdracht (printbare A4).
 * Hybrid: PDF-compact header + duidelijke regels-tabel.
 * Live: AssemblageKop + AssemblageRegels via Mímir als $mimirApi gezet is.
 *
 * RDL-quirks:
 * - No == "INSTRUCTIE" → Quantity verbergen
 * - Unit_of_Measure_Code == "HR" → toon "UUR" i.p.v. ST
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/barcode128.php';
require_once __DIR__ . '/lib/live_data.php';

$no = trim((string) ($_GET['no'] ?? 'ASS26094567'));
if ($no === '') {
    $no = 'ASS26094567';
}

if (vulcanus_detect_report_type($no) === 'werkplaats') {
    vulcanus_redirect_to_report('werkplaats', $no);
}

try {
    $report = fetch_assemblage($no);
} catch (VulcanusNotFoundException $e) {
    vulcanus_render_not_found($e);
} catch (Throwable $e) {
    vulcanus_render_mimir_error($e);
}

$header = $report['header'];
$lines = $report['lines'];
$sourceLabel = vulcanus_source_label($report['source']);
$barcodeText = barcode_digits_only($header['No']);
$barcodeSvg  = render_code128b_svg($barcodeText, 2, 36, false);

$title = 'Assemblageopdracht';
$printedAt = print_timestamp();
$gedaan = (string) $header['Assembled_Quantity'] . '/' . (string) $header['Quantity'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vulcanus – <?= h($title) ?> <?= h($header['No']) ?></title>
  <link rel="stylesheet" href="assets/print.css">
</head>
<body>
  <p class="no-print-hint screen-only">
    <a href="index.php">← Vulcanus</a>
    · <?= h($sourceLabel) ?> ·
    <a href="javascript:window.print()">Afdrukken</a>
  </p>

  <article class="page">
    <header class="page-header">
      <div class="page-header-top">
        <div class="title-block">
          <h1><?= h($title) ?></h1>
          <div class="subtitle"><?= h($header['Description']) ?></div>
          <div class="print-ts"><?= h($printedAt) ?></div>
          <?php if (!empty($header['Variant_Code'])): ?>
            <div class="print-ts"><?= h((string) $header['Variant_Code']) ?></div>
          <?php endif; ?>
        </div>
        <img class="logo-kvt" src="assets/kvt-crown.svg" alt="KVT" width="42" height="42">
      </div>
      <hr class="rule-solid">

      <div class="header-meta cols-3" aria-label="Assemblagegegevens">
        <div class="col">
          <div class="row"><span class="lbl">Datum</span><span class="val"><?= h($header['Starting_Date']) ?></span></div>
          <div class="row"><span class="lbl">Opleverdatum</span><span class="val"><?= h($header['Due_Date']) ?></span></div>
          <div class="row"><span class="lbl">Taak</span><span class="val"><?= h((string) ($header['LVS_Job_No'] ?? '')) ?></span></div>
        </div>
        <div class="col">
          <div class="row"><span class="lbl">Te assembleren</span><span class="val"><?= h((string) $header['Quantity_to_Assemble']) ?></span></div>
          <div class="row"><span class="lbl">Gedaan</span><span class="val"><?= h($gedaan) ?></span></div>
        </div>
        <div class="col">
          <div class="row"><span class="lbl">Bin</span><span class="val"><?= h($header['Bin_Code']) ?></span></div>
          <div class="row"><span class="lbl">Status</span><span class="val"><?= h($header['Status']) ?></span></div>
          <div class="row"><span class="lbl">Locatiecode</span><span class="val"><?= h($header['Location_Code']) ?></span></div>
        </div>
      </div>

      <div class="order-barcode">
        <div class="order-no"><?= h($header['No']) ?></div>
        <?= $barcodeSvg ?>
      </div>
    </header>

    <hr class="rule-dotted">

    <table class="lines">
      <thead>
        <tr>
          <th class="qty">Aantal</th>
          <th class="no">Nr.</th>
          <th class="desc">Omschrijving</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
          <?php
            $isInstr = ($line['No'] ?? '') === 'INSTRUCTIE';
            $rowClass = $isInstr ? 'instructie' : '';
            $qtyShow = format_assemblage_qty($line);
          ?>
          <tr class="<?= h($rowClass) ?>">
            <td class="qty"><?= h($qtyShow) ?></td>
            <td class="no"><?= h((string) $line['No']) ?></td>
            <td class="desc">
              <span class="desc-main"><?= h((string) $line['Description']) ?></span>
              <?php if (trim((string) ($line['KVT_Extended_Text'] ?? '')) !== ''): ?>
                <span class="ext-text"><?= nl2br_h($line['KVT_Extended_Text']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <footer class="report-footer">
      <span>Pagina 1</span>
    </footer>
  </article>
</body>
</html>
