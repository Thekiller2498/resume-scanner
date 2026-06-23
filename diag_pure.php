<?php
require_once 'exp.php';

$pdf = __DIR__ . DIRECTORY_SEPARATOR . 'DevOps_sample_resume.pdf';
echo "Extracting using pure PHP parser..." . PHP_EOL;
$text = extractTextFromPdf($pdf);

echo "Extracted text length: " . strlen($text) . PHP_EOL;
echo "Is Garbled? " . (isGarbled($text) ? 'YES' : 'NO') . PHP_EOL;
echo "Preview (first 500 chars):" . PHP_EOL;
echo substr($text, 0, 500) . PHP_EOL;
