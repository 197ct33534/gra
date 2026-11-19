<?php

/**
 * PCL csv -> styled .xlsx (optional deliverable; the .html renderer is what people copy from).
 *
 *   php .claude/skills/pcl-testcase/assets/generate_pcl.php docs/testcase/sheet.csv
 *
 * Needs phpoffice/phpspreadsheet in the project's vendor/ (gra_o_to does not ship it by default).
 *
 * The .csv is the single source of truth. Nothing is duplicated here — this only styles.
 *
 * WHY THE CSV CARRIES A UTF-8 BOM: Excel on Windows otherwise reads a .csv in the system ANSI
 * codepage and turns Japanese headers into mojibake. That is the one place a BOM is correct —
 * never add one to a .php file.
 */

$arg = $argv[1] ?? null;

if ($arg === null) {
    exit("usage: php generate_pcl.php path/to/sheet.csv\n");
}

$src = realpath($arg);

if ($src === false || !is_file($src)) {
    exit("csv not found: {$arg}\n");
}

// Walk up from the csv to find the project's vendor/autoload.php, so this asset works from any
// project without editing a hardcoded relative path.
$autoload = null;
$dir      = dirname($src);

for ($i = 0; $i < 8; $i++) {
    foreach (["{$dir}/vendor/autoload.php", "{$dir}/backend/vendor/autoload.php"] as $candidate) {
        if (is_file($candidate)) {
            $autoload = $candidate;
            break 2;
        }
    }

    $parent = dirname($dir);

    if ($parent === $dir) {
        break;
    }

    $dir = $parent;
}

if ($autoload === null) {
    exit("could not find vendor/autoload.php above {$src} — run from a project that has PhpSpreadsheet\n");
}

require $autoload;

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    fwrite(STDERR, "phpoffice/phpspreadsheet is not installed in {$autoload} — deliver the .html instead,\n"
        . "or add it with: composer require --dev phpoffice/phpspreadsheet\n");
    exit(1);
}

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const COLUMNS = 5;

// The BOM sits BEFORE the opening quote of field 1, so fgetcsv() would not see that field as
// quoted and the two-line header would split into two records. Strip it before parsing, not after.
$fh = fopen('php://memory', 'r+');
fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($src)));
rewind($fh);
$rows = [];

while (($cells = fgetcsv($fh)) !== false) {
    if ($cells === [null] || $cells === []) {
        continue;
    }

    if (count($cells) !== COLUMNS) {
        fclose($fh);
        exit(sprintf(
            "record %d has %d columns (expected %d) — fix the csv first.\n"
            . "A missing quote swallows the following rows into one cell, and that is invisible\n"
            . "once the sheet is styled.\n",
            count($rows) + 1,
            count($cells),
            COLUMNS
        ));
    }

    $rows[] = $cells;
}

fclose($fh);

if ($rows !== []) {
    // Keep the BOM out of the header cell, or it sorts and filters oddly in Excel.
    $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
}

if (count($rows) < 2) {
    exit("csv has no data rows\n");
}

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle(substr(basename($src, '.csv'), 0, 31));

foreach ($rows as $r => $row) {
    foreach ($row as $c => $value) {
        // Forced to string: a cell starting with "=", "+" or "-" would be stored as a formula and
        // render as #NAME? instead of the test step.
        // getCellByColumnAndRow() was removed in PhpSpreadsheet 2.0; the array coordinate is the
        // supported form on 5.x, which is what this project ships.
        $sh->getCell([$c + 1, $r + 1])->setValueExplicit($value, DataType::TYPE_STRING);
    }
}

$last = count($rows);

$sh->getStyle("A1:E{$last}")->applyFromArray([
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9BA7B0']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);

$sh->getStyle('A1:D1')->applyFromArray([
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sh->getStyle('E1')->applyFromArray([
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sh->getStyle("A2:E{$last}")->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
]);
$sh->getStyle("D2:D{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

foreach (['A' => 22, 'B' => 40, 'C' => 78, 'D' => 8, 'E' => 50] as $col => $w) {
    $sh->getColumnDimension($col)->setWidth($w);
}

// Data row height stays automatic — PhpSpreadsheet cannot measure wrapped text, so a fixed height
// would clip the long cells. Excel recalculates on open.
$sh->getRowDimension(1)->setRowHeight(34);
$sh->freezePane('A2');
$sh->setAutoFilter("A1:E{$last}");

$out = preg_replace('/\.csv$/', '.xlsx', $src);
(new Xlsx($ss))->save($out);

printf("source : %s\ncases  : %d\nxlsx   : %s\n", basename($src), $last - 1, $out);

$fn = [];
foreach (array_slice($rows, 1) as $row) {
    $fn[$row[0]] = ($fn[$row[0]] ?? 0) + 1;
}
ksort($fn);

echo "\ncases per function:\n";
foreach ($fn as $k => $v) {
    printf("  %-24s %d\n", $k, $v);
}
