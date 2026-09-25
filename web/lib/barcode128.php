<?php
/**
 * Minimale Code128B barcode → SVG (geen Composer).
 * Encodeert ASCII 32–126 (Code Set B), met start B + checksum + stop.
 */

declare(strict_types=1);

/**
 * Code128B patterns: index 0–106 → 11-module (of 13 voor stop) bar/space string van 0/1.
 * Bron: gestandaardiseerde Code128-tabellen.
 */
function code128_patterns(): array
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    // Elke string: afwisseling bar/space, start met bar. Lengte 11 (stop=13).
    $p = [
        '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
        '10001001100', '10011001000', '10011000100', '10001100100', '11001001000',
        '11001000100', '11000100100', '10110011100', '10011011100', '10011001110',
        '10111001100', '10011101100', '10011100110', '11001110010', '11001011100',
        '11001001110', '11011100100', '11001110100', '11101101110', '11101001100',
        '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
        '11011011000', '11011000110', '11000110110', '10100011000', '10001011000',
        '10001000110', '10110001000', '10001101000', '10001100010', '11010001000',
        '11000101000', '11000100010', '10110111000', '10110001110', '10001101110',
        '10111011000', '10111000110', '10001110110', '11101110110', '11010001110',
        '11000101110', '11011101000', '11011100010', '11011101110', '11101011000',
        '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
        '11101111010', '11001000010', '11110001010', '10100110000', '10100001100',
        '10010110000', '10010000110', '10000101100', '10000100110', '10110010000',
        '10110000100', '10011010000', '10011000010', '10000110100', '10000110010',
        '11000010010', '11001010000', '11110111010', '11000010100', '10001111010',
        '10100111100', '10010111100', '10010011110', '10111100100', '10011110100',
        '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
        '11011110110', '11110110110', '10101111000', '10100011110', '10001011110',
        '10111101000', '10111100010', '11110101000', '11110100010', '10111011110',
        '10111101110', '11101011110', '11110101110',
        // 103 Start A, 104 Start B, 105 Start C, 106 Stop
        '11010000100', '11010010000', '11010011100', '1100011101011',
    ];
    return $p;
}

/**
 * Zet een string om naar Code128B code-waarden (zonder start/checksum/stop).
 */
function code128b_encode_values(string $text): array
{
    $codes = [];
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ord = ord($text[$i]);
        if ($ord < 32 || $ord > 126) {
            // Niet-printbaar → vervang door spatie (Code B)
            $ord = 32;
        }
        $codes[] = $ord - 32; // Code Set B: ASCII 32 → code 0
    }
    return $codes;
}

/**
 * Bouw module-bitstring voor Code128B (inclusief quiet zone-hint via padding in SVG).
 */
function code128b_bitstring(string $text): string
{
    $patterns = code128_patterns();
    $startB = 104;
    $stop = 106;

    $data = code128b_encode_values($text);

    // Checksum: (start + Σ i * code_i) mod 103
    $sum = $startB;
    foreach ($data as $i => $code) {
        $sum += ($i + 1) * $code;
    }
    $checksum = $sum % 103;

    $seq = array_merge([$startB], $data, [$checksum, $stop]);
    $bits = '';
    foreach ($seq as $code) {
        $bits .= $patterns[$code];
    }
    return $bits;
}

/**
 * Alleen cijfers uit een ordernummer (zoals RDL: Regex.Replace(..., "[^0-9]", "")).
 */
function barcode_digits_only(string $no): string
{
    $digits = preg_replace('/[^0-9]/', '', $no) ?? '';
    // Fallback: als er geen cijfers zijn, encodeer de volledige No
    return $digits !== '' ? $digits : $no;
}

/**
 * Render Code128B als inline SVG.
 *
 * @param string $text     Tekst om te encoden (meestal digits-only No)
 * @param int    $moduleW  Breedte per module in px
 * @param int    $height   Balkhoogte in px
 * @param bool   $showText Toon human-readable tekst onder barcode
 */
function render_code128b_svg(
    string $text,
    int $moduleW = 2,
    int $height = 60,
    bool $showText = false
): string {
    if ($text === '') {
        $text = '0';
    }

    $bits = code128b_bitstring($text);
    $quiet = 10; // modules quiet zone links/rechts
    $modules = strlen($bits) + 2 * $quiet;
    $width = $modules * $moduleW;
    $textH = $showText ? 16 : 0;
    $totalH = $height + $textH + 4;

    $rects = [];
    $x = $quiet * $moduleW;
    $len = strlen($bits);
    $i = 0;
    while ($i < $len) {
        if ($bits[$i] === '1') {
            $run = 1;
            while ($i + $run < $len && $bits[$i + $run] === '1') {
                $run++;
            }
            $w = $run * $moduleW;
            $rects[] = sprintf(
                '<rect x="%d" y="0" width="%d" height="%d"/>',
                $x,
                $w,
                $height
            );
            $x += $w;
            $i += $run;
        } else {
            $x += $moduleW;
            $i++;
        }
    }

    $label = '';
    if ($showText) {
        $esc = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = sprintf(
            '<text x="%d" y="%d" text-anchor="middle" font-family="monospace" font-size="12">%s</text>',
            (int) ($width / 2),
            $height + 14,
            $esc
        );
    }

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" class="barcode" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="Code128 %s">%s%s</svg>',
        $width,
        $totalH,
        $width,
        $totalH,
        htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        implode('', $rects),
        $label
    );
}
