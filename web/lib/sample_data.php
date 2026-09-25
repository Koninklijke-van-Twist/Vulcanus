<?php
/**
 * Sample/demo data voor Werkplaatsorder en Assemblage.
 * Inhoud lijkt qua lengte/structuur op de PDF-voorbeelden (fictief, geparafraseerd).
 * Later te vervangen door Mímir/BC OData-calls.
 */

declare(strict_types=1);

/**
 * Sample werkplaatsorder-header (LVS_MainWorkOrderCard).
 */
function sample_werkplaatsorder_header(string $no = 'WO26091234'): array
{
    return [
        'No'                       => $no,
        'Main_Entity_Description'  => 'UNKNOWN - NIET AANPASSEN',
        'Component_No'             => '10087766',
        'Serial_No'                => '42-PW1106-R043184K',
        'Task_Description'         => 'Hencon Reparatie Motor',
        'Sell_to_Name'             => 'HENCON SERVICES BV',
        'Visit_Address'            => 'UNKNOWN - NIET AANPASSEN',
        'Memo'                     => sample_werkplaats_memo(),
        'Created_By'               => 'KVT\\LODGE',
        'Created_Date_Time'        => '2/3/2026',
        'End_Date'                 => '2/28/2026',
        'Status'                   => 'In progress',
        'Start_Date'               => '2/3/2026',
        'Resource_Name'            => 'Team Motoren',
    ];
}

function sample_werkplaats_memo(): string
{
    return <<<'MEMO'
-Schade delen vervangen en motor weer opbouwen

Turbohuis draaien
Turbohuis 200mm verdraaien t.o.v. standaardpositie (uitlaat naar stuurboord).
Controleer uitlijning compressorhuis vóór vastzetten.

#1 Werk instructie compressor timing
Zie werkinstructie: KVT Documenten\General\Klanten\17606 - DEMO KLANT BV\Motor\Compressor-timing-v3.pdf
Timing markeringen op nokkenas en krukas verifiëren na montage.

#2 ECM programmeren
ECM flashen met klantspecifieke parameterfile.
Pad (intern): KVT Documenten\General\Klanten\17606 - DEMO KLANT BV\ECM\flash-params-r4.xml
Na flash: idle 5 min, foutcodes wissen, proefdraaien.

Let op: oliefilter en turbo retourleiding altijd nieuw.
MEMO;
}

/**
 * Sample planningregels — veel korte onderdelen zoals in PDF-voorbeeld.
 */
function sample_werkplaatsorder_lines(): array
{
    $parts = [
        ['PK-T414086', 'WIRING HARNESS', 1],
        ['PK-4627133', 'OIL FILTER', 1],
        ['PK-T414201', 'SENSOR', 2],
        ['PK-3958145', 'PIPE, FUEL', 1],
        ['PK-3903309', 'CLAMP', 4],
        ['PK-2314-F005', 'SCREW', 8],
        ['PK-3900678', 'NUT', 8],
        ['PK-T414512', 'MOUNTING', 2],
        ['PK-3817723', 'SPACER', 4],
        ['PK-3901234', 'WASHER', 12],
        ['PK-T417410', 'FUEL PIPE', 1],
        ['PK-3218R045', 'BANJO BOLT', 2],
        ['PK-2411D012', 'WASHER', 4],
        ['PK-T410735', 'KIT, DRAIN', 1],
        ['PK-T412890', 'SCREW', 6],
        ['PK-3905567', 'WIRING HARNESS', 1],
        ['PK-T415201', 'COMPRESSOR', 0],
        ['PK-3819012', 'GASKET', 2],
        ['PK-T413340', 'HOSE', 1],
        ['PK-3907781', 'CLIP', 4],
        ['PK-T416002', 'SENSOR, TEMP', 1],
        ['PK-32184423', 'BOLT', 0],
        ['PK-T411100', 'HOSE', 0],
        ['PK-3902201', 'STUD', 1],
        ['PK-T418850', 'SEAL, OIL', 2],
        ['MWORKSHOP', 'Workshop hours Mechanical', 6],
        ['PK-T419100', 'BRACKET', 1],
        ['PK-3909912', 'PIPE, OIL', 1],
        ['PK-T412200', 'VALVE', 1],
        ['PK-3815500', 'FILTER, FUEL', 1],
        ['PK-T414900', 'ACTUATOR', 0],
        ['PK-3904400', 'O-RING', 6],
        ['PK-T417050', 'CONNECTOR', 2],
        ['PK-32190011', 'NUT, LOCK', 4],
        ['PK-T410050', 'PLATE', 1],
    ];

    $lines = [];
    $lineNo = 10000;
    foreach ($parts as [$no, $desc, $qty]) {
        $lines[] = [
            'Line_No'              => $lineNo,
            'Type'                 => str_starts_with($no, 'M') ? 'Resource' : 'Item',
            'No'                   => $no,
            'Description'          => $desc,
            'Quantity'             => $qty,
            'Unit_of_Measure_Code' => str_starts_with($no, 'M') ? 'HR' : 'ST',
            'KVT_Extended_Text'    => '',
        ];
        $lineNo += 10000;
    }

    // Eén regel met korte extended text (zeldzaam in WO-sample)
    $lines[2]['KVT_Extended_Text'] = 'Positie bij waterpomp; connector blauw.';

    usort($lines, static fn ($a, $b) => $a['Line_No'] <=> $b['Line_No']);
    return $lines;
}

/**
 * Sample assemblage-header (AssemblageKop).
 */
function sample_assemblage_header(string $no = 'ASS26094567'): array
{
    return [
        'No'                    => $no,
        'Item_No'               => 'GENSET-904J',
        'Description'           => 'Samenbouw GenSet 904J-E56TAG+LSA',
        'Quantity'              => 3,
        'Quantity_to_Assemble'  => 0,
        'Assembled_Quantity'    => 3,
        'Status'                => 'Released',
        'Due_Date'              => '10/8/2026',
        'Starting_Date'         => '10/8/2026',
        'Location_Code'         => 'WSH-DORD',
        'Bin_Code'              => 'WSH-P-GERP',
        'Unit_of_Measure_Code'  => 'ST',
        'LVS_Job_No'            => '20-15202129',
        'Variant_Code'          => '44.3',
    ];
}

/**
 * Sample assemblageregels — korte parts + lange INSTRUCTIE zoals PDF.
 */
function sample_assemblage_lines(): array
{
    $lines = [
        [
            'Line_No'              => 10000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Item',
            'No'                   => 'AO-11A476',
            'Description'          => 'HOUTEN BALK 680 x 100 x 50 mm ongesch',
            'Quantity'             => 3,
            'Unit_of_Measure_Code' => 'ST',
            'KVT_Extended_Text'    => '',
        ],
        [
            'Line_No'              => 20000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Item',
            'No'                   => 'AO-11A410/24',
            'Description'          => 'Tapeind M10-87mm',
            'Quantity'             => 36,
            'Unit_of_Measure_Code' => 'ST',
            'KVT_Extended_Text'    => '',
        ],
        [
            'Line_No'              => 30000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Resource',
            'No'                   => 'MWORKSHOP',
            'Description'          => 'Workshop hours Mechanical',
            'Quantity'             => 6,
            'Unit_of_Measure_Code' => 'HR',
            'KVT_Extended_Text'    => '',
        ],
        [
            'Line_No'              => 40000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Item',
            'No'                   => 'INSTRUCTIE',
            'Description'          => 'Assemblage-instructie GenSet 904J',
            'Quantity'             => 1,
            'Unit_of_Measure_Code' => 'ST',
            'KVT_Extended_Text'    => sample_assemblage_instructie(),
        ],
        [
            'Line_No'              => 50000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Item',
            'No'                   => 'AO-11B220',
            'Description'          => 'M10 Borgmoer (set)',
            'Quantity'             => 36,
            'Unit_of_Measure_Code' => 'ST',
            'KVT_Extended_Text'    => 'Aandraaimoment 54 Nm.',
        ],
        [
            'Line_No'              => 60000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Item',
            'No'                   => 'AO-11C105',
            'Description'          => '3/8 UNF bout flens',
            'Quantity'             => 12,
            'Unit_of_Measure_Code' => 'ST',
            'KVT_Extended_Text'    => 'Aandraaimoment 48 Nm.',
        ],
        [
            'Line_No'              => 70000,
            'Document_No'          => 'ASS26094567',
            'Type'                 => 'Item',
            'No'                   => 'LABEL-CE-GS',
            'Description'          => 'Typeplaatje / CE-label GenSet',
            'Quantity'             => 3,
            'Unit_of_Measure_Code' => 'ST',
            'KVT_Extended_Text'    => '',
        ],
    ];

    usort($lines, static fn ($a, $b) => $a['Line_No'] <=> $b['Line_No']);
    return $lines;
}

function sample_assemblage_instructie(): string
{
    return <<<'TXT'
Serienummers motor/generator (voorbeeldset — fictief):
FW51992U054557M / GEN-LSA-904-001
FW51992U054558M / GEN-LSA-904-002
FW51992U054559M / GEN-LSA-904-003

-Doos weggooien na uitpakken (Perkins pallet behouden tot eindcontrole)
-HOUTEN BALK onder frame plaatsen vóór hijsen
-Serienummers motor/generator combinaties op rapportage noteren

Montagevolgorde:
1) Frame reinigen, boutgaten controleren.
2) Motor plaatsen, M10 borgmoer 54 Nm.
3) Generator koppelen, 3/8 UNF bout 48 Nm.
4) Bedrading + sensoren volgens schema GenSet-904J.
5) Eindcontrole: draaiing vrij, geen lek, CE-label bevestigen.

Configuratie / statusrapport (intern pad, geparafraseerd):
KVT Algemeen - Documenten\Generaal\Leveranties\90101-PERKINS\GenSet-904J\config-status-r2.xlsx

Na oplevering: pallet labelen met ASS-nummer + serienummers.
TXT;
}

/**
 * Formatteer hoeveelheid voor assemblage-regel (RDL-logica).
 * - No == INSTRUCTIE → leeg (verberg qty)
 * - UoM HR → toon "UUR" i.p.v. "ST"
 */
function format_assemblage_qty(array $line): string
{
    if (($line['No'] ?? '') === 'INSTRUCTIE') {
        return '';
    }
    $qty = $line['Quantity'] ?? '';
    $uom = $line['Unit_of_Measure_Code'] ?? '';
    if ($uom === 'HR') {
        return rtrim(rtrim((string) $qty, '0'), '.') . ' UUR';
    }
    // PDF toont vaak alleen het getal (geen ST)
    if (is_float($qty) || (is_string($qty) && str_contains((string) $qty, '.'))) {
        $qty = rtrim(rtrim(number_format((float) $qty, 2, ',', ''), '0'), ',');
    }
    return (string) $qty;
}

/**
 * Formatteer hoeveelheid voor werkplaatsorder-regel.
 * PDF: vaak alleen qty-cijfer; UUR wel tonen bij HR.
 */
function format_werkplaats_qty(array $line): string
{
    if (($line['No'] ?? '') === 'INSTRUCTIE') {
        return '';
    }
    $qty = $line['Quantity'] ?? '';
    $uom = $line['Unit_of_Measure_Code'] ?? 'ST';
    if ($uom === 'HR') {
        return rtrim(rtrim(number_format((float) $qty, 2, ',', ''), '0'), ',') . ' UUR';
    }
    if (is_float($qty) || (is_numeric($qty) && floor((float) $qty) != $qty)) {
        $qty = rtrim(rtrim(number_format((float) $qty, 2, ',', ''), '0'), ',');
    }
    return (string) $qty;
}

/** HTML-escape helper */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Multiline memo/extended text → HTML met <br> */
function nl2br_h(?string $s): string
{
    $s = (string) $s;
    if ($s === '') {
        return '';
    }
    return nl2br(h($s), false);
}

/** Print-tijdstempel in US-achtige vorm zoals PDF-voorbeeld */
function print_timestamp(): string
{
    // Match PDF look: 9/25/2026 2:50:16 PM
    return date('n/j/Y g:i:s A');
}
