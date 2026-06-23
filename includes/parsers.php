<?php

/**
 * Filter the resume text to exclude company names and dates to avoid false positives during skill matching.
 */
function getSkillSearchText(string $resumeText, array $sections, array $parsedExp): string {
    $searchParts = [];
    if (!empty($sections['summary'])) {
        $searchParts[] = $sections['summary'];
    }
    if (!empty($sections['skills'])) {
        $searchParts[] = $sections['skills'];
    }
    if (!empty($sections['certifications'])) {
        $searchParts[] = $sections['certifications'];
    }
    if (!empty($sections['projects'])) {
        $searchParts[] = $sections['projects'];
    }
    foreach ($parsedExp as $job) {
        if (!empty($job['role'])) {
            $searchParts[] = $job['role'];
        }
        if (!empty($job['bullets'])) {
            $searchParts[] = implode(' ', $job['bullets']);
        }
    }
    if (empty($searchParts)) {
        return $resumeText;
    }
    return implode(' ', $searchParts);
}

/**
 * Split the raw text of the resume into logical sections based on headings.
 */
function getResumeSections(string $text): array {
    $headings = [
        'summary'        => ['/^(?:PROFESSIONAL\s+)?SUMMARY\b/im', '/^ABOUT\s+ME\b/im', '/^OBJECTIVE\b/im'],
        'experience'     => [
            '/^(?:WORK\s+|PROFESSIONAL\s+|EMPLOYMENT\s+|LEADERSHIP\s+|LEADERSHIP\s+&\s+)?EXPERIENCE\b/im',
            '/^WORK\s+HISTORY\b/im',
            '/^CAREER\s+HISTORY\b/im',
            '/^LEADERSHIP(?:\s+(?:&|and)\s+ACTIVITIES)?\b/im'
        ],
        'education'      => ['/^(?:PROFESSIONAL\s+|ACADEMIC\s+)?EDUCATION\b/im', '/^ACADEMIC\s+BACKGROUND\b/im'],
        'projects'       => ['/^(?:.*?\s+)?PROJECTS\b/im'],
        'certifications' => ['/^(?:.*?\s+)?CERTIFICATIONS\b/im', '/^CREDENTIALS\b/im', '/^LICENSES\b/im'],
        'skills'         => ['/^(?:TECHNICAL\s+|KEY\s+)?SKILLS\b/im', '/^(?:.*?\s+)?TECHNOLOGIES\b/im'],
        'culture'        => ['/^(?:.*?\s+)?(?:INTERESTS|HOBBIES|VOLUNTEER(?:ING)?|COMMUNITY|ACTIVITIES|CAMPUS\s+INVOLVEMENT)\b/im']
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
 * Map the structured JSON emitted by the browser spatial PDF extractor
 * directly into the $sections array and pre-parsed $parsedExp/$parsedEdu arrays.
 *
 * Returns an array: [
 *   'sections'    => [...],        // keyed text sections
 *   'parsedExp'   => [...],        // structured job entries (already parsed)
 *   'resumeText'  => '...',        // reconstructed plain-text for metrics/tenure
 * ]
 */
function mapStructuredJsonToSections(array $structured): array {
    $secs = $structured['sections'] ?? [];
    $sections = [];

    // Plain text sections that PHP parsers still need as raw text
    foreach (['skills', 'summary', 'certifications', 'culture'] as $key) {
        if (!empty($secs[$key])) {
            $sections[$key] = $secs[$key];
        }
    }

    // Projects: may be a structured array (from JS spatial parseProjectRows) or raw text.
    // Convert array → clean text so parseProjects() sees proper project-name lines and bullets.
    if (!empty($secs['projects'])) {
        if (is_array($secs['projects'])) {
            $projLines = [];
            foreach ($secs['projects'] as $proj) {
                if (!empty($proj['name'])) $projLines[] = $proj['name'];
                foreach (($proj['bullets'] ?? []) as $b) {
                    $projLines[] = "\u2022 " . $b;
                }
            }
            $sections['projects'] = implode("\n", $projLines);
        } else {
            $sections['projects'] = $secs['projects'];
        }
    }

    // Education stays as raw text so parseEducation() can run on it
    if (!empty($secs['education_text'])) {
        $sections['education'] = $secs['education_text'];
    }

    // Map pre-structured experience jobs into parsedExp format
    $parsedExp = [];
    if (!empty($secs['experience']) && is_array($secs['experience'])) {
        foreach ($secs['experience'] as $job) {
            $parsedExp[] = [
                'role'      => trim($job['role'] ?? ''),
                'company'   => trim($job['company'] ?? ''),
                'dates'     => trim($job['dates'] ?? ''),
                'reference' => '',
                'bullets'   => array_map('trim', $job['bullets'] ?? []),
            ];
        }
    }

    // Use the pre-built plain text if available, otherwise reconstruct
    $resumeText = $structured['_plainText'] ?? '';
    if (empty($resumeText)) {
        $parts = [];
        if (!empty($structured['header'])) $parts[] = $structured['header'];
        foreach ($sections as $key => $text) {
            $parts[] = strtoupper($key) . "\n" . $text;
        }
        foreach ($parsedExp as $job) {
            $parts[] = implode("\n", array_filter([$job['company'], $job['role'], $job['dates']]));
            foreach ($job['bullets'] as $b) $parts[] = '• ' . $b;
        }
        $resumeText = implode("\n\n", $parts);
    }

    return [
        'sections'   => $sections,
        'parsedExp'  => $parsedExp,
        'resumeText' => $resumeText,
    ];
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

        // Check if there is a GPA in the line, handling optional colon and full denominator scale
        $gpa = '';
        if (preg_match('/\b(?:GPA|Grade|Score|Marks|CGPA)?\s*[:\-–—]?\s*\(?\s*([0-4]\.\d+\s*(?:\/\s*[45](?:\.\d+)?)?)\s*\)?/i', $line, $gm)) {
            $gpa = $gm[0];
            $lineCleaned = trim(str_replace($gpa, '', $line), " \t\n\r\0\x0B(),:-–—");
        } else {
            $lineCleaned = $line;
        }

        $isDegree = preg_match('/\b(Master|Masters|Bachelor|Bachelors|M\.S\.|B\.S\.|B\.A\.|M\.A\.|B\.Sc\.|M\.Sc\.|B\.E\.|B\.Tech|Degree|Diploma|Graduate|Engineering|Science|Arts|Business)\b/i', $lineCleaned);
        $isUni = preg_match('/\b(University|College|Institute|School|Academy|Polytechnic|State)\b/i', $lineCleaned);

        if ($isDegree) {
            if ($currentDegree && empty($currentDegree['course'])) {
                // We had a pending university block that had no degree course yet
                $currentDegree['course'] = $lineCleaned;
                if (!empty($gpa)) {
                    $currentDegree['grade'] = $gpa;
                }
                $currentDegree['details'][] = $line;
            } else {
                if ($currentDegree) {
                    $degrees[] = $currentDegree;
                }
                $currentDegree = [
                    'course' => $lineCleaned,
                    'university' => '',
                    'grade' => $gpa,
                    'details' => [$line]
                ];
            }
        } elseif ($isUni) {
            if ($currentDegree && empty($currentDegree['university'])) {
                $currentDegree['university'] = $lineCleaned;
                if (!empty($gpa)) {
                    $currentDegree['grade'] = $gpa;
                }
                $currentDegree['details'][] = $line;
            } else {
                if ($currentDegree) {
                    $degrees[] = $currentDegree;
                }
                $currentDegree = [
                    'course' => '',
                    'university' => $lineCleaned,
                    'grade' => $gpa,
                    'details' => [$line]
                ];
            }
        } else {
            if (!empty($gpa) && $currentDegree) {
                $currentDegree['grade'] = $gpa;
            }
            if ($currentDegree) {
                $currentDegree['details'][] = $line;
            }
        }
    }
    if ($currentDegree) {
        $degrees[] = $currentDegree;
    }

    // Filter out entries that have neither a recognized course nor a recognized university
    $filtered = [];
    foreach ($degrees as $d) {
        $course = $d['course'] ?? '';
        $uni = $d['university'] ?? '';

        // Ignore lines that look like bullet lists, bullet points, headers of other sections, or work bullets
        if (preg_match('/^[\x{2022}\x{2023}\x{2043}\x{204B}•\-*✦]\s*/u', trim($course)) || preg_match('/^[\x{2022}\x{2023}\x{2043}\x{204B}•\-*✦]\s*/u', trim($uni))) {
            continue;
        }

        // Ignore if the course/university contains key verbs that match work experience details instead of a school name
        if (preg_match('/\b(allocate|manage|organize|conducted|designed|produced|redesigned|modernized|supervise|calculate|communicate)\b/i', $course . ' ' . $uni)) {
            continue;
        }

        if (!empty($course) || !empty($uni)) {
            $filtered[] = $d;
        }
    }
    return $filtered;
}

/**
 * Segment experience block into structural jobs.
 */
function parseExperience(string $expText): array {
    $lines = explode("\n", $expText);
    $jobs = [];
    $currentJob = null;
    $monthsPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})';
    $rangePattern = '#(?:' . $monthsPattern . '[\s\/-]+)?\b(19\d\d|20[0-4]\d)\s*(?:-|–|—|\/|to)\s*(?:' . $monthsPattern . '[\s\/-]+)?\b(20[0-4]\d|Present|Current|Now)\b#ui';

    $cleanedLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $cleanedLines[] = $trimmed;
        }
    }

    $bulletPattern = '/^[\x{2022}\x{2023}\x{2043}\x{204B}\x{E000}-\x{F8FF}•\-*●▪◦■♦★]\s*(.*)/u';

    for ($i = 0; $i < count($cleanedLines); $i++) {
        $line = $cleanedLines[$i];
        $hasRange = preg_match($rangePattern, $line, $m);

        if ($hasRange) {
            if ($currentJob) {
                $jobs[] = $currentJob;
            }
            $dateRange = $m[0];
            $roleInfo = trim(str_replace($dateRange, '', $line));
            $roleInfo = trim($roleInfo, " \t\n\r\0\x0B|,-–—");

            // Look back to find the company name.
            $company = '';
            if ($i > 0) {
                $prevLine = $cleanedLines[$i - 1];
                $prevHasRange = preg_match($rangePattern, $prevLine);
                $prevIsBullet = preg_match($bulletPattern, $prevLine);
                if (!$prevHasRange && !$prevIsBullet) {
                    $company = $prevLine;
                }
            }

            $currentJob = [
                'role' => $roleInfo,
                'company' => $company,
                'dates' => $dateRange,
                'reference' => '',
                'bullets' => []
            ];
        } else {
            if ($currentJob) {
                $isBullet = preg_match($bulletPattern, $line, $bm);
                if ($isBullet) {
                    $currentJob['bullets'][] = trim($bm[1]);
                } else {
                    if ($line === $currentJob['company']) {
                        continue;
                    }
                    if (stripos($line, 'reference') !== false || preg_match('/contact/i', $line) || preg_match('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', $line)) {
                        $currentJob['reference'] = $line;
                    } else {
                        $isNextJobCompany = false;
                        if ($i + 1 < count($cleanedLines)) {
                            $nextLine = $cleanedLines[$i + 1];
                            if (preg_match($rangePattern, $nextLine)) {
                                $isNextJobCompany = true;
                            }
                        }
                        if ($isNextJobCompany) {
                            continue;
                        }

                        if (!empty($currentJob['bullets'])) {
                            $idx = count($currentJob['bullets']) - 1;
                            $currentJob['bullets'][$idx] .= ' ' . $line;
                        } else {
                            if (empty($currentJob['company'])) {
                                $currentJob['company'] = $line;
                            } else {
                                $currentJob['company'] .= ' | ' . $line;
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
        $pattern = '/(?<![a-zA-Z0-9])(' . preg_quote($keyword, '/') . ')(?![a-zA-Z0-9])/i';
        $escapedText = preg_replace($pattern, '<strong>$1</strong>', $escapedText);
    }
    return $escapedText;
}
