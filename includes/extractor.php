<?php

/**
 * Pure-PHP UTF-8 encoder — converts a Unicode codepoint to a UTF-8 string.
 * Replaces mb_chr() so we don't need the mbstring extension.
 */
function utf8Chr(int $cp): string {
    if ($cp < 0x80)  return chr($cp);
    if ($cp < 0x800) return chr(0xC0 | ($cp >> 6))  . chr(0x80 | ($cp & 0x3F));
    if ($cp < 0x10000) return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
}

/**
 * Parse a ToUnicode CMap from a PDF stream.
 * Returns an array mapping hex glyph ID strings to Unicode characters.
 */
function parseCMap(string $cmap): array {
    $map = [];
    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $cmap, $m, PREG_SET_ORDER)) {
        foreach ($m as $entry) {
            $from = strtolower($entry[1]);
            $toHex = $entry[2];
            $cp = hexdec($toHex);
            if ($cp >= 0x20) {
                $map[$from] = utf8Chr($cp);
            }
        }
    }
    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $cmap, $ranges, PREG_SET_ORDER)) {
        foreach ($ranges as $r) {
            $from = hexdec($r[1]);
            $to   = hexdec($r[2]);
            $uni  = hexdec($r[3]);
            for ($i = $from; $i <= $to && $i - $from < 256; $i++) {
                $key = strtolower(sprintf(strlen($r[1]) <= 2 ? '%02x' : '%04x', $i));
                $cp  = $uni + ($i - $from);
                if ($cp >= 0x20) {
                    $map[$key] = utf8Chr($cp);
                }
            }
        }
    }
    return $map;
}

/**
 * Decode a hex token from a PDF text stream using a ToUnicode map (if available),
 * falling back to treating the bytes as Latin-1 / ASCII.
 */
function decodeHexToken(string $hex, array $cmap): string {
    $hex = strtolower(preg_replace('/\s+/', '', $hex));
    $len = strlen($hex);
    if ($len === 0) return '';

    if (isset($cmap[$hex])) return $cmap[$hex];

    $result = '';
    $chunkSize = ($len % 4 === 0 && $len >= 4) ? 4 : 2;
    for ($i = 0; $i < $len; $i += $chunkSize) {
        $chunk = substr($hex, $i, $chunkSize);
        if (isset($cmap[$chunk])) {
            $result .= $cmap[$chunk];
        } else {
            $cp = hexdec($chunk);
            if ($cp >= 0x20 && $cp <= 0x7E) {
                $result .= chr($cp);
            } elseif ($cp > 0x7E && $cp < 0x110000) {
                $result .= utf8Chr($cp);
            }
        }
    }
    return $result;
}

/**
 * Check whether extracted text looks like garbled/misencoded output.
 */
function isGarbled(string $text): bool {
    $trimmed = trim($text);
    if (strlen($trimmed) < 80) return false;

    $words = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) >= 10) {
        $singleCount = 0;
        foreach ($words as $w) {
            if (strlen($w) === 1 && ctype_alpha($w)) $singleCount++;
        }
        if (($singleCount / count($words)) > 0.4) return true;
    }

    $lower = strtolower($trimmed);
    $stopWords = [
        ' the ', ' and ', ' with ', ' for ', ' of ', ' to ', ' in ',
        ' is ', ' are ', ' was ', ' has ', ' have ', ' from ', ' at ',
        'experience', 'education', 'skills', 'work', 'team', 'engineer',
    ];
    $found = 0;
    foreach ($stopWords as $w) {
        if (strpos($lower, $w) !== false) $found++;
    }
    if ($found < 3) return true;

    return false;
}

/**
 * Try to extract text using the bundled pdftotext.exe (Poppler).
 */
function tryPdfToText(string $tmpPath): string {
    if (!function_exists('exec')) return '';

    $binDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR
            . 'poppler-24.08.0' . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'bin';
    $exe    = $binDir . DIRECTORY_SEPARATOR . 'pdftotext.exe';

    if (!file_exists($exe)) return '';

    $out = [];
    $ret = -1;
    $cmd = escapeshellarg($exe) . ' -layout ' . escapeshellarg($tmpPath) . ' -';
    @exec($cmd, $out, $ret);

    if ($ret === 0 && !empty($out)) {
        return implode("\n", $out);
    }
    return '';
}

/**
 * Main PDF text extractor fallback.
 */
function extractTextFromPdf(string $filePath): string {
    $raw = @file_get_contents($filePath);
    if ($raw === false) return '';

    $globalCmap = [];
    if (preg_match_all('/stream(.*?)endstream/s', $raw, $allStreams, PREG_SET_ORDER)) {
        foreach ($allStreams as $s) {
            $data = ltrim($s[1]);
            $dec  = @gzuncompress($data) ?: @gzinflate($data) ?: $data;
            if (strpos($dec, 'beginbfchar') !== false || strpos($dec, 'beginbfrange') !== false) {
                $globalCmap = array_merge($globalCmap, parseCMap($dec));
            }
        }
    }

    $text = '';
    preg_match_all('/stream(.*?)endstream/s', $raw, $streams, PREG_SET_ORDER);

    foreach ($streams as $stream) {
        $data    = ltrim($stream[1]);
        $dec     = @gzuncompress($data) ?: @gzinflate($data) ?: $data;

        if (strpos($dec, 'beginbfchar') !== false) continue;
        if (strpos($dec, 'FontMatrix')  !== false) continue;

        preg_match_all('/BT(.*?)ET/s', $dec, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $inner = $block[1];

            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $inner, $parens);
            foreach ($parens[1] as $s) {
                $s = stripslashes($s);
                $s = preg_replace('/[^\x20-\x7E\xC0-\xFF]/', ' ', $s);
                $s = trim($s);
                if (strlen($s) > 1) $text .= $s . ' ';
            }

            preg_match_all('/<([0-9A-Fa-f\s]+)>/', $inner, $hexes);
            foreach ($hexes[1] as $hex) {
                $decoded = decodeHexToken($hex, $globalCmap);
                $decoded = preg_replace('/[\x00-\x1F\x7F]/', ' ', $decoded);
                $decoded = trim($decoded);
                if (strlen($decoded) > 0) $text .= $decoded . ' ';
            }
        }

        $text .= "\n";
    }

    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/(\s*\n\s*){2,}/', "\n\n", $text);
    return trim($text);
}
