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


require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/extractor.php';
require_once __DIR__ . '/includes/parsers.php';
require_once __DIR__ . '/includes/scorer.php';

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

                // Check if browser-extracted text is provided via POST
                $structuredJson = null;  // will hold decoded spatial JSON if browser sent it
                if (isset($_POST['extracted_text']) && strlen(trim($_POST['extracted_text'])) > 20) {
                    $rawExtracted = $_POST['extracted_text'];
                    // Detect structured spatial JSON from the upgraded browser extractor
                    if (isset($rawExtracted[0]) && $rawExtracted[0] === '{') {
                        $decoded = @json_decode($rawExtracted, true);
                        if ($decoded && isset($decoded['sections'])) {
                            $structuredJson = $decoded;
                            // Use the embedded plain text for text-based analyses
                            $resumeText = $decoded['_plainText'] ?? '';
                        }
                    }
                    if ($structuredJson === null) {
                        $resumeText = $rawExtracted;
                    }
                } else {
                    // Attempt 1: Poppler pdftotext executable
                    $resumeText = tryPdfToText($file['tmp_name']);

                    // Attempt 2: Pure-PHP fallback
                    if (strlen(trim($resumeText)) < 20) {
                        $resumeText = extractTextFromPdf($file['tmp_name']);
                    }
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
        $currentYear = (int)date('Y');
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

        // B. Parse sections and details
        // If the browser sent structured spatial JSON, map it directly (skips fragile regex section splitting
        // and the flat parseExperience parser which caused job-bleed issues).
        if (!empty($structuredJson)) {
            $mapped      = mapStructuredJsonToSections($structuredJson);
            $sections    = $mapped['sections'];
            $parsedExp   = $mapped['parsedExp'];  // already structured — no parseExperience() needed
            // Resume text may have been enriched; keep the mapped version as authoritative
            if (!empty($mapped['resumeText'])) {
                $resumeText      = $mapped['resumeText'];
                $lowercaseText   = strtolower($resumeText);
                $lines           = explode("\n", trim($resumeText));
            }
        } else {
            $sections  = getResumeSections($resumeText);
            $parsedExp = isset($sections['experience']) ? parseExperience($sections['experience']) : [];
        }
        // These parsers run regardless of path (education/projects/skills always come from text)
        $parsedEdu    = isset($sections['education'])      ? parseEducation($sections['education'])         : [];
        $parsedProj   = isset($sections['projects'])       ? parseProjects($sections['projects'])           : [];
        $parsedCert   = isset($sections['certifications']) ? parseCertifications($sections['certifications']): [];
        $parsedSkills = isset($sections['skills'])         ? parseSkills($sections['skills'])               : [];
        $parsedCulture= isset($sections['culture'])        ? parseCulture($sections['culture'])             : [];

        // C. Calculate general tenure years first (score is calculated dynamically per field)
        $tenureYears = estimateTenure($resumeText);

        // D. Calculate general PILLAR 3: Quantifiable Impact
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

        // $rolesMatrix and $fieldDescriptions are loaded globally from includes/data.php
        $descriptions = $fieldDescriptions;

        // Calculate highest graduation year, enrollment status, and chosen degree level beforehand
        $highestGradYear = 0;
        $highestGradMonth = 6;
        $hasUndergradEnrolled = false;
        $selectedDegree = null;
        $highestDegreeScore = -1;
        $currentMonth = (int)date('n');

        foreach ($parsedEdu as $edu) {
            $course = $edu['course'] ?? '';
            $uni = $edu['university'] ?? '';
            
            $eduDetailsText = $course . ' ' . $uni . ' ' . (isset($edu['details']) ? implode(' ', $edu['details']) : '');
            $gradYear = null;
            $gradMonth = 6;
            if (preg_match_all('/\b(19\d\d|20[0-4]\d)\b/', $eduDetailsText, $ym)) {
                $yearsFound = array_map('intval', $ym[1]);
                $minYear = min($yearsFound);
                $maxYear = max($yearsFound);
                if ($maxYear > $currentYear) {
                    $gradYear = $maxYear;
                } else {
                    $isBach = preg_match('/\b(bachelors?|b\.s\.|b\.a\.|b\.sc\.|b\.e\.|b\.tech)\b/i', $course);
                    if ($isBach && $minYear >= $currentYear - 4) {
                        $gradYear = $minYear + 4;
                    } else {
                        $gradYear = $maxYear;
                    }
                }

                if (preg_match('/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b/i', $eduDetailsText, $mm)) {
                    $gradMonth = parseMonth($mm[1]);
                }

                if ($gradYear > $highestGradYear) {
                    $highestGradYear = $gradYear;
                    $highestGradMonth = $gradMonth;
                } elseif ($gradYear === $highestGradYear && $gradMonth > $highestGradMonth) {
                    $highestGradMonth = $gradMonth;
                }
            }

            $cLower = strtolower($course);
            $isFuture = ($gradYear !== null && ($gradYear > $currentYear || ($gradYear === $currentYear && $gradMonth >= $currentMonth)));
            if (preg_match('/\b(bachelors?|b\.s\.|b\.a\.|b\.sc\.|b\.e\.|b\.tech)\b/i', $cLower)) {
                if ($isFuture) {
                    $hasUndergradEnrolled = true;
                }
            }

            $isBach = preg_match('/\b(bachelors?|b\.s\.|b\.a\.|b\.sc\.|b\.e\.|b\.tech)\b/i', $cLower);
            $isMast = preg_match('/\b(masters?|m\.s\.|m\.a\.|m\.sc\.|mba)\b/i', $cLower);
            $isPhd = preg_match('/\b(ph\.?d|doctor|doctorate)\b/i', $cLower);
            
            $score = 0;
            if ($isPhd) $score = 3;
            elseif ($isMast) $score = 2;
            elseif ($isBach) $score = 1;
            
            if ($score > $highestDegreeScore) {
                $highestDegreeScore = $score;
                $selectedDegree = $edu;
            }
        }

        $det = detectJobRoleAndField($resumeText, $sections, $parsedExp, $rolesMatrix);
        $identifiedRole = $det['role'];
        $identifiedField = $det['field'];

        // Apply Intern/Student Override Logic
        $isInternOrStudent = ($highestGradYear > $currentYear || ($highestGradYear === $currentYear && $highestGradMonth >= $currentMonth))
            || $hasUndergradEnrolled
            || (bool)preg_match('/\b(intern|assistant|co-op|trainee|apprentice|student|clerk)\b/i', $identifiedRole)
            || ($identifiedRole === 'Intern');

        if ($isInternOrStudent) {
            $hasExpPriorToGrad = false;
            if ($highestGradYear > 0 && !empty($parsedExp)) {
                $hasExpPriorToGrad = true; // assume true and verify
                foreach ($parsedExp as $job) {
                    if (preg_match_all('/\b(20[0-2]\d)\b/', $job['dates'], $mym)) {
                        foreach ($mym[1] as $y) {
                            if ((int)$y >= $highestGradYear) {
                                $hasExpPriorToGrad = false;
                                break 2;
                            }
                        }
                    }
                }
            } else {
                $hasExpPriorToGrad = true;
            }

            if ($hasExpPriorToGrad) {
                if ($selectedDegree !== null) {
                    $course = $selectedDegree['course'] ?? '';
                    $cLower = strtolower($course);
                    
                    if (preg_match('/\b(computer\s+science|software|programming|developer|ai|artificial\s+intelligence|net\s*centric|algorithmic|graphics|web|cyber|cybersecurity|information\s+technology)\b/i', $cLower)) {
                        $identifiedRole = 'Software Engineer';
                        $identifiedField = 'Technology & Engineering';
                    } elseif (preg_match('/\b(economics?|finance|accounting|business|management|marketing)\b/i', $cLower)) {
                        if (preg_match('/\b(computer|software|coding|cyber|web)\b/i', $cLower)) {
                            $identifiedRole = 'Software Engineer';
                            $identifiedField = 'Technology & Engineering';
                        } else {
                            if (preg_match('/\b(accounting|accountant)\b/i', $cLower)) {
                                $identifiedRole = 'Accountant';
                                $identifiedField = 'Finance & Accounting';
                            } elseif (preg_match('/\b(finance|financial)\b/i', $cLower)) {
                                $identifiedRole = 'Financial Analyst';
                                $identifiedField = 'Finance & Accounting';
                            } elseif (preg_match('/\b(business|management)\b/i', $cLower)) {
                                $identifiedRole = 'Business Analyst';
                                $identifiedField = 'Business Operations, HR & Executive';
                            } else {
                                $identifiedRole = 'Business Analyst';
                                $identifiedField = 'Business Operations, HR & Executive';
                            }
                        }
                    }
                }
            } else {
                if ($identifiedRole === 'Intern' && !empty($parsedExp)) {
                    $experienceText = '';
                    foreach ($parsedExp as $job) {
                        $experienceText .= ' ' . ($job['role'] ?? '') . ' ' . ($job['company'] ?? '');
                    }
                    if (preg_match('/\b(computer|software|programming|developer|web|cyber|it)\b/i', $experienceText)) {
                        $identifiedRole = 'Software Engineer';
                        $identifiedField = 'Technology & Engineering';
                    } else {
                        $identifiedRole = 'Business Analyst';
                        $identifiedField = 'Business Operations, HR & Executive';
                    }
                }
            }
        }

        // Build skill search text to exclude company names and date ranges
        $skillSearchText = getSkillSearchText($resumeText, $sections, $parsedExp);

        // F. Calculate scoring for all 12 fields using appropriate keywords
        $fieldScores = [];
        $fieldDetails = [];

        foreach ($fieldMatrices as $field => $fieldKws) {
            $isTech = ($field === 'Technology & Engineering');
            $activeKws = $fieldKws;
            if ($field === $identifiedField && isset($roleMatrices[$identifiedRole])) {
                $activeKws = $roleMatrices[$identifiedRole];
            }

            // Calculate tenure for this specific field using only relevant jobs
            $fieldKwsList = $fieldMatrices[$field] ?? ['core' => [], 'supporting' => []];
            $roleKwsList = (isset($roleMatrices[$identifiedRole]) && $field === $identifiedField) ? $roleMatrices[$identifiedRole] : ['core' => [], 'supporting' => []];
            $combinedKws = array_unique(array_merge(
                $fieldKwsList['core'],
                $fieldKwsList['supporting'],
                $roleKwsList['core'],
                $roleKwsList['supporting']
            ));
            $tenureYearsField = calculateTenureFromJobs($parsedExp, $combinedKws, $currentYear, $field);

            // Calculate Education & Certifications Score (Pillar 3)
            $eduResult = calculateEducationScore($parsedEdu, $parsedCert, $tenureYearsField, $field, $identifiedRole === $identifiedRole && $field === $identifiedField ? $identifiedRole : $field, $skillSearchText, $activeKws);
            $p3ScoreField = $eduResult['score'];
            $isFreshEdu = $eduResult['is_fresh'];
            $isUndergradIntern = $eduResult['is_undergrad_intern'] ?? false;

            // Adjust weights and caps
            if ($isUndergradIntern) {
                // If undergrad intern rule is active: P3 weight increases to 50 points
                // Tech fields: P1 = 30 points max, P2 = 20 points max, P3 = 50 points max. (Total = 100)
                // Non-Tech fields: P1 = 20 points max, P2 = 30 points max, P3 = 50 points max. (Total = 100)
                $p1MaxField = $isTech ? 30 : 20;
                $p2MaxField = $isTech ? 20 : 30;
                $p3MaxField = 50;
            } elseif ($isFreshEdu) {
                $p1MaxField = $isTech ? 42 : 26;
                $p2MaxField = $isTech ? 28 : 44;
                $p3MaxField = 30;
            } else {
                $p1MaxField = $isTech ? 50 : 30;
                $p2MaxField = $isTech ? 30 : 50;
                $p3MaxField = 20;
            }

            // Pillar 2 tenure score calculation for this field
            $Y = min($tenureYearsField, 20);
            if ($isTech) {
                $p2ScoreField = ($Y >= 20) ? $p2MaxField : (int)round($p2MaxField * (1 - exp(-0.32 * $Y)));
            } else {
                $p2ScoreField = ($Y >= 20) ? $p2MaxField : (int)round($p2MaxField * (1 - exp(-0.16 * $Y)));
            }

            $coreMatched = 0;
            $supportingMatched = 0;
            $p1 = 0;
            $coreWeightField = $isTech ? 3 : 6;
            $suppWeightField = $isTech ? 1 : 3;

            foreach ($activeKws['core'] as $keyword) {
                $pattern = '/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i';
                if (preg_match($pattern, $skillSearchText)) {
                    $coreMatched++;
                    $p1 += $coreWeightField;
                }
            }

            foreach ($activeKws['supporting'] as $keyword) {
                $pattern = '/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i';
                if (preg_match($pattern, $skillSearchText)) {
                    $supportingMatched++;
                    $p1 += $suppWeightField;
                }
            }

            $p1Cap = min($p1, $p1MaxField);
            $totalScore = $p1Cap + $p2ScoreField + $p3ScoreField;

            $fieldScores[$field] = $totalScore;
            $fieldDetails[$field] = [
                'core_count' => $coreMatched,
                'supporting_count' => $supportingMatched,
                'p1_score' => $p1Cap,
                'p2_score' => $p2ScoreField,
                'p3_score' => $p3ScoreField,
                'p1_max' => $p1MaxField,
                'p2_max' => $p2MaxField,
                'p3_max' => $p3MaxField,
                'core_weight' => $coreWeightField,
                'supp_weight' => $suppWeightField,
                'keywords' => $activeKws,
                'edu_result' => $eduResult,
                'tenure_years' => $tenureYearsField
            ];
        }

        // Set top level scores (sorted descending)
        $scores = $fieldScores;
        arsort($scores);

        $identifiedArchetype = $identifiedField;
        $atsScore = $scores[$identifiedField];

        // Winning details for display
        $winningKws = $fieldDetails[$identifiedField]['keywords'];
        $matchedCoreCount = $fieldDetails[$identifiedField]['core_count'];
        $matchedSupportingCount = $fieldDetails[$identifiedField]['supporting_count'];
        $winningPillar1 = $fieldDetails[$identifiedField]['p1_score'];
        $winningPillar2 = $fieldDetails[$identifiedField]['p2_score'];
        $winningPillar3 = $fieldDetails[$identifiedField]['p3_score'];
        $winningEduResult = $fieldDetails[$identifiedField]['edu_result'];
        $p1Max = $fieldDetails[$identifiedField]['p1_max'];
        $p2Max = $fieldDetails[$identifiedField]['p2_max'];
        $p3Max = $fieldDetails[$identifiedField]['p3_max'];
        $coreWeight = $fieldDetails[$identifiedField]['core_weight'];
        $supportingWeight = $fieldDetails[$identifiedField]['supp_weight'];
        $tenureYears = $fieldDetails[$identifiedField]['tenure_years'];

        $archetypeEmoji = ($fieldIcons[$identifiedField] ?? '🤔') . ' ' . $identifiedRole;

        $winningKeywords = array_merge(
            $winningKws['core'],
            $winningKws['supporting']
        );

        // G. Collect matched lines for rubric dropdowns using the filtered skillSearchText
        $textLines = explode("\n", $resumeText);
        $pillar1Details = ['core' => [], 'supporting' => []];

        foreach ($winningKws['core'] as $keyword) {
            $pattern = '/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i';
            $matchingLines = [];
            if (preg_match($pattern, $skillSearchText)) {
                foreach ($textLines as $line) {
                    $trimmedLine = trim($line);
                    if (!empty($trimmedLine) && preg_match($pattern, $trimmedLine)) {
                        $isCompanyLine = false;
                        foreach ($parsedExp as $job) {
                            if (!empty($job['company']) && strpos($trimmedLine, $job['company']) !== false) {
                                $isCompanyLine = true;
                                break;
                            }
                        }
                        if (!$isCompanyLine) {
                            $matchingLines[] = $trimmedLine;
                            if (count($matchingLines) >= 2) break;
                        }
                    }
                }
            }
            $pillar1Details['core'][$keyword] = $matchingLines;
        }

        foreach ($winningKws['supporting'] as $keyword) {
            $pattern = '/(?<![a-zA-Z0-9])' . preg_quote($keyword, '/') . '(?![a-zA-Z0-9])/i';
            $matchingLines = [];
            if (preg_match($pattern, $skillSearchText)) {
                foreach ($textLines as $line) {
                    $trimmedLine = trim($line);
                    if (!empty($trimmedLine) && preg_match($pattern, $trimmedLine)) {
                        $isCompanyLine = false;
                        foreach ($parsedExp as $job) {
                            if (!empty($job['company']) && strpos($trimmedLine, $job['company']) !== false) {
                                $isCompanyLine = true;
                                break;
                            }
                        }
                        if (!$isCompanyLine) {
                            $matchingLines[] = $trimmedLine;
                            if (count($matchingLines) >= 2) break;
                        }
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


$scoreLabel = get_score_label($atsScore);
require_once __DIR__ . '/includes/ui.php';
