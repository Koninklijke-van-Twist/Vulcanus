<?php
/**
 * Vulcanus – Werkplaatsorder (printbare A4).
 * Hybrid: PDF-compact header + duidelijke regels-tabel.
 * Sample data; live Mímir wiring next: LVS_MainWorkOrderCard + Job_Planning_Lines.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/barcode128.php';
require_once __DIR__ . '/lib/sample_data.php';

$no = trim((string) ($_GET['no'] ?? 'WO26091234'));
if ($no === '') {
    $no = 'WO26091234';
}

$header = sample_werkplaatsorder_header($no);
$lines  = sample_werkplaatsorder_lines();
$barcodeText = barcode_digits_only($header['No']);
$barcodeSvg  = render_code128b_svg($barcodeText, 2, 36, false);

$title = 'Werkplaatsopdracht';
$printedAt = print_timestamp();
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
    · sample data ·
    <a href="javascript:window.print()">Afdrukken</a>
  </p>

  <article class="page">
    <header class="page-header">
      <div class="page-header-top">
        <div class="title-block">
          <h1><?= h($title) ?></h1>
          <div class="print-ts"><?= h($printedAt) ?></div>
        </div>
        <img class="logo-kvt" src="assets/kvt-crown.svg" alt="KVT" width="42" height="42">
      </div>
      <hr class="rule-solid">

      <div class="header-meta" aria-label="Werkordergegevens">
        <div class="col">
          <div class="row"><span class="lbl">Datum</span><span class="val"><?= h($header['Created_Date_Time']) ?></span></div>
          <div class="row"><span class="lbl">Aangemaakt door</span><span class="val"><?= h($header['Created_By']) ?></span></div>
          <div class="row"><span class="lbl">Opleverdatum</span><span class="val"><?= h($header['End_Date']) ?></span></div>
          <div class="row"><span class="lbl">Taak</span><span class="val"><?= h($header['Task_Description']) ?></span></div>
        </div>
        <div class="col">
          <div class="plain"><?= h($header['Sell_to_Name']) ?></div>
          <div class="plain"><?= h($header['Visit_Address']) ?></div>
          <div class="row"><span class="lbl">SN</span><span class="val"><?= h($header['Serial_No']) ?></span></div>
          <div class="row"><span class="lbl">Comp</span><span class="val"><?= h($header['Component_No']) ?></span></div>
          <div class="row"><span class="lbl">Servicelocatie</span><span class="val"><?= h($header['Main_Entity_Description']) ?></span></div>
        </div>
      </div>

      <div class="order-barcode">
        <div class="order-no"><?= h($header['No']) ?></div>
        <?= $barcodeSvg ?>
      </div>
    </header>

    <hr class="rule-dotted">

    <?php if (trim((string) ($header['Memo'] ?? '')) !== ''): ?>
    <aside class="memo-block">
      <div class="memo-body"><?= nl2br_h($header['Memo']) ?></div>
    </aside>
    <hr class="rule-dotted">
    <?php endif; ?>

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
          ?>
          <tr class="<?= h($rowClass) ?>">
            <td class="qty"><?= h(format_werkplaats_qty($line)) ?></td>
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
