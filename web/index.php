<?php
/**
 * Vulcanus – ordernummer invoeren.
 * ASS → assemblage, WO → werkplaats. Zonder die letters: twee grote knoppen, geen gok.
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/live_data.php';

/**
 * Querystring voor een rapportlink. sample=1 blijft staan wanneer gevraagd.
 */
function vulcanus_index_query(string $no): string
{
    $params = ['no' => $no];
    if (vulcanus_sample_forced()) {
        $params['sample'] = '1';
    }
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

$no = trim((string) ($_GET['no'] ?? ''));
$numberWasSent = array_key_exists('no', $_GET);
$detected = $no === '' ? null : vulcanus_detect_report_type($no);

if ($no !== '' && $detected !== null) {
    $target = $detected === 'assemblage' ? 'assemblage.php' : 'werkplaatsorder.php';
    header('Location: ' . $target . '?' . vulcanus_index_query($no));
    exit;
}

$showChoice = $no !== '' && $detected === null;
$showMissingNumber = $numberWasSent && $no === '';

$choiceQuery = $showChoice ? vulcanus_index_query($no) : '';
$backHref = vulcanus_sample_forced() ? 'index.php?sample=1' : 'index.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vulcanus</title>
  <link rel="manifest" href="site.webmanifest">
  <link rel="stylesheet" href="assets/print.css">
</head>
<body>
  <div class="chooser">
    <img class="chooser-logo" src="assets/kvt-crown.svg" alt="KVT" width="84" height="84">

    <?php if ($showChoice): ?>
      <p class="entered-no"><?= htmlspecialchars($no, ENT_QUOTES, 'UTF-8') ?></p>
      <p class="choice-lead">Welke opdracht?</p>
      <div class="choice-list">
        <a class="choice" href="assemblage.php?<?= htmlspecialchars($choiceQuery, ENT_QUOTES, 'UTF-8') ?>">Assemblageopdracht</a>
        <a class="choice" href="werkplaatsorder.php?<?= htmlspecialchars($choiceQuery, ENT_QUOTES, 'UTF-8') ?>">Werkplaatsopdracht</a>
      </div>
      <a class="back" href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>">Ander nummer</a>
    <?php else: ?>
      <form method="get" action="index.php">
        <?php if (vulcanus_sample_forced()): ?>
          <input type="hidden" name="sample" value="1">
        <?php endif; ?>
        <label for="no">Ordernummer</label>
        <input type="text" name="no" id="no" required autofocus autocomplete="off" enterkeyhint="go"
               value="">
        <?php if ($showMissingNumber): ?>
          <p class="field-error">Vul een ordernummer in.</p>
        <?php endif; ?>
        <button type="submit">Doorgaan</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
