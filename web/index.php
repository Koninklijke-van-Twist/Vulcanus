<?php
/**
 * Vulcanus – kiezer: Werkplaatsorder of Assemblage.
 * Live via Mímir als $mimirApi gezet is; anders sample-data.
 * ASS in het nummer → assemblage; WO → werkplaatsorder.
 * Zonder ASS en zonder WO blijft de keuze in de rapport-select gelden.
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib/live_data.php';

$defaultWo = 'WO26091234';
$defaultAo = 'ASS26094567';
$type = $_GET['type'] ?? 'werkplaats';
$no   = trim((string) ($_GET['no'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['go'])) {
    $type = ($_GET['type'] ?? 'werkplaats') === 'assemblage' ? 'assemblage' : 'werkplaats';
    if ($no === '') {
        $no = $type === 'assemblage' ? $defaultAo : $defaultWo;
    }
    // ASS/WO overrulen de select. Zonder die letters blijft $type de keuze van de gebruiker.
    $type = vulcanus_resolve_report_type($no, $type);
    $target = $type === 'assemblage' ? 'assemblage.php' : 'werkplaatsorder.php';
    $params = ['no' => $no];
    if (vulcanus_sample_forced()) {
        $params['sample'] = '1';
    }
    header('Location: ' . $target . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
}

$noValue = $no !== '' ? $no : $defaultWo;
$detectedOnForm = vulcanus_detect_report_type($noValue);
if ($detectedOnForm !== null) {
    $type = $detectedOnForm;
}
$typeNote = vulcanus_type_choice_note($noValue);
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
      Live via Mímir wanneer <code>$mimirApi</code> in auth.php staat; zonder sleutel blijft dit sample-data
      (<code>?sample=1</code> forceert sample).
      Een nummer met ASS opent altijd assemblage, een nummer met WO altijd de werkplaatsorder.
      Zonder ASS of WO kies je zelf het rapporttype; die keuze wordt niet overruled.
    </p>

    <form method="get" action="index.php">
      <input type="hidden" name="go" value="1">

      <label for="type">Rapport</label>
      <select name="type" id="type" required>
        <option value="werkplaats"<?= $type !== 'assemblage' ? ' selected' : '' ?>>Werkplaatsorder</option>
        <option value="assemblage"<?= $type === 'assemblage' ? ' selected' : '' ?>>Assemblage</option>
      </select>
      <p class="type-note" id="type-note"><?= htmlspecialchars($typeNote, ENT_QUOTES, 'UTF-8') ?></p>

      <label for="no">Werkorder / Assemblagenr.</label>
      <input type="text" name="no" id="no" value="<?= htmlspecialchars($noValue, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="<?= htmlspecialchars($defaultWo, ENT_QUOTES, 'UTF-8') ?>">

      <div class="actions">
        <button type="submit">Open rapport</button>
        <a class="btn secondary" href="werkplaatsorder.php?no=<?= rawurlencode($defaultWo) ?>">Sample WO</a>
        <a class="btn secondary" href="assemblage.php?no=<?= rawurlencode($defaultAo) ?>">Sample ASS</a>
      </div>
    </form>

    <p class="hint">
      Lokaal: <code>php -S localhost:8765 -t web</code>
      · daarna <code>http://localhost:8765/</code>
    </p>
  </div>
  <script>
    var defaultWo = <?= json_encode($defaultWo) ?>;
    var defaultAo = <?= json_encode($defaultAo) ?>;
    var typeNotes = <?= json_encode([
        'assemblage' => vulcanus_type_choice_note('ASS'),
        'werkplaats' => vulcanus_type_choice_note('WO'),
        'manual' => vulcanus_type_choice_note('104582'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function vulcanusDetectReportType(no) {
      var upper = String(no || '').toUpperCase();
      if (upper.indexOf('ASS') !== -1) return 'assemblage';
      if (upper.indexOf('WO') !== -1) return 'werkplaats';
      return null;
    }
    function vulcanusSyncTypeNote() {
      var select = document.getElementById('type');
      var detected = vulcanusDetectReportType(document.getElementById('no').value);
      select.disabled = false;
      select.hidden = false;
      document.getElementById('type-note').textContent = typeNotes[detected || 'manual'];
    }
    document.getElementById('no').addEventListener('input', function () {
      var detected = vulcanusDetectReportType(this.value);
      if (detected) document.getElementById('type').value = detected;
      vulcanusSyncTypeNote();
    });
    document.getElementById('type').addEventListener('change', function () {
      var inp = document.getElementById('no');
      var detected = vulcanusDetectReportType(inp.value);
      if (!String(inp.value || '').trim()) {
        inp.value = this.value === 'assemblage' ? defaultAo : defaultWo;
      } else if (detected && detected !== this.value) {
        if (inp.value === defaultWo || inp.value === defaultAo) {
          inp.value = this.value === 'assemblage' ? defaultAo : defaultWo;
        } else {
          this.value = detected;
        }
      }
      vulcanusSyncTypeNote();
    });
    vulcanusSyncTypeNote();
  </script>
</body>
</html>
