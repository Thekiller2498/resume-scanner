<?php
require_once 'ats_scanner.php';
$text = tryPdfToText('DevOps_sample_resume.pdf');

echo "Text length: " . strlen($text) . PHP_EOL;

$currentYear = (int)date('Y');
$yearsActive = [];

// Allow optional letters/spaces/commas between separator and end year
$rangePattern = '/\b(19\d\d|20[0-2]\d)\s*(?:-|–|—|\/|to)\s*(?:[a-zA-Z\s,]+)?\b(20[0-2]\d|Present|Current|Now)\b/i';
if (preg_match_all($rangePattern, $text, $matches, PREG_SET_ORDER)) {
    echo "Date Range Matches:" . PHP_EOL;
    foreach ($matches as $match) {
        echo "  Full: " . $match[0] . " | Start: " . $match[1] . " | End: " . $match[2] . PHP_EOL;
        $start = (int)$match[1];
        $endVal = strtolower($match[2]);
        if (in_array($endVal, ['present', 'current', 'now'])) {
            $end = $currentYear;
        } else {
            $end = (int)$match[2];
        }
        for ($y = $start; $y <= $end; $y++) {
            $yearsActive[$y] = true;
        }
    }
} else {
    echo "No Date Range Matches!" . PHP_EOL;
}

echo "Total unique years from range active: " . count($yearsActive) . PHP_EOL;
