<?php
/**
 * Vulcanus – kiezer: Werkplaatsorder of Assemblage (sample / later live via Mímir).
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';

$defaultWo = 'WO26091234';
$defaultAo = 'ASS26094567';
$type = $_GET['type'] ?? 'werkplaats';
$no   = trim((string) ($_GET['no'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['go'])) {
    $type = ($_GET['type'] ?? 'werkplaats') === 'assemblage' ? 'assemblage' : 'werkplaats';
    if ($no === '') {
        $no = $type === 'assemblage' ? $defaultAo : $defaultWo;
    }
    $target = $type === 'assemblage' ? 'assemblage.php' : 'werkplaatsorder.php';
    header('Location: ' . $target . '?no=' . rawurlencode($no));
    exit;
}

$noValue = $no !== '' ? $no : $defaultWo;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vulcanus – kiezer</title>
  <link rel="manifest" href="site.webmanifest">
  <link rel="stylesheet" href="assets/print.css">
</head>
<body>
  <div class="chooser">
    <h1>Vulcanus</h1>
    <p class="lead">
      Printbare Werkplaatsopdracht en Assemblageopdracht (A4).
      Momenteel sample-data; live data volgt via Mímir.
    </p>

    <form method="get" action="index.php">
      <input type="hidden" name="go" value="1">

      <label for="type">Rapport</label>
      <select name="type" id="type">
        <option value="werkplaats"<?= $type !== 'assemblage' ? ' selected' : '' ?>>Werkplaatsorder</option>
        <option value="assemblage"<?= $type === 'assemblage' ? ' selected' : '' ?>>Assemblage</option>
      </select>

      <label for="no">Werkorder / Assemblagenr.</label>
      <input type="text" name="no" id="no" value="<?= htmlspecialchars($noValue, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="<?= htmlspecialchars($defaultWo, ENT_QUOTES, 'UTF-8') ?>">

      <div class="actions">
        <button type="submit">Open rapport</button>
        <a class="btn secondary" href="werkplaatsorder.php?no=<?= rawurlencode($defaultWo) ?>">Sample WO</a>
        <a class="btn secondary" href="assemblage.php?no=<?= rawurlencode($defaultAo) ?>">Sample AO</a>
      </div>
    </form>

    <p class="hint">
      Lokaal: <code>php -S localhost:8765 -t web</code>
      · daarna <code>http://localhost:8765/</code>
    </p>
  </div>
  <script>
    document.getElementById('type').addEventListener('change', function () {
      var inp = document.getElementById('no');
      if (this.value === 'assemblage') {
        if (!inp.value || inp.value.indexOf('WO') === 0) inp.value = <?= json_encode($defaultAo) ?>;
      } else {
        if (!inp.value || inp.value.indexOf('ASS') === 0) inp.value = <?= json_encode($defaultWo) ?>;
      }
    });
  </script>
</body>
</html>
