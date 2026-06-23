<?php
// =========================================================================
// ATS RESUME ARCHETYPE SCANNER — ats_scanner.php
// A single-file PHP application that accepts a PDF resume upload,
// extracts its text cleanly, then scores it against engineering archetypes.
// Styled with Google Gemini Light Theme and formatted for Company Profile Breakdown.
// =========================================================================

// =========================================================================
// SECTION 1 — INITIALIZE STATE VARIABLES
// =========================================================================
$resumeText             = '';
$uploadedPdfName        = '';
$uploadError            = '';
$name                   = '';
$email                  = '';
$phone                  = '';
$location               = '';
$linkedin               = '';
$github                 = '';
$website                = '';
$identifiedArchetype    = '';
$archetypeEmoji         = '';
$atsScore               = 0;
$scores                 = ['Frontend' => 0, 'Backend' => 0, 'Data' => 0, 'DevOps' => 0];
$formSubmitted          = false;

// Scoring blueprint tracking variables
$tenureYears            = 0;
$matchedCoreCount       = 0;
$matchedSupportingCount = 0;
$winningPillar1         = 0; // Skill match (max 50)
$winningPillar2         = 0; // Experience (max 30)
$winningPillar3         = 0; // Impact metrics (max 20)
$extractedMetrics       = [];
$pillar1Details         = ['core' => [], 'supporting' => []];
$pillar2Details         = [];

// Structural parsing variables
$sections               = [];
$parsedEdu              = [];
$parsedExp              = [];
$parsedProj             = [];
$parsedCert             = [];
$parsedSkills           = [];
$parsedCulture          = [];
$winningKeywords        = [];

// =========================================================================
// SECTION 2 — PDF TEXT EXTRACTION, SCORING & PARSING UTILITIES
// =========================================================================

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

    $binDir = __DIR__ . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR
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

/**
 * Estimate career longevity/tenure from date ranges and explicit declarations.
 */
function estimateTenure(string $text): int {
    $currentYear = (int)date('Y');
    $yearsActive = [];

    // date ranges pattern allows optional month names/numbers preceding the years
    $monthsPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})';
    $rangePattern = '#(?:' . $monthsPattern . '[\s\/-]+)?\b(19\d\d|20[0-2]\d)\s*(?:-|–|—|\/|to)\s*(?:' . $monthsPattern . '[\s\/-]+)?\b(20[0-2]\d|Present|Current|Now)\b#ui';
    if (preg_match_all($rangePattern, $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $start = (int)$match[1];
            $endVal = strtolower($match[2]);
            if (in_array($endVal, ['present', 'current', 'now'])) {
                $end = $currentYear;
            } else {
                $end = (int)$match[2];
            }
            if ($start <= $end && $start > 1950) {
                for ($y = $start; $y <= $end; $y++) {
                    $yearsActive[$y] = true;
                }
            }
        }
    }

    $rangeYears = count($yearsActive);

    // explicit declarations check
    $explicitYears = 0;
    $explicitPattern = '/\b(\d+)\+?\s*years?\s+(?:of\s+)?(?:experience|work|professional|industry|career|tenure)\b/i';
    if (preg_match_all($explicitPattern, $text, $matches)) {
        foreach ($matches[1] as $val) {
            $valInt = (int)$val;
            if ($valInt > $explicitYears && $valInt < 50) {
                $explicitYears = $valInt;
            }
        }
    }

    $explicitPattern2 = '/(?:experience|tenure|work)\s+(?:of\s+)?(?:active\s+)?(\d+)\+?\s*years?/i';
    if (preg_match_all($explicitPattern2, $text, $matches)) {
        foreach ($matches[1] as $val) {
            $valInt = (int)$val;
            if ($valInt > $explicitYears && $valInt < 50) {
                $explicitYears = $valInt;
            }
        }
    }

    return max($rangeYears, $explicitYears);
}

// -------------------------------------------------------------------------
// SECTION 2.1 — STRUCTURAL SECTION EXTRACTION AND PARSING
// -------------------------------------------------------------------------

/**
 * Split the raw text of the resume into logical sections based on headings.
 */
function getResumeSections(string $text): array {
    $headings = [
        'summary'        => ['/^(?:PROFESSIONAL\s+)?SUMMARY\b/im', '/^ABOUT\s+ME\b/im', '/^OBJECTIVE\b/im'],
        'experience'     => ['/^(?:WORK\s+|PROFESSIONAL\s+|EMPLOYMENT\s+)?EXPERIENCE\b/im', '/^WORK\s+HISTORY\b/im', '/^CAREER\s+HISTORY\b/im'],
        'education'      => ['/^EDUCATION\b/im', '/^ACADEMIC\s+BACKGROUND\b/im'],
        'projects'       => ['/^(?:PERSONAL\s+|ACADEMIC\s+|TECHNICAL\s+)?PROJECTS\b/im'],
        'certifications' => ['/^CERTIFICATIONS\b/im', '/^CREDENTIALS\b/im', '/^LICENSES\b/im'],
        'skills'         => ['/^(?:TECHNICAL\s+|KEY\s+)?SKILLS\b/im', '/^TECHNOLOGIES\b/im'],
        'culture'        => ['/^INTERESTS\b/im', '/^HOBBIES\b/im', '/^PERSONAL\s+LIFE\b/im', '/^VOLUNTEER(?:ING)?\b/im', '/^COMMUNITY\b/im']
    ];

    $sections = [];
    $lines = explode("\n", $text);
    
    $found = [];
    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;
        
        foreach ($headings as $secKey => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    $found[] = [
                        'key' => $secKey,
                        'line_idx' => $i,
                        'title' => $trimmed
                    ];
                    break 2;
                }
            }
        }
    }

    usort($found, function($a, $b) {
        return $a['line_idx'] <=> $b['line_idx'];
    });

    $totalLines = count($lines);
    for ($idx = 0; $idx < count($found); $idx++) {
        $curr = $found[$idx];
        $startLine = $curr['line_idx'] + 1;
        $endLine = ($idx + 1 < count($found)) ? $found[$idx + 1]['line_idx'] : $totalLines;
        
        $secText = implode("\n", array_slice($lines, $startLine, $endLine - $startLine));
        $sections[$curr['key']] = trim($secText);
    }
    
    if (!empty($found)) {
        $headerLines = array_slice($lines, 0, $found[0]['line_idx']);
        $sections['header'] = trim(implode("\n", $headerLines));
    } else {
        $sections['header'] = trim($text);
    }

    return $sections;
}

/**
 * Extract degrees, universities, and grades from education text block.
 */
function parseEducation(string $eduText): array {
    $lines = explode("\n", $eduText);
    $degrees = [];
    $currentDegree = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $isDegree = preg_match('/\b(Master|Bachelor|M\.S\.|B\.S\.|B\.A\.|M\.A\.|B\.Sc\.|M\.Sc\.|B\.E\.|B\.Tech|Degree|Diploma|Graduate)\b/i', $line);
        $isUni = preg_match('/\b(University|College|Institute|School|Academy|Polytechnic)\b/i', $line);
        $hasGpa = preg_match('/\b(GPA|Grade|Score|Marks|CGPA)\b/i', $line) || preg_match('/\b[0-3]\.\d+\s*\/|4\.\d+\b/i', $line);

        if ($isDegree) {
            if ($currentDegree) {
                $degrees[] = $currentDegree;
            }
            $currentDegree = ['course' => $line, 'university' => '', 'grade' => ''];
        } elseif ($isUni) {
            if (!$currentDegree) {
                $currentDegree = ['course' => 'Degree / Course Not Specified', 'university' => $line, 'grade' => ''];
            } else {
                $currentDegree['university'] = $line;
            }
        } elseif ($hasGpa) {
            if ($currentDegree) {
                $currentDegree['grade'] = $line;
            }
        } else {
            if ($currentDegree) {
                if (empty($currentDegree['university'])) {
                    $currentDegree['university'] = $line;
                }
            }
        }
    }
    if ($currentDegree) {
        $degrees[] = $currentDegree;
    }
    return $degrees;
}

/**
 * Segment experience block into structural jobs.
 */
function parseExperience(string $expText): array {
    $lines = explode("\n", $expText);
    $jobs = [];
    $currentJob = null;
    $monthsPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})';
    $rangePattern = '#(?:' . $monthsPattern . '[\s\/-]+)?\b(19\d\d|20[0-2]\d)\s*(?:-|–|—|\/|to)\s*(?:' . $monthsPattern . '[\s\/-]+)?\b(20[0-2]\d|Present|Current|Now)\b#ui';

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;

        $hasRange = preg_match($rangePattern, $trimmed, $m);
        
        if ($hasRange) {
            if ($currentJob) {
                $jobs[] = $currentJob;
            }
            $dateRange = $m[0];
            $roleInfo = trim(str_replace($dateRange, '', $trimmed));
            $roleInfo = trim($roleInfo, " \t\n\r\0\x0B|,-–—");
            
            $currentJob = [
                'role' => $roleInfo,
                'company' => '',
                'dates' => $dateRange,
                'reference' => '',
                'bullets' => []
            ];
        } else {
            if ($currentJob) {
                $isBullet = preg_match('/^[\x{2022}\x{2023}\x{2043}\x{204B}•\-*]\s*(.*)/u', $trimmed, $bm);
                if ($isBullet) {
                    $currentJob['bullets'][] = trim($bm[1]);
                } else {
                    if (empty($currentJob['company'])) {
                        if (stripos($trimmed, 'reference') !== false || preg_match('/contact/i', $trimmed) || preg_match('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', $trimmed)) {
                            $currentJob['reference'] = $trimmed;
                        } else {
                            $currentJob['company'] = $trimmed;
                        }
                    } else {
                        if (stripos($trimmed, 'reference') !== false || preg_match('/contact/i', $trimmed) || preg_match('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', $trimmed)) {
                            $currentJob['reference'] = $trimmed;
                        } else {
                            if (!empty($currentJob['bullets'])) {
                                $idx = count($currentJob['bullets']) - 1;
                                $currentJob['bullets'][$idx] .= ' ' . $trimmed;
                            } else {
                                $currentJob['company'] .= ' | ' . $trimmed;
                            }
                        }
                    }
                }
            }
        }
    }
    if ($currentJob) {
        $jobs[] = $currentJob;
    }
    return $jobs;
}

/**
 * Segment projects block into structural projects.
 */
function parseProjects(string $projText): array {
    $lines = explode("\n", $projText);
    $projects = [];
    $currentProj = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;

        $isBullet = preg_match('/^[\x{2022}\x{2023}\x{2043}\x{204B}•\-*]\s*(.*)/u', $trimmed, $bm);
        if ($isBullet) {
            if ($currentProj) {
                $currentProj['bullets'][] = trim($bm[1]);
            }
        } else {
            if (strlen($trimmed) < 100) {
                if ($currentProj) {
                    $projects[] = $currentProj;
                }
                $currentProj = [
                    'name' => $trimmed,
                    'bullets' => []
                ];
            } else {
                if ($currentProj) {
                    $currentProj['bullets'][] = $trimmed;
                }
            }
        }
    }
    if ($currentProj) {
        $projects[] = $currentProj;
    }
    return $projects;
}

/**
 * Clean certifications list.
 */
function parseCertifications(string $certText): array {
    $lines = explode("\n", $certText);
    $certs = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!empty($trimmed)) {
            $trimmed = preg_replace('/^[\x{2022}\x{2023}\x{2043}\x{204B}•\-*]\s*/u', '', $trimmed);
            $certs[] = $trimmed;
        }
    }
    return $certs;
}

/**
 * Split skills block by typical delimiters.
 */
function parseSkills(string $skillsText): array {
    $lines = explode("\n", $skillsText);
    $skills = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;

        if (strpos($trimmed, '|') !== false) {
            $parts = explode('|', $trimmed);
        } elseif (strpos($trimmed, ',') !== false) {
            $parts = explode(',', $trimmed);
        } else {
            $parts = [$trimmed];
        }

        foreach ($parts as $p) {
            $pTrim = trim($p);
            if (!empty($pTrim)) {
                $skills[] = $pTrim;
            }
        }
    }
    return $skills;
}

/**
 * Extract culture fit lines (hobbies, volunteer info).
 */
function parseCulture(string $cultureText): array {
    $lines = explode("\n", $cultureText);
    $items = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!empty($trimmed)) {
            $trimmed = preg_replace('/^[\x{2022}\x{2023}\x{2043}\x{204B}•\-*]\s*/u', '', $trimmed);
            $items[] = $trimmed;
        }
    }
    return $items;
}

/**
 * Bold matched keywords dynamically using strict negative lookaround boundaries.
 */
function highlightKeywords(string $text, array $keywords): string {
    $escapedText = htmlspecialchars($text);
    foreach ($keywords as $keyword) {
        $pattern = '/(?<![a-zA-Z0-9])(' . $keyword . ')(?![a-zA-Z0-9])/i';
        $escapedText = preg_replace($pattern, '<strong>$1</strong>', $escapedText);
    }
    return $escapedText;
}

// =========================================================================
// SECTION 3 — FORM SUBMISSION HANDLING
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formSubmitted = true;

    if (isset($_FILES['resume_pdf']) && $_FILES['resume_pdf']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['resume_pdf'];

        if ($file['size'] > 5 * 1024 * 1024) {
            $uploadError = "File is too large. Maximum allowed size is 5 MB.";
            $formSubmitted = false;
        } else {
            $handle = @fopen($file['tmp_name'], 'rb');
            $magic  = $handle ? fread($handle, 4) : '';
            if ($handle) fclose($handle);

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($magic !== '%PDF' || $ext !== 'pdf') {
                $uploadError = "Please upload a valid PDF file.";
                $formSubmitted = false;
            } else {
                $uploadedPdfName = htmlspecialchars(basename($file['name']));

                // Attempt 1: Poppler pdftotext executable
                $resumeText = tryPdfToText($file['tmp_name']);

                // Attempt 2: Pure-PHP fallback
                if (strlen(trim($resumeText)) < 20) {
                    $resumeText = extractTextFromPdf($file['tmp_name']);
                }

                // Clean control characters (like form-feeds \x0C) while keeping tab, LF, CR
                $resumeText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $resumeText);

                if (strlen(trim($resumeText)) < 20) {
                    $uploadError = "Could not extract text from this PDF. It may be a scanned (image-based) PDF. Please try a text-based PDF.";
                    $formSubmitted = false;
                } elseif (isGarbled($resumeText)) {
                    $uploadError = "This PDF uses a custom font encoding that cannot be decoded. "
                        . "Please try one of these fixes:<br><br>"
                        . "&bull; <strong>Re-export from Word:</strong> File → Save As → PDF<br>"
                        . "&bull; <strong>Print to PDF:</strong> Ctrl+P → Microsoft Print to PDF<br>"
                        . "&bull; <strong>Copy-paste method:</strong> Open PDF → Ctrl+A → Ctrl+C → "
                        . "paste into Notepad → save as .txt, then rename to .pdf (won't work for scanning, "
                        . "but this tells you the text is inaccessible)";
                    $formSubmitted = false;
                }
            }
        }
    } elseif (isset($_FILES['resume_pdf'])) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload limit (upload_max_filesize in php.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected. Please choose a PDF.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write file to disk.',
        ];
        $code = $_FILES['resume_pdf']['error'];
        $uploadError = $errorMessages[$code] ?? "Upload failed (code {$code}).";
        $formSubmitted = false;
    } else {
        $uploadError = "No file was selected. Please choose a PDF resume to upload.";
        $formSubmitted = false;
    }

    // =========================================================================
    // SECTION 4 — ANALYSIS PIPELINE (runs on successful extraction)
    // =========================================================================
    if ($formSubmitted && !empty($resumeText)) {
        $lowercaseText = strtolower($resumeText);
        $lines = explode("\n", trim($resumeText));

        // A. Contact info extraction
        $name = 'Unknown Candidate';
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strlen($line) < 60) {
                $name = $line;
                break;
            }
        }

        $email = preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $resumeText, $m) ? trim($m[0]) : '';
        $phone = preg_match('/\(?\d{3}\)?[-.\\s]?\d{3}[-.\\s]?\d{4}/', $resumeText, $m) ? trim($m[0]) : '';
        $location = preg_match('/\b[A-Za-z\s]{2,},\s*[A-Z]{2}\b/', $resumeText, $m) ? trim($m[0]) : '';
        $linkedin = preg_match('/(?:linkedin\.com\/in\/|linkedin\.com\/)[a-zA-Z0-9_-]+/i', $resumeText, $m) ? trim($m[0]) : '';
        $github = preg_match('/(?:github\.com\/)[a-zA-Z0-9_-]+/i', $resumeText, $m) ? trim($m[0]) : '';
        
        $website = '';
        if (preg_match('/(?:portfolio|website|site):\s*(\S+)/i', $resumeText, $m)) {
            $website = trim($m[1]);
        } elseif (preg_match('/\b(?:https?:\/\/)?(?:www\.)?([a-zA-Z0-9-]+\.[a-z]{2,3}(?:\/[^\s|]+)?)\b/i', $resumeText, $m)) {
            $url = $m[0];
            if (strpos($url, '@') === false && stripos($url, 'linkedin.com') === false && stripos($url, 'github.com') === false) {
                $website = trim($url);
            }
        }

        // B. Calculate general PILLAR 2: Tenure
        $tenureYears = estimateTenure($resumeText);
        if ($tenureYears >= 6) {
            $pillar2Score = 30;
        } elseif ($tenureYears >= 3) {
            $pillar2Score = 20;
        } else {
            $pillar2Score = 10;
        }

        // C. Calculate general PILLAR 3: Quantifiable Impact
        $actionVerbs = ['led','built','optimized','implemented','designed','developed','increased','decreased','reduced','launched','architected','migrated','automated','scaled','shipped','deployed','refactored','mentored','created','delivered'];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) < 15) continue;
            $ll = strtolower($line);
            $hasNumber = (bool) preg_match('/\d+/', $line);
            $hasVerb = false;
            foreach ($actionVerbs as $verb) {
                if (strpos($ll, $verb) !== false) {
                    $hasVerb = true;
                    break;
                }
            }
            if (($hasNumber || $hasVerb) && count($extractedMetrics) < 4) {
                $extractedMetrics[] = $line;
            }
        }
        $pillar3Score = count($extractedMetrics) * 5;

        // D. Calculate PILLAR 1 (Skill Match) per archetype and sum total scores
        $archetypes = [
            'Frontend' => [
                'core' => ['react', 'javascript', 'typescript', 'next\.js', 'vue', 'angular', 'svelte', 'webpack'],
                'supporting' => ['tailwind', 'css', 'sass', 'figma', 'html', 'frontend', 'ui', 'ux']
            ],
            'Backend'  => [
                'core' => ['java', 'golang', 'rust', 'node', 'express', 'graphql', 'grpc', 'go', 'microservices'],
                'supporting' => ['rest', 'api', 'sql', 'postgresql', 'redis', 'kafka', 'backend', 'c\+\+', 'git']
            ],
            'Data'     => [
                'core' => ['python', 'pandas', 'numpy', 'spark', 'airflow', 'bigquery', 'tensorflow', 'machine learning'],
                'supporting' => ['sql', 'etl', 'tableau', 'data scientist', 'dbt', 'statistics', 'regression', 'nlp', 'r']
            ],
            'DevOps'   => [
                'core' => ['devops', 'terraform', 'jenkins', 'kubernetes', 'docker', 'aws', 'gcp', 'azure', 'ci\/cd', 'pipeline', 'pipelines'],
                'supporting' => ['ansible', 'bash', 'sre', 'linux', 'helm', 'prometheus', 'grafana', 'cloudformation', 'chef', 'puppet', 'vault', 'packer', 'github actions', 'gitlab ci', 'circleci', 'argocd', 'spinnaker', 'nginx', 'haproxy', 'infrastructure as code', 'observability', 'on-call']
            ],
        ];

        $archetypeDetails = [];

        foreach ($archetypes as $type => $categories) {
            $coreMatched = 0;
            $supportingMatched = 0;
            $p1 = 0;

            foreach ($categories['core'] as $keyword) {
                $pattern = '/(?<![a-zA-Z0-9])' . $keyword . '(?![a-zA-Z0-9])/i';
                if (preg_match($pattern, $resumeText)) {
                    $coreMatched++;
                    $p1 += 3;
                }
            }

            foreach ($categories['supporting'] as $keyword) {
                $pattern = '/(?<![a-zA-Z0-9])' . $keyword . '(?![a-zA-Z0-9])/i';
                if (preg_match($pattern, $resumeText)) {
                    $supportingMatched++;
                    $p1 += 1;
                }
            }

            $p1Cap = min($p1, 50);
            $totalScore = $p1Cap + $pillar2Score + $pillar3Score;

            $scores[$type] = $totalScore;

            $archetypeDetails[$type] = [
                'core_count' => $coreMatched,
                'supporting_count' => $supportingMatched,
                'p1_score' => $p1Cap,
            ];
        }

        // Sort descending to find primary archetype
        arsort($scores);
        $identifiedArchetype = key($scores);
        $atsScore = $scores[$identifiedArchetype];

        // Winning details for display
        $matchedCoreCount = $archetypeDetails[$identifiedArchetype]['core_count'];
        $matchedSupportingCount = $archetypeDetails[$identifiedArchetype]['supporting_count'];
        $winningPillar1 = $archetypeDetails[$identifiedArchetype]['p1_score'];
        $winningPillar2 = $pillar2Score;
        $winningPillar3 = $pillar3Score;

        $archetypeMap = [
            'Frontend' => '🎨 Frontend / Product Engineer',
            'Backend'  => '⚙️ Backend / Systems Engineer',
            'Data'     => '📊 Data / Analytics Engineer',
            'DevOps'   => '🚀 DevOps / SRE Cloud Engineer',
        ];
        $archetypeEmoji = $archetypeMap[$identifiedArchetype] ?? '🤔 Generalist';

        // E. Company profile parsing and structure extraction
        $sections = getResumeSections($resumeText);
        $parsedEdu = isset($sections['education']) ? parseEducation($sections['education']) : [];
        $parsedExp = isset($sections['experience']) ? parseExperience($sections['experience']) : [];
        $parsedProj = isset($sections['projects']) ? parseProjects($sections['projects']) : [];
        $parsedCert = isset($sections['certifications']) ? parseCertifications($sections['certifications']) : [];
        $parsedSkills = isset($sections['skills']) ? parseSkills($sections['skills']) : [];
        $parsedCulture = isset($sections['culture']) ? parseCulture($sections['culture']) : [];

        $winningKeywords = array_merge(
            $archetypes[$identifiedArchetype]['core'],
            $archetypes[$identifiedArchetype]['supporting']
        );

        // F. Collect matched lines for rubric dropdowns
        $textLines = explode("\n", $resumeText);
        $pillar1Details = ['core' => [], 'supporting' => []];

        foreach ($archetypes[$identifiedArchetype]['core'] as $keyword) {
            $pattern = '/(?<![a-zA-Z0-9])' . $keyword . '(?![a-zA-Z0-9])/i';
            $matchingLines = [];
            if (preg_match($pattern, $resumeText)) {
                foreach ($textLines as $line) {
                    $trimmedLine = trim($line);
                    if (!empty($trimmedLine) && preg_match($pattern, $trimmedLine)) {
                        $matchingLines[] = $trimmedLine;
                        if (count($matchingLines) >= 2) break; // Limit to 2 lines
                    }
                }
            }
            $pillar1Details['core'][$keyword] = $matchingLines;
        }

        foreach ($archetypes[$identifiedArchetype]['supporting'] as $keyword) {
            $pattern = '/(?<![a-zA-Z0-9])' . $keyword . '(?![a-zA-Z0-9])/i';
            $matchingLines = [];
            if (preg_match($pattern, $resumeText)) {
                foreach ($textLines as $line) {
                    $trimmedLine = trim($line);
                    if (!empty($trimmedLine) && preg_match($pattern, $trimmedLine)) {
                        $matchingLines[] = $trimmedLine;
                        if (count($matchingLines) >= 2) break;
                    }
                }
            }
            $pillar1Details['supporting'][$keyword] = $matchingLines;
        }

        $pillar2Details = [];
        $monthsPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})';
        $rangePattern = '#(?:' . $monthsPattern . '[\s\/-]+)?\b(19\d\d|20[0-2]\d)\s*(?:-|–|—|\/|to)\s*(?:' . $monthsPattern . '[\s\/-]+)?\b(20[0-2]\d|Present|Current|Now)\b#ui';
        $explicitPattern = '/\b(\d+)\+?\s*years?\s+(?:of\s+)?(?:experience|work|professional|industry|career|tenure)\b/i';
        $explicitPattern2 = '/(?:experience|tenure|work)\s+(?:of\s+)?(?:active\s+)?(\d+)\+?\s*years?/i';

        foreach ($textLines as $line) {
            $trimmedLine = trim($line);
            if (!empty($trimmedLine)) {
                if (preg_match($rangePattern, $trimmedLine) || preg_match($explicitPattern, $trimmedLine) || preg_match($explicitPattern2, $trimmedLine)) {
                    $pillar2Details[] = $trimmedLine;
                }
            }
        }
        $pillar2Details = array_values(array_unique($pillar2Details));
    }
}

// =========================================================================
// SECTION 5 — HELPER FUNCTIONS FOR BADGE COLORING
// =========================================================================
function get_score_label(int $score): array
{
    if ($score >= 80) return ['Strong Pass ✅',  '#1e8e3e']; // Gemini success green
    if ($score >= 60) return ['Likely Pass 🟡',  '#b06000']; // Gemini amber/yellow
    if ($score >= 40) return ['Borderline ⚠️',  '#d97706']; // Orange
    return             ['Likely Rejected ❌', '#d93025']; // Gemini danger red
}

$scoreLabel = get_score_label($atsScore);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATS Resume Archetype Scanner</title>
    <meta name="description" content="Upload your PDF resume to get an instant ATS benchmark score, engineering archetype, and impact bullet analysis.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Google+Sans+Display:wght@400;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Gemini Light Design Tokens ── */
        :root {
            --bg:            #f0f4ff;
            --surface:       #ffffff;
            --surface-2:     #f8f9fc;
            --border:        #e0e3ef;
            --border-focus:  #4a90e2;

            /* Gemini gradient: blue → violet → rose */
            --gem-a:  #4285f4;
            --gem-b:  #7c5cfc;
            --gem-c:  #c084fc;
            --gem-gradient: linear-gradient(135deg, var(--gem-a) 0%, var(--gem-b) 55%, var(--gem-c) 100%);

            --primary:       #1a73e8;
            --primary-light: #e8f0fe;
            --primary-dark:  #1557b0;

            --text:          #1c1b1f;
            --text-2:        #3c4043;
            --text-muted:    #5f6368;

            --danger:        #d93025;
            --danger-bg:     #fce8e6;
            --success:       #1e8e3e;

            --shadow-sm:     0 1px 3px rgba(60,64,67,.15), 0 1px 2px rgba(60,64,67,.10);
            --shadow-md:     0 2px 8px rgba(60,64,67,.15), 0 1px 4px rgba(60,64,67,.10);
            --shadow-lg:     0 4px 20px rgba(60,64,67,.18), 0 2px 6px rgba(60,64,67,.12);

            --radius:        16px;
            --radius-sm:     10px;
            --radius-pill:   100px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Roboto', 'Google Sans', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 48px 20px 80px;
        }

        /* ── Layout ── */
        .container { max-width: 740px; margin: 0 auto; }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        .gem-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px; height: 56px;
            border-radius: 50%;
            background: var(--gem-gradient);
            margin-bottom: 18px;
            box-shadow: 0 4px 16px rgba(74,144,226,.35);
        }
        .gem-logo svg { width: 28px; height: 28px; fill: white; }

        .header h1 {
            font-family: 'Google Sans Display', 'Google Sans', sans-serif;
            font-size: clamp(1.9rem, 5vw, 2.7rem);
            font-weight: 700;
            letter-spacing: -0.02em;
            background: var(--gem-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
            line-height: 1.2;
        }
        .header p {
            color: var(--text-muted);
            font-size: 1rem;
            max-width: 450px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* ── Card ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            transition: box-shadow .2s;
        }
        .card:hover { box-shadow: var(--shadow-md); }

        .card-title {
            font-family: 'Google Sans', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }
        .card-sub {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 22px;
        }

        /* ── Upload Zone ── */
        .upload-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            padding: 38px 24px;
            text-align: center;
            cursor: pointer;
            position: relative;
            background: var(--surface-2);
            transition: border-color .2s, background .2s;
        }
        .upload-zone:hover,
        .upload-zone.drag-over {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .upload-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .upload-icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px; height: 52px;
            background: var(--primary-light);
            border-radius: 50%;
            margin-bottom: 14px;
        }
        .upload-icon-wrap svg { width: 26px; height: 26px; fill: var(--primary); }

        .upload-main {
            font-family: 'Google Sans', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 4px;
        }
        .upload-hint {
            font-size: 0.82rem;
            color: var(--text-muted);
        }
        .file-selected-msg {
            display: none;
            margin-top: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--primary);
            min-height: 18px;
        }

        /* ── Error alert ── */
        .alert-error {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            background: var(--danger-bg);
            border: 1px solid #f5c6c2;
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 22px;
            color: var(--danger);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .alert-error svg { flex-shrink: 0; margin-top: 1px; }

        /* ── Button ── */
        .btn-primary {
            display: block;
            width: 100%;
            margin-top: 20px;
            padding: 14px 24px;
            background: var(--gem-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-pill);
            font-family: 'Google Sans', sans-serif;
            font-size: 0.975rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.01em;
            box-shadow: 0 2px 8px rgba(74,144,226,.3);
            transition: opacity .2s, transform .15s, box-shadow .2s;
        }
        .btn-primary:hover {
            opacity: 0.93;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(74,144,226,.4);
        }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        /* ── PDF badge ── */
        .pdf-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary-light);
            border: 1px solid #c5d9f8;
            color: var(--primary-dark);
            padding: 5px 12px;
            border-radius: var(--radius-pill);
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        /* ── Results ── */
        .result-card {
            border-top: 4px solid transparent;
            border-image: var(--gem-gradient) 1;
            border-radius: var(--radius);
            overflow: hidden;
        }

        .result-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            padding-bottom: 22px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--border);
        }

        .result-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .result-name {
            font-family: 'Google Sans Display', 'Google Sans', sans-serif;
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
            line-height: 1.2;
        }
        .archetype-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary-light);
            color: var(--primary-dark);
            border: 1px solid #c5d9f8;
            padding: 6px 16px;
            border-radius: var(--radius-pill);
            font-family: 'Google Sans', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Score dial */
        .score-dial {
            text-align: center;
            min-width: 110px;
        }
        .score-number {
            font-family: 'Google Sans Display', sans-serif;
            font-size: 3rem;
            font-weight: 700;
            line-height: 1;
            background: var(--gem-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .score-denom {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .verdict-chip {
            display: inline-block;
            margin-top: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: var(--radius-pill);
        }

        /* Info grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .info-item {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 13px 15px;
        }
        .info-item .lbl {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .info-item .val {
            font-size: 0.92rem;
            font-weight: 500;
            color: var(--text-2);
            word-break: break-all;
        }

        /* Score bars */
        .section-label {
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: var(--text-muted);
            margin-bottom: 14px;
        }
        .bar-row {
            margin-bottom: 6px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            transition: background 0.2s, border-color 0.2s;
            user-select: none;
        }
        .bar-row:hover {
            background: var(--surface-2);
            border-color: var(--border);
        }
        .bar-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--text-2);
            margin-bottom: 6px;
        }
        .bar-head-title {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .bar-head-title::after {
            content: '▼';
            font-size: 0.65rem;
            color: var(--text-muted);
            transition: transform 0.2s;
            display: inline-block;
        }
        .bar-row.expanded .bar-head-title::after {
            transform: rotate(180deg);
        }
        .rubric-dropdown {
            display: none;
            margin-bottom: 18px;
            margin-top: 2px;
            padding: 16px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            font-size: 0.85rem;
            color: var(--text-2);
            line-height: 1.5;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
        }
        .rubric-dropdown.show {
            display: block;
        }
        .rubric-section-title {
            font-family: 'Google Sans', sans-serif;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary);
            margin-bottom: 10px;
            margin-top: 14px;
        }
        .rubric-section-title:first-child {
            margin-top: 0;
        }
        .rubric-list {
            list-style: none;
            padding-left: 0;
        }
        .rubric-item {
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .rubric-item:last-child {
            margin-bottom: 0;
        }
        .rubric-item.matched {
            color: var(--text);
        }
        .rubric-item.unmatched {
            opacity: 0.55;
            color: var(--text-muted);
        }
        .rubric-item-header {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }
        .rubric-badge {
            font-size: 0.65rem;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .rubric-badge.matched {
            background: #e6f4ea;
            color: #137333;
            border: 1px solid #c4eed0;
        }
        .rubric-badge.unmatched {
            background: #f1f3f4;
            color: #5f6368;
            border: 1px solid #dadce0;
        }
        .rubric-quote {
            margin: 4px 0 6px 12px;
            padding-left: 8px;
            border-left: 2px solid var(--primary);
            font-style: italic;
            color: var(--text-muted);
            font-size: 0.8rem;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .bar-track {
            height: 8px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-pill);
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            border-radius: var(--radius-pill);
            transition: width .9s cubic-bezier(.22,1,.36,1);
        }
        .bar-fill.contact  { background: linear-gradient(90deg, #4285f4, #669df6); }
        .bar-fill.keywords { background: linear-gradient(90deg, #7c5cfc, #a78bfa); }
        .bar-fill.impact   { background: linear-gradient(90deg, #1e8e3e, #34a853); }

        /* Archetype Signal breakdown */
        .archetype-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 0.9rem;
        }
        .archetype-row .arch-name {
            width: 100px;
            color: var(--text-2);
            font-weight: 500;
        }
        .arch-bar-track {
            flex: 1;
            height: 6px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-pill);
            overflow: hidden;
        }
        .arch-bar-fill {
            height: 100%;
            border-radius: var(--radius-pill);
            background: var(--border);
        }
        .arch-bar-fill.winner {
            background: var(--gem-gradient);
        }
        .arch-count {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            min-width: 30px;
            text-align: right;
        }

        .divider { height: 1px; background: var(--border); margin: 22px 0; }

        @media (max-width: 540px) {
            .card { padding: 22px 18px; }
            .result-top { flex-direction: column; }
            .header h1 { font-size: 1.8rem; }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- ── Header ── -->
    <div class="header">
        <div class="gem-logo">
            <!-- Gemini-style sparkle icon -->
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L13.5 9.5L21 11L13.5 12.5L12 20L10.5 12.5L3 11L10.5 9.5Z"/>
            </svg>
        </div>
        <h1>Resume Archetype Scanner</h1>
        <p>Upload your PDF resume for an instant ATS benchmark score, engineering archetype, and impact analysis.</p>
    </div>

    <!-- ── Upload Card ── -->
    <div class="card">
        <div class="card-title">Upload Resume (PDF)</div>
        <div class="card-sub">We'll extract your text, detect your engineering archetype, and score your resume.</div>

        <?php if (!empty($uploadError)): ?>
        <div class="alert-error" role="alert">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?php echo $uploadError; ?></span>
        </div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" id="resume-form">
            <div class="upload-zone" id="drop-zone">
                <input type="file" name="resume_pdf" id="pdf-input" accept=".pdf,application/pdf" required>
                <div class="upload-icon-wrap">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                </div>
                <div class="upload-main">Click to choose or drag &amp; drop your PDF</div>
                <div class="upload-hint">PDF files only &nbsp;·&nbsp; Max 5 MB</div>
                <div id="file-name" class="file-selected-msg"></div>
            </div>

            <button type="submit" class="btn-primary" id="scan-btn">
                ✦ &nbsp;Scan &amp; Categorize Resume
            </button>
        </form>
    </div>

    <!-- ── Results ── -->
    <?php if ($formSubmitted && empty($uploadError)): ?>
    <div class="card result-card">

        <!-- PDF badge -->
        <div class="pdf-badge">
            📄 <?php echo $uploadedPdfName; ?>
        </div>

        <!-- Top banner -->
        <div class="result-top">
            <div>
                <div class="result-label">Candidate Profile</div>
                <div class="result-name"><?php echo htmlspecialchars($name); ?></div>
                <div class="archetype-chip"><?php echo htmlspecialchars($archetypeEmoji); ?></div>
            </div>
            <div class="score-dial">
                <div class="score-number"><?php echo (int)$atsScore; ?></div>
                <div class="score-denom">out of 100</div>
                <div class="verdict-chip" style="background:<?php echo $scoreLabel[1]; ?>12; color:<?php echo $scoreLabel[1]; ?>; border: 1px solid <?php echo $scoreLabel[1]; ?>33;">
                    <?php echo htmlspecialchars($scoreLabel[0]); ?>
                </div>
            </div>
        </div>

        <!-- Contact / meta grid -->
        <div class="info-grid">
            <div class="info-item">
                <div class="lbl">Email Address</div>
                <div class="val"><?php echo htmlspecialchars($email); ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Phone Number</div>
                <div class="val"><?php echo htmlspecialchars($phone); ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Longevity / Tenure</div>
                <div class="val"><?php echo $tenureYears; ?> Years</div>
            </div>
            <div class="info-item">
                <div class="lbl">Matched Skills</div>
                <div class="val"><?php echo $matchedCoreCount; ?> Core / <?php echo $matchedSupportingCount; ?> Supp.</div>
            </div>
        </div>

        <!-- Score breakdown bars -->
        <div class="section-label">Score Breakdown (Click each bar to view detail rubric)</div>
        <div class="bar-row" data-target="rubric-p1">
            <div class="bar-head"><span class="bar-head-title">Skill Match (Pillar 1)</span><span><?php echo $winningPillar1; ?> / 50</span></div>
            <div class="bar-track"><div class="bar-fill contact" style="width:<?php echo ($winningPillar1 / 50) * 100; ?>%"></div></div>
        </div>
        <div id="rubric-p1" class="rubric-dropdown">
            <div class="rubric-section-title">Core Skills (3 pts each)</div>
            <ul class="rubric-list">
                <?php foreach ($pillar1Details['core'] as $kw => $lines): 
                    $isMatched = !empty($lines);
                ?>
                    <li class="rubric-item <?php echo $isMatched ? 'matched' : 'unmatched'; ?>">
                        <div class="rubric-item-header">
                            <span class="rubric-badge <?php echo $isMatched ? 'matched' : 'unmatched'; ?>">
                                <?php echo $isMatched ? '✓ Match' : '✗ No Match'; ?>
                            </span>
                            <strong><?php echo htmlspecialchars(ucfirst($kw)); ?></strong>
                        </div>
                        <?php if ($isMatched): ?>
                            <?php foreach ($lines as $line): ?>
                                <div class="rubric-quote">"... <?php echo htmlspecialchars($line); ?> ..."</div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="rubric-section-title">Supporting Skills (1 pt each)</div>
            <ul class="rubric-list">
                <?php foreach ($pillar1Details['supporting'] as $kw => $lines): 
                    $isMatched = !empty($lines);
                ?>
                    <li class="rubric-item <?php echo $isMatched ? 'matched' : 'unmatched'; ?>">
                        <div class="rubric-item-header">
                            <span class="rubric-badge <?php echo $isMatched ? 'matched' : 'unmatched'; ?>">
                                <?php echo $isMatched ? '✓ Match' : '✗ No Match'; ?>
                            </span>
                            <strong><?php echo htmlspecialchars(ucfirst($kw)); ?></strong>
                        </div>
                        <?php if ($isMatched): ?>
                            <?php foreach ($lines as $line): ?>
                                <div class="rubric-quote">"... <?php echo htmlspecialchars($line); ?> ..."</div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="bar-row" data-target="rubric-p2">
            <div class="bar-head"><span class="bar-head-title">Tenure &amp; Longevity (Pillar 2)</span><span><?php echo $winningPillar2; ?> / 30</span></div>
            <div class="bar-track"><div class="bar-fill keywords" style="width:<?php echo ($winningPillar2 / 30) * 100; ?>%"></div></div>
        </div>
        <div id="rubric-p2" class="rubric-dropdown">
            <div class="rubric-section-title">Experience Tiers</div>
            <ul class="rubric-list">
                <li class="rubric-item <?php echo ($tenureYears >= 6) ? 'matched' : 'unmatched'; ?>">
                    <div class="rubric-item-header">
                        <span class="rubric-badge <?php echo ($tenureYears >= 6) ? 'matched' : 'unmatched'; ?>">
                            <?php echo ($tenureYears >= 6) ? '✓ Active' : 'Tier'; ?>
                        </span>
                        <strong>Senior Tier (6+ Years)</strong> — 30 points
                    </div>
                </li>
                <li class="rubric-item <?php echo ($tenureYears >= 3 && $tenureYears < 6) ? 'matched' : 'unmatched'; ?>">
                    <div class="rubric-item-header">
                        <span class="rubric-badge <?php echo ($tenureYears >= 3 && $tenureYears < 6) ? 'matched' : 'unmatched'; ?>">
                            <?php echo ($tenureYears >= 3 && $tenureYears < 6) ? '✓ Active' : 'Tier'; ?>
                        </span>
                        <strong>Mid Tier (3-5 Years)</strong> — 20 points
                    </div>
                </li>
                <li class="rubric-item <?php echo ($tenureYears < 3) ? 'matched' : 'unmatched'; ?>">
                    <div class="rubric-item-header">
                        <span class="rubric-badge <?php echo ($tenureYears < 3) ? 'matched' : 'unmatched'; ?>">
                            <?php echo ($tenureYears < 3) ? '✓ Active' : 'Tier'; ?>
                        </span>
                        <strong>Junior Tier (<3 Years)</strong> — 10 points
                    </div>
                </li>
            </ul>

            <div class="rubric-section-title" style="margin-top: 14px;">Extracted Longevity (<?php echo $tenureYears; ?> Years)</div>
            <?php if (!empty($pillar2Details)): ?>
                <p style="margin-bottom: 6px; font-weight: 500; font-size: 0.8rem; color: var(--text-muted);">Tenure calculated from these matching resume lines:</p>
                <?php foreach ($pillar2Details as $line): ?>
                    <div class="rubric-quote">"<?php echo htmlspecialchars($line); ?>"</div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="font-style: italic; color: var(--text-muted);">No date ranges or experience declarations detected. Defaulting to 0 years.</p>
            <?php endif; ?>
        </div>

        <div class="bar-row" data-target="rubric-p3">
            <div class="bar-head"><span class="bar-head-title">Quantifiable Impact (Pillar 3)</span><span><?php echo $winningPillar3; ?> / 20</span></div>
            <div class="bar-track"><div class="bar-fill impact" style="width:<?php echo ($winningPillar3 / 20) * 100; ?>%"></div></div>
        </div>
        <div id="rubric-p3" class="rubric-dropdown">
            <div class="rubric-section-title">Impact Rubric</div>
            <p style="margin-bottom: 10px; color: var(--text-muted); font-size: 0.8rem;">
                Earns <strong>5 points</strong> per unique line that quantifies achievements (requires a number and an action verb) up to a maximum of 4 lines (20 points total).
            </p>
            <ul class="rubric-list">
                <?php 
                for ($i = 0; $i < 4; $i++): 
                    $hasMetric = isset($extractedMetrics[$i]);
                ?>
                    <li class="rubric-item <?php echo $hasMetric ? 'matched' : 'unmatched'; ?>">
                        <div class="rubric-item-header">
                            <span class="rubric-badge <?php echo $hasMetric ? 'matched' : 'unmatched'; ?>">
                                <?php echo $hasMetric ? '✓ Matched (+5 pts)' : '✗ Empty'; ?>
                            </span>
                            <strong>Metric Line <?php echo ($i + 1); ?></strong>
                        </div>
                        <?php if ($hasMetric): ?>
                            <div class="rubric-quote">"<?php echo htmlspecialchars($extractedMetrics[$i]); ?>"</div>
                        <?php endif; ?>
                    </li>
                <?php endfor; ?>
            </ul>
        </div>

        <div class="divider"></div>

        <!-- Archetype Comparison bars -->
        <div class="section-label">Archetype Signal Breakdown</div>
        <?php
            $archetypeIcons = ['Frontend' => '🎨', 'Backend' => '⚙️', 'Data' => '📊', 'DevOps' => '🚀'];

            foreach ($scores as $arcType => $arcScore):
                $isWinner = ($arcType === $identifiedArchetype);
        ?>
        <div class="archetype-row">
            <div class="arch-name"><?php echo $archetypeIcons[$arcType] ?? ''; ?> <?php echo $arcType; ?></div>
            <div class="arch-bar-track">
                <div class="arch-bar-fill <?php echo $isWinner ? 'winner' : ''; ?>" style="width:<?php echo $arcScore; ?>%"></div>
            </div>
            <div class="arch-count"><?php echo $arcScore; ?> / 100</div>
        </div>
        <?php endforeach; ?>

        <div class="divider"></div>

        <!-- ── COMPANY PROFILE CANDIDATE OVERVIEW ── -->
        <div class="section-label" style="font-size: 0.85rem; letter-spacing: 0.1em; margin-bottom: 18px;">Company Candidate Profile Overview</div>

        <!-- Overview details card -->
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 12px;">Overview Details</h3>
            <div style="font-size: 0.95rem; line-height: 1.6; color: var(--text-2);">
                <?php if (!empty($name) && $name !== 'Unknown Candidate'): ?>
                    <p style="margin-bottom: 6px;"><strong>Name:</strong> <?php echo htmlspecialchars($name); ?></p>
                <?php endif; ?>
                <?php if (!empty($archetypeEmoji)): ?>
                    <p style="margin-bottom: 6px;"><strong>Type of Engineer:</strong> <?php echo htmlspecialchars($archetypeEmoji); ?></p>
                <?php endif; ?>
                <?php if (!empty($email)): ?>
                    <p style="margin-bottom: 6px;"><strong>Email:</strong> <?php echo htmlspecialchars($email); ?></p>
                <?php endif; ?>
                <?php if (!empty($phone)): ?>
                    <p style="margin-bottom: 6px;"><strong>Phone:</strong> <?php echo htmlspecialchars($phone); ?></p>
                <?php endif; ?>
                <?php if (!empty($location)): ?>
                    <p style="margin-bottom: 6px;"><strong>Location:</strong> <?php echo htmlspecialchars($location); ?></p>
                <?php endif; ?>
                <?php if (!empty($linkedin)): ?>
                    <p style="margin-bottom: 6px;"><strong>LinkedIn:</strong> <?php echo htmlspecialchars($linkedin); ?></p>
                <?php endif; ?>
                <?php if (!empty($github)): ?>
                    <p style="margin-bottom: 6px;"><strong>GitHub:</strong> <?php echo htmlspecialchars($github); ?></p>
                <?php endif; ?>
                <?php if (!empty($website)): ?>
                    <p style="margin-bottom: 6px;"><strong>Website:</strong> <?php echo htmlspecialchars($website); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Education card -->
        <?php if (!empty($parsedEdu)): ?>
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 12px;">Education</h3>
            <div style="font-size: 0.95rem; line-height: 1.6; color: var(--text-2);">
                <?php 
                $eduCount = count($parsedEdu);
                foreach ($parsedEdu as $idx => $edu): 
                    $isLast = ($idx === $eduCount - 1);
                    $borderStyle = $isLast ? '' : 'border-bottom: 1px dashed var(--border); padding-bottom: 12px; margin-bottom: 12px;';
                ?>
                    <div style="<?php echo $borderStyle; ?>">
                        <?php if (!empty($edu['course'])): ?>
                            <p style="margin-bottom: 4px;"><strong>Degree:</strong> <?php echo htmlspecialchars($edu['course']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($edu['university'])): ?>
                            <p style="margin-bottom: 4px;"><strong>Institution:</strong> <?php echo htmlspecialchars($edu['university']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($edu['grade'])): ?>
                            <p style="margin-bottom: 4px;"><strong>Grade/GPA:</strong> <?php echo htmlspecialchars($edu['grade']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($tenureYears)): ?>
                    <p style="margin-top: 10px;"><strong>Time in Industry:</strong> <?php echo $tenureYears; ?> Years</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Experience card -->
        <?php if (!empty($parsedExp)): ?>
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 16px;">Professional Experience</h3>
            <?php 
            $expCount = count($parsedExp);
            foreach ($parsedExp as $idx => $job): 
                $isLast = ($idx === $expCount - 1);
                $borderStyle = $isLast ? '' : 'border-bottom: 1px solid var(--border); padding-bottom: 16px; margin-bottom: 18px;';
            ?>
                <div style="<?php echo $borderStyle; ?>">
                    <?php if (!empty($job['role'])): ?>
                        <p style="margin-bottom: 4px;"><strong>Role Name:</strong> <?php echo htmlspecialchars($job['role']); ?></p>
                        <p style="margin-bottom: 4px;"><strong>Position in Company:</strong> <?php echo htmlspecialchars($job['role']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($job['company'])): ?>
                        <p style="margin-bottom: 4px;"><strong>Company:</strong> <?php echo htmlspecialchars($job['company']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($job['dates'])): ?>
                        <p style="margin-bottom: 4px;"><strong>Work Time:</strong> <?php echo htmlspecialchars($job['dates']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($job['reference'])): ?>
                        <p style="margin-bottom: 4px;"><strong>Reference Contact Details:</strong> <?php echo htmlspecialchars($job['reference']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($job['bullets'])): ?>
                        <p style="margin-top: 8px; margin-bottom: 4px; font-weight: 600;">Key Points:</p>
                        <ul style="list-style: none; padding-left: 0; font-size: 0.9rem; color: var(--text-2); line-height: 1.5;">
                            <?php foreach ($job['bullets'] as $bullet): ?>
                                <li style="margin-bottom: 6px; display: flex; gap: 8px; align-items: flex-start;">
                                    <span style="color: var(--primary); font-size: 0.85rem;">✦</span>
                                    <span><?php echo highlightKeywords($bullet, $winningKeywords); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Projects card -->
        <?php if (!empty($parsedProj)): ?>
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 12px;">Projects</h3>
            <?php 
            $projCount = count($parsedProj);
            foreach ($parsedProj as $idx => $proj): 
                $isLast = ($idx === $projCount - 1);
                $borderStyle = $isLast ? '' : 'border-bottom: 1px dashed var(--border); padding-bottom: 12px; margin-bottom: 12px;';
            ?>
                <div style="<?php echo $borderStyle; ?>">
                    <?php if (!empty($proj['name'])): ?>
                        <p style="margin-bottom: 4px;"><strong>Project Name:</strong> <?php echo htmlspecialchars($proj['name']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($proj['bullets'])): ?>
                        <p style="margin-top: 6px; margin-bottom: 4px; font-weight: 600;">Key Points:</p>
                        <ul style="list-style: none; padding-left: 0; font-size: 0.9rem; color: var(--text-2); line-height: 1.5;">
                            <?php foreach ($proj['bullets'] as $bullet): ?>
                                <li style="margin-bottom: 4px; display: flex; gap: 8px; align-items: flex-start;">
                                    <span style="color: var(--primary); font-size: 0.8rem;">✦</span>
                                    <span><?php echo highlightKeywords($bullet, $winningKeywords); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Certifications card -->
        <?php if (!empty($parsedCert)): ?>
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 12px;">Certifications</h3>
            <ul style="list-style: none; padding-left: 0; font-size: 0.9rem; color: var(--text-2); line-height: 1.6;">
                <?php foreach ($parsedCert as $cert): ?>
                    <li style="margin-bottom: 6px; display: flex; gap: 8px; align-items: flex-start;">
                        <span style="color: var(--primary); font-size: 0.85rem;">✦</span>
                        <span><?php echo highlightKeywords($cert, $winningKeywords); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Skills card -->
        <?php if (!empty($parsedSkills)): ?>
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 14px;">Technical Skills</h3>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <?php foreach ($parsedSkills as $skill): ?>
                    <span style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-pill); padding: 6px 14px; font-size: 0.85rem; font-weight: 500; color: var(--text-2); box-shadow: var(--shadow-sm);">
                        <?php echo highlightKeywords($skill, $winningKeywords); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Culture Fit card -->
        <?php if (!empty($parsedCulture)): ?>
        <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 18px 20px; margin-bottom: 16px; box-shadow: var(--shadow-sm);">
            <h3 style="font-family: 'Google Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 12px;">Culture Fit &amp; Interests</h3>
            <ul style="list-style: none; padding-left: 0; font-size: 0.9rem; color: var(--text-2); line-height: 1.6;">
                <?php foreach ($parsedCulture as $item): ?>
                    <li style="margin-bottom: 6px; display: flex; gap: 8px; align-items: flex-start;">
                        <span style="color: var(--primary); font-size: 0.85rem;">✦</span>
                        <span><?php echo htmlspecialchars($item); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="divider"></div>

        <!-- Tips cards -->
        <?php if ($atsScore < 80): ?>
        <div class="tips-card">
            <strong>💡 Suggestions to optimize score</strong>
            <?php if ($winningPillar1 < 35): ?>• Boost archetype density (list key tools: Docker, Terraform, React, Go, etc.).<br><?php endif; ?>
            <?php if ($winningPillar2 < 30): ?>• Add date ranges to clearly indicate professional experience spans.<br><?php endif; ?>
            <?php if ($winningPillar3 < 20): ?>• Quantify your achievements (e.g. "Reduced database query time by 30% by refactoring indices").<br><?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div><!-- /container -->

<script>
    const input   = document.getElementById('pdf-input');
    const display = document.getElementById('file-name');
    const zone    = document.getElementById('drop-zone');
    const btn     = document.getElementById('scan-btn');
    const form    = document.getElementById('resume-form');

    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            display.style.display = 'block';
            display.textContent = '✔ Selected: ' + input.files[0].name;
            btn.textContent = '✦   Scan "' + input.files[0].name + '"';
        } else {
            display.style.display = 'none';
            display.textContent = '';
            btn.textContent = '✦   Scan & Categorize Resume';
        }
    });

    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    ['dragleave','dragend','drop'].forEach(ev => zone.addEventListener(ev, () => zone.classList.remove('drag-over')));

    form.addEventListener('submit', () => {
        btn.textContent = '⏳ Analysing...';
        btn.disabled = true;
    });

    // Score breakdown bar-row expander logic
    document.querySelectorAll('.bar-row').forEach(row => {
        row.addEventListener('click', () => {
            const targetId = row.getAttribute('data-target');
            const dropdown = document.getElementById(targetId);
            if (dropdown) {
                const isExpanded = dropdown.classList.toggle('show');
                row.classList.toggle('expanded', isExpanded);
            }
        });
    });
</script>

</body>
</html>
