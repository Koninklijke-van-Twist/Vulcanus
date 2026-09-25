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
  <div class="chooser" id="chooser">
    <img class="chooser-logo" src="assets/kvt-crown.svg" alt="KVT" width="84" height="84">
    <div id="chooser-body">
    <?php if ($showChoice): ?>
      <p class="entered-no"><?= htmlspecialchars($no, ENT_QUOTES, 'UTF-8') ?></p>
      <p class="choice-lead">Welke opdracht?</p>
      <div class="choice-list">
        <a class="choice" href="assemblage.php?<?= htmlspecialchars($choiceQuery, ENT_QUOTES, 'UTF-8') ?>">Assemblageopdracht</a>
        <a class="choice" href="werkplaatsorder.php?<?= htmlspecialchars($choiceQuery, ENT_QUOTES, 'UTF-8') ?>">Werkplaatsopdracht</a>
      </div>
      <a class="back" href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>">Ander nummer</a>
    <?php else: ?>
      <form method="get" action="index.php" id="order-form">
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
  </div>
  <script>
  (function () {
    var busy = false;
    var sampleOn = <?= vulcanus_sample_forced() ? 'true' : 'false' ?>;

    // Zelfde volgorde als vulcanus_detect_report_type: ASS wint van WO, anders null.
    function detectReportType(no) {
      var upper = String(no).toUpperCase();
      if (upper.indexOf('ASS') !== -1) {
        return 'assemblage';
      }
      if (upper.indexOf('WO') !== -1) {
        return 'werkplaats';
      }
      return '';
    }

    function destinationFor(no) {
      var params = new URLSearchParams();
      params.set('no', no);
      if (sampleOn) {
        params.set('sample', '1');
      }
      var type = detectReportType(no);
      var page = 'index.php';
      if (type === 'assemblage') {
        page = 'assemblage.php';
      } else if (type === 'werkplaats') {
        page = 'werkplaatsorder.php';
      }
      return page + '?' + params.toString();
    }

    function showLoading(number) {
      var chooser = document.getElementById('chooser');
      var body = document.getElementById('chooser-body');
      if (!chooser || !body) {
        return;
      }
      var input = document.getElementById('no');
      var button = document.querySelector('#order-form button[type="submit"]');
      if (input) {
        input.value = number;
        input.disabled = true;
      }
      if (button) {
        button.disabled = true;
      }
      var links = document.querySelectorAll('a.choice');
      for (var i = 0; i < links.length; i++) {
        links[i].setAttribute('aria-disabled', 'true');
        links[i].removeAttribute('href');
      }
      chooser.classList.add('is-loading');
      chooser.setAttribute('aria-busy', 'true');

      var noEl = body.querySelector('.entered-no');
      if (noEl) {
        noEl.textContent = number;
      }

      if (!document.getElementById('loading-status')) {
        var status = document.createElement('p');
        status.className = 'loading-status';
        status.id = 'loading-status';
        status.setAttribute('role', 'status');
        var spinner = document.createElement('span');
        spinner.className = 'spinner';
        spinner.setAttribute('aria-hidden', 'true');
        status.appendChild(spinner);
        status.appendChild(document.createTextNode('Opdracht ophalen…'));
        body.appendChild(status);
      }
    }

    function goAfterPaint(href) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          window.location.assign(href);
        });
      });
    }

    window.addEventListener('pageshow', function (event) {
      var chooser = document.getElementById('chooser');
      if (event.persisted && chooser && chooser.classList.contains('is-loading')) {
        window.location.reload();
      }
    });

    var form = document.getElementById('order-form');
    if (form) {
      form.addEventListener('submit', function (event) {
        if (busy) {
          event.preventDefault();
          return;
        }
        var input = document.getElementById('no');
        var no = input ? input.value.trim() : '';
        if (no === '') {
          event.preventDefault();
          if (input) {
            input.value = '';
            input.reportValidity();
          }
          return;
        }
        event.preventDefault();
        busy = true;
        showLoading(no);
        goAfterPaint(destinationFor(no));
      });
    }

    var choices = document.querySelectorAll('a.choice');
    for (var c = 0; c < choices.length; c++) {
      choices[c].addEventListener('click', function (event) {
        if (busy) {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        busy = true;
        var shown = document.querySelector('.entered-no');
        var number = shown ? shown.textContent : '';
        var href = event.currentTarget.href;
        showLoading(number);
        goAfterPaint(href);
      });
    }
  })();
  </script>
</body>
</html>
