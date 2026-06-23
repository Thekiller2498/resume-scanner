<?php
$binDir = __DIR__ . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR
        . 'poppler-24.08.0' . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'bin';
$exe = $binDir . DIRECTORY_SEPARATOR . 'pdftotext.exe';

echo 'EXE path : ' . $exe . PHP_EOL;
echo 'Exists   : ' . (file_exists($exe) ? 'YES' : 'NO') . PHP_EOL;

$pdf = __DIR__ . DIRECTORY_SEPARATOR . 'DevOps_sample_resume.pdf';
echo 'PDF exists: ' . (file_exists($pdf) ? 'YES' : 'NO') . PHP_EOL;

$out = [];
$ret = -1;
// Test WITHOUT stderr redirect first
$cmd = escapeshellarg($exe) . ' -layout ' . escapeshellarg($pdf) . ' -';
echo 'CMD: ' . $cmd . PHP_EOL;
exec($cmd, $out, $ret);
echo 'Return code : ' . $ret . PHP_EOL;
echo 'Lines output: ' . count($out) . PHP_EOL;
if (!empty($out)) {
    echo 'Sample lines:' . PHP_EOL;
    foreach (array_slice($out, 0, 5) as $l) echo '  ' . $l . PHP_EOL;
}
