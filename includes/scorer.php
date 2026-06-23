<?php

/**
 * Detect the candidate's primary job role and industry field.
 */
function detectJobRoleAndField(string $resumeText, array $sections, array $parsedExp, array $matrix): array {
    $scores = [];
    $roleToField = [];
    
    foreach ($matrix as $field => $roles) {
        foreach ($roles as $role) {
            $scores[$role] = 0;
            $roleToField[$role] = $field;
        }
    }
    
    if (empty($scores)) {
        return [
            'role' => 'Software Engineer',
            'field' => 'Technology & Engineering'
        ];
    }
    
    $lines = explode("\n", $resumeText);
    $headerText = '';
    for ($i = 0; $i < min(8, count($lines)); $i++) {
        $headerText .= ' ' . $lines[$i];
    }
    
    $summaryText = $sections['summary'] ?? '';
    
    $expRoles = [];
    foreach ($parsedExp as $job) {
        if (!empty($job['role'])) {
            $expRoles[] = $job['role'];
        }
    }
    
    // Calculate field boosts based on skill keywords
    global $fieldMatrices;
    $fieldBoosts = [];
    if (!empty($fieldMatrices)) {
        foreach ($fieldMatrices as $f => $kws) {
            $cnt = 0;
            $combined = array_unique(array_merge($kws['core'], $kws['supporting']));
            foreach ($combined as $kw) {
                $kwPattern = '/(?<![a-zA-Z0-9])' . preg_quote($kw, '/') . '(?![a-zA-Z0-9])/i';
                if (preg_match_all($kwPattern, $resumeText, $matches)) {
                    $cnt += count($matches[0]);
                }
            }
            $fieldBoosts[$f] = $cnt * 3;
        }
    }

    $flagshipRoles = [
        'Software Engineer' => 0.1,
        'Software Developer' => 0.09,
        'Administrative Assistant' => 0.1,
        'Business Analyst' => 0.1,
        'Customer Service Representative' => 0.1,
        'Teacher' => 0.1,
        'Accountant' => 0.1,
        'Registered Nurse' => 0.1,
        'Designer' => 0.1,
        'Sales Representative' => 0.1
    ];

    foreach ($scores as $role => $score) {
        $escapedRole = preg_quote($role, '/');
        $pattern = '/(?<![a-zA-Z0-9])' . $escapedRole . '(?![a-zA-Z0-9])/i';
        
        foreach ($expRoles as $expRole) {
            if (preg_match($pattern, $expRole)) {
                if ($role === 'Server' && preg_match('/\b(?:database|web|system|network|sql|linux|windows|api|backend|dns|dhcp|devops|cloud|infrastructure)\b/i', $expRole)) {
                    continue;
                }
                $scores[$role] += 15;
            }
        }
        
        if (preg_match($pattern, $headerText)) {
            $scores[$role] += 10;
        }
        
        if (!empty($summaryText) && preg_match($pattern, $summaryText)) {
            $scores[$role] += 8;
        }
        
        if ($role === 'Server') {
            $bodyScore = 0;
            if (preg_match_all('/(?<![a-zA-Z0-9])(?:restaurant|food|dining|bar|waiter|waitress|beverage|cocktail|lead|head|shift)\s+server(?![a-zA-Z0-9])/i', $resumeText, $matches)) {
                $bodyScore = count($matches[0]) * 2;
            }
            $scores[$role] += $bodyScore;
        } else {
            if (preg_match_all($pattern, $resumeText, $matches)) {
                $scores[$role] += count($matches[0]) * 2;
            }
        }

        // Apply field boost
        $fOfRole = $roleToField[$role] ?? '';
        if ($fOfRole && isset($fieldBoosts[$fOfRole])) {
            $scores[$role] += $fieldBoosts[$fOfRole];
        }

        // Apply flagship tie-breaker
        if (isset($flagshipRoles[$role])) {
            $scores[$role] += $flagshipRoles[$role];
        }
    }
    
    arsort($scores);
    $bestRole = key($scores);
    $bestScore = current($scores);
    
    if ($bestScore === 0) {
        $bestRole = 'Software Engineer';
        $bestField = 'Technology & Engineering';
    } else {
        $bestField = $roleToField[$bestRole];
    }
    
    return [
        'role' => $bestRole,
        'field' => $bestField
    ];
}

$fieldIcons = [
    'Administrative' => '💼',
    'Business Operations, HR & Executive' => '👔',
    'Construction, Manufacturing & Trades' => '🛠️',
    'Customer Service & Hospitality' => '🛎️',
    'Education' => '🎓',
    'Finance & Accounting' => '💵',
    'Government, Legal & Public Safety' => '⚖️',
    'Healthcare & Personal Care' => '🏥',
    'Marketing, Media & Design' => '🎨',
    'Retail, Sales & Real Estate' => '🛍️',
    'Technology & Engineering' => '⚙️',
    'Transportation & Logistics' => '🚚'
];

// Matrix variables are loaded from includes/data.php

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

/**
 * Calculate career longevity/tenure from date ranges of relevant jobs.
 */
function calculateTenureFromJobs(array $jobs, array $combinedKws, int $currentYear, string $field = ''): int {
    $yearsActive = [];
    $monthsPattern = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})';
    $rangePattern = '#(?:' . $monthsPattern . '[\s\/-]+)?\b(19\d\d|20[0-4]\d)\s*(?:-|–|—|\/|to)\s*(?:' . $monthsPattern . '[\s\/-]+)?\b(20[0-4]\d|Present|Current|Now)\b#ui';

    foreach ($jobs as $job) {
        $title = $job['role'] ?? '';
        $company = $job['company'] ?? '';
        $bullets = implode(' ', $job['bullets'] ?? []);
        $combinedText = $title . ' ' . $company . ' ' . $bullets;

        // Check if job is relevant
        $isRelevant = false;
        if (empty($combinedKws)) {
            $isRelevant = true;
        } else {
            foreach ($combinedKws as $kw) {
                $pattern = '/(?<![a-zA-Z0-9])' . preg_quote($kw, '/') . '(?![a-zA-Z0-9])/i';
                if (preg_match($pattern, $combinedText)) {
                    $isRelevant = true;
                    break;
                }
            }
            if (!$isRelevant && !empty($field)) {
                $f = strtolower($field);
                $generalKws = [];
                if (strpos($f, 'business') !== false || strpos($f, 'admin') !== false || strpos($f, 'finance') !== false || strpos($f, 'sales') !== false || strpos($f, 'retail') !== false) {
                    $generalKws = ['business', 'admin', 'manage', 'finance', 'account', 'mba', 'economics', 'hr', 'human resource', 'marketing', 'sales', 'commerce', 'intern', 'consultant'];
                } elseif (strpos($f, 'technology') !== false || strpos($f, 'engineering') !== false) {
                    $generalKws = ['computer', 'software', 'technology', 'system', 'it', 'engineering', 'science', 'developer', 'prog', 'data', 'network', 'web', 'cyber', 'information', 'intern', 'consultant'];
                } elseif (strpos($f, 'construction') !== false || strpos($f, 'manufacturing') !== false) {
                    $generalKws = ['construction', 'machin', 'hvac', 'weld', 'automotive', 'architect', 'civil', 'engineer', 'trade', 'safety', 'intern'];
                } elseif (strpos($f, 'health') !== false) {
                    $generalKws = ['nurs', 'medic', 'health', 'clinic', 'dent', 'pharm', 'therapy', 'biolog', 'vet', 'intern'];
                } elseif (strpos($f, 'education') !== false) {
                    $generalKws = ['education', 'teach', 'pedagogy', 'instruction', 'curriculum', 'child', 'intern'];
                } elseif (strpos($f, 'legal') !== false || strpos($f, 'government') !== false) {
                    $generalKws = ['law', 'legal', 'crimin', 'justice', 'police', 'attorney', 'paralegal', 'safety', 'intern'];
                }
                
                foreach ($generalKws as $kw) {
                    $pattern = '/(?<![a-zA-Z0-9])' . preg_quote($kw, '/') . '(?![a-zA-Z0-9])/i';
                    if (preg_match($pattern, $title . ' ' . $company)) {
                        $isRelevant = true;
                        break;
                    }
                }
            }
        }

        if ($isRelevant && !empty($job['dates'])) {
            if (preg_match_all($rangePattern, $job['dates'], $matches, PREG_SET_ORDER)) {
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
        }
    }
    return count($yearsActive);
}

/**
 * Check if the major/course of a degree is relevant to the matched field/role.
 */
function isMajorRelevant(string $course, string $field, string $role): bool {
    $c = strtolower($course);
    $r = strtolower($role);
    $f = strtolower($field);

    // Business Operations, HR, Sales, Administrative, Finance
    if (strpos($f, 'business') !== false || strpos($f, 'admin') !== false || strpos($f, 'finance') !== false || strpos($f, 'sales') !== false || strpos($f, 'retail') !== false) {
        $keywords = ['business', 'admin', 'manage', 'finance', 'account', 'mba', 'economics', 'hr', 'human resource', 'marketing', 'sales', 'commerce'];
    }
    // Technology & Engineering
    elseif (strpos($f, 'technology') !== false || strpos($f, 'engineering') !== false) {
        $keywords = ['computer', 'software', 'technology', 'system', 'it', 'engineering', 'science', 'developer', 'prog', 'data', 'network', 'web', 'cyber', 'information'];
    }
    // General engineering check
    elseif (strpos($r, 'engineer') !== false) {
        $keywords = ['engineering', 'science', 'physics', 'math', 'tech'];
    }
    // Construction & Trades
    elseif (strpos($f, 'construction') !== false || strpos($f, 'manufacturing') !== false) {
        $keywords = ['construction', 'machin', 'hvac', 'weld', 'automotive', 'architect', 'civil', 'engineer', 'trade', 'safety'];
    }
    // Healthcare
    elseif (strpos($f, 'health') !== false) {
        $keywords = ['nurs', 'medic', 'health', 'clinic', 'dent', 'pharm', 'therapy', 'biolog', 'vet'];
    }
    // Education
    elseif (strpos($f, 'education') !== false) {
        $keywords = ['education', 'teach', 'pedagogy', 'instruction', 'curriculum', 'child'];
    }
    // Legal & Public Safety
    elseif (strpos($f, 'legal') !== false || strpos($f, 'government') !== false) {
        $keywords = ['law', 'legal', 'crimin', 'justice', 'police', 'attorney', 'paralegal', 'safety'];
    }
    // Default fallback list
    else {
        $keywords = ['business', 'science', 'art', 'design', 'manage', 'communcation', 'media', 'journalism', 'hospitality', 'culinary', 'logistics', 'supply chain'];
    }

    foreach ($keywords as $kw) {
        if (strpos($c, $kw) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Helper to parse month names to numbers.
 */
function parseMonth(string $text): int {
    $months = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
        'oct' => 10, 'nov' => 11, 'dec' => 12
    ];
    $textLower = strtolower(trim($text));
    foreach ($months as $name => $num) {
        if (strpos($textLower, $name) !== false) {
            return $num;
        }
    }
    return 6; // Default to June if not specified
}

/**
 * Calculate the detailed Education & Certifications Score (Pillar 3).
 * Returns array with score, audit logs/reasons, fresh-grad status, and highest graduation year.
 */
function calculateEducationScore(array $parsedEdu, array $parsedCert, int $tenureYears, string $field, string $role, string $skillSearchText, array $activeKws): array {
    $audit = [];
    $isFreshEdu = false;
    $freshYear = 0;
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    $highestGradYear = 0;
    $highestGradMonth = 6;
    $hasPhDEnrolled = false;
    $hasUndergradEnrolled = false;

    // 1. Identify all degrees by level
    $phds = [];
    $masters = [];
    $bachelors = [];

    // Extract graduation years from education block if present
    // Keep track of the most recent graduation year to determine if the education is "fresh" (< 4 years old)
    $allYears = [];

    foreach ($parsedEdu as $edu) {
        $course = $edu['course'] ?? '';
        $uni = $edu['university'] ?? '';
        $gradeStr = $edu['grade'] ?? '';

        // Extract GPA
        $gpa = null;
        if (preg_match('/\b([0-4]\.\d+)\b/', $gradeStr, $gm)) {
            $gpa = (float)$gm[1];
        } else {
            // Apply honors and fallback GPA rules
            $fullEduText = $course . ' ' . $uni . ' ' . $gradeStr;
            if (preg_match('/\b(magna\s+cum\s+laude|summa\s+cum\s+laude|cum\s+laude|honors?|distinction)\b/i', $fullEduText)) {
                $gpa = 3.5;
            } else {
                $gpa = 2.5;
            }
        }

        // Try to find any year in the university or course line or details (preserved lines)
        $gradYear = null;
        $gradMonth = 6;
        $eduDetailsText = $course . ' ' . $uni . ' ' . (isset($edu['details']) ? implode(' ', $edu['details']) : '');
        if (preg_match_all('/\b(19\d\d|20[0-4]\d)\b/', $eduDetailsText, $ym)) {
            $yearsFound = array_map('intval', $ym[1]);
            $minYear = min($yearsFound);
            $maxYear = max($yearsFound);

            // If the max year is in the future, it's definitely the graduation year
            if ($maxYear > $currentYear) {
                $gradYear = $maxYear;
            } else {
                // If it's a bachelor's and they are currently enrolled (or experience is Present),
                // we can estimate graduation as minYear + 4 (if minYear is close to current year, e.g. >= currentYear - 4)
                $isBach = preg_match('/\b(bachelors?|b\.s\.|b\.a\.|b\.sc\.|b\.e\.|b\.tech)\b/i', $course);
                if ($isBach && $minYear >= $currentYear - 4) {
                    $gradYear = $minYear + 4;
                    $audit[] = "No future graduation year explicitly written; estimated graduation year as $gradYear (Start Year $minYear + 4).";
                } else {
                    $gradYear = $maxYear;
                }
            }

            // Extract month if present near the graduation year
            if (preg_match('/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b/i', $eduDetailsText, $mm)) {
                $gradMonth = parseMonth($mm[1]);
            }

            if ($gradYear <= $currentYear) {
                $allYears[] = $gradYear;
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

        if (preg_match('/\b(ph\.?d|doctor|doctorate)\b/i', $cLower)) {
            if ($isFuture) {
                $hasPhDEnrolled = true;
            } else {
                $phds[] = ['course' => $course, 'gpa' => $gpa, 'raw' => $edu, 'gradYear' => $gradYear, 'gradMonth' => $gradMonth];
            }
        } elseif (preg_match('/\b(masters?|m\.s\.|m\.a\.|m\.sc\.|mba)\b/i', $cLower)) {
            $masters[] = ['course' => $course, 'gpa' => $gpa, 'raw' => $edu, 'gradYear' => $gradYear, 'gradMonth' => $gradMonth];
        } elseif (preg_match('/\b(bachelors?|b\.s\.|b\.a\.|b\.sc\.|b\.e\.|b\.tech)\b/i', $cLower)) {
            if ($isFuture) {
                $hasUndergradEnrolled = true;
            }
            $bachelors[] = ['course' => $course, 'gpa' => $gpa, 'raw' => $edu, 'gradYear' => $gradYear, 'gradMonth' => $gradMonth];
        }
    }

    // Try to find years in certification titles or from certifications block
    foreach ($parsedCert as $cert) {
        if (preg_match('/\b(19\d\d|20[0-4]\d)\b/', $cert, $ym)) {
            $yr = (int)$ym[1];
            if ($yr <= $currentYear) {
                $allYears[] = $yr;
            }
        }
    }

    // Determine highest degree level of study
    $level = 'none';
    $baseScore = 0;
    $selectedDegreeInfo = '';
    $isUndergradIntern = false;

    // Check if employee is Intern level based on role match or ongoing university enrollment
    $isInternOrStudentFuture = ($highestGradYear > $currentYear || ($highestGradYear === $currentYear && $highestGradMonth >= $currentMonth));
    $isInternRole = (bool)preg_match('/\b(intern|assistant|co-op|trainee|apprentice|student|clerk)\b/i', $role)
        || $isInternOrStudentFuture
        || $hasUndergradEnrolled;

    if (!empty($phds)) {
        // Find best PhD
        $bestScoreForPhd = -1;
        $selectedPhd = null;
        foreach ($phds as $phd) {
            $phdGpa = $phd['gpa'];
            $phdIsRelevant = isMajorRelevant($phd['course'], $field, $role);
            $gpaBounded = max(2.1, min(3.5, $phdGpa ?? 2.5));
            $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);
            $phdScore = $phdIsRelevant ? (18 + ($fraction * (20 - 18))) : (15 + ($fraction * (18 - 15)));
            if ($phdScore > $bestScoreForPhd) {
                $bestScoreForPhd = $phdScore;
                $selectedPhd = $phd;
            }
        }
        if ($selectedPhd) {
            $level = 'phd';
            $bestPhd = $selectedPhd;
            $gpa = $bestPhd['gpa'];
            $isRelevant = isMajorRelevant($bestPhd['course'], $field, $role);
            $gpaBounded = max(2.1, min(3.5, $gpa ?? 2.5));
            $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);
            if ($isRelevant) {
                $baseScore = 18 + ($fraction * (20 - 18));
                $audit[] = "Relevant PhD detected (" . $bestPhd['course'] . ") (GPA: " . ($gpa ?? 'N/A') . "). Interpolated score: " . round($baseScore, 2) . " points.";
            } else {
                $baseScore = 15 + ($fraction * (18 - 15));
                $audit[] = "PhD detected in irrelevant field (" . $bestPhd['course'] . ") (GPA: " . ($gpa ?? 'N/A') . "). Interpolated score: " . round($baseScore, 2) . " points.";
            }
            $selectedDegreeInfo = "PhD in " . $bestPhd['course'];
        }
    }

    if ($level === 'none' && !empty($masters)) {
        // Find best Masters
        $bestScoreForMaster = -1;
        $selectedMaster = null;
        foreach ($masters as $m) {
            $mGpa = $m['gpa'];
            if ($mGpa !== null && $mGpa <= 2.0) {
                continue;
            }
            $mIsRelevant = isMajorRelevant($m['course'], $field, $role);
            $gpaBounded = max(2.1, min(3.5, $mGpa ?? 2.5));
            $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);
            $mScore = $mIsRelevant ? (10 + ($fraction * (20 - 10))) : 10;
            if ($mScore > $bestScoreForMaster) {
                $bestScoreForMaster = $mScore;
                $selectedMaster = $m;
            }
        }
        if ($selectedMaster) {
            $level = 'masters';
            $bestMaster = $selectedMaster;
            $gpa = $bestMaster['gpa'];
            $isRelevant = isMajorRelevant($bestMaster['course'], $field, $role);
            $gpaBounded = max(2.1, min(3.5, $gpa ?? 2.5));
            $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);
            if ($isRelevant) {
                $baseScore = 10 + ($fraction * (20 - 10));
                $audit[] = "Relevant Masters detected (" . $bestMaster['course'] . ") (GPA: " . ($gpa ?? 'N/A') . "). Interpolated score: " . round($baseScore, 2) . " points.";
            } else {
                $baseScore = 10;
                $audit[] = "Irrelevant Masters detected (" . $bestMaster['course'] . ") (GPA: " . ($gpa ?? 'N/A') . "). Scored exactly 10 points.";
            }
            $selectedDegreeInfo = "Masters in " . $bestMaster['course'];
        } else {
            $audit[] = "Masters degrees ignored due to low GPA (<= 2.0).";
        }
    }

    // Check bachelors / undergrad intern rules
    if ($level === 'none' && !empty($bachelors)) {
        $bestScoreForBach = -1;
        $selectedBach = null;

        foreach ($bachelors as $bach) {
            $bachGpa = $bach['gpa'];
            if ($bachGpa !== null && $bachGpa <= 2.0) {
                continue;
            }

            $bachIsRelevant = isMajorRelevant($bach['course'], $field, $role);
            $bachScore = 0;
            $bachGradYear = $bach['gradYear'];
            $bachGradMonth = $bach['gradMonth'] ?? 6;
            $bachIsFuture = ($bachGradYear !== null && ($bachGradYear > $currentYear || ($bachGradYear === $currentYear && $bachGradMonth >= $currentMonth)));

            // Undergrad Intern Rule: relevant major, intern-level role
            if ($bachIsRelevant && $isInternRole && $bachIsFuture) {
                $monthsToGrad = ($bachGradYear - $currentYear) * 12 + ($bachGradMonth - $currentMonth);
                $yearsToGrad = $monthsToGrad / 12.0;
                $gpaVal = $bachGpa ?? 2.5;

                // Max possible points for 4.0 GPA:
                // Over 3 years: 15. Over 2 years and under 3: 20. Less than 2 years: 30.
                if ($yearsToGrad > 3.0) {
                    $maxGpaPoints = 15;
                } elseif ($yearsToGrad >= 2.0 && $yearsToGrad <= 3.0) {
                    $maxGpaPoints = 20;
                } else {
                    $maxGpaPoints = 30;
                }

                // Scale points based on GPA (2.1 to 3.5 minimum/maximum threshold check)
                $gpaBounded = max(2.1, min(3.5, $gpaVal));
                $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);
                $bachScore = 8 + ($fraction * ($maxGpaPoints - 8));
            } else {
                $gpaVal = $bachGpa ?? 2.5;
                $gpaBounded = max(2.1, min(3.5, $gpaVal));
                $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);

                if ($bachIsRelevant) {
                    $bachScore = 8 + ($fraction * (15 - 8));
                } else {
                    $bachScore = 8 + ($fraction * (10 - 8));
                }
            }

            if ($bachScore > $bestScoreForBach) {
                $bestScoreForBach = $bachScore;
                $selectedBach = $bach;
            }
        }

        if ($selectedBach !== null) {
            $level = 'bachelors';
            $bestBach = $selectedBach;
            $gpa = $bestBach['gpa'];
            $isRelevant = isMajorRelevant($bestBach['course'], $field, $role);
            $bestBachGradYear = $bestBach['gradYear'];
            $bestBachGradMonth = $bestBach['gradMonth'] ?? 6;
            $bestBachIsFuture = ($bestBachGradYear !== null && ($bestBachGradYear > $currentYear || ($bestBachGradYear === $currentYear && $bestBachGradMonth >= $currentMonth)));

            // Re-run for audit log generation and final baseScore setting
            if ($isRelevant && $isInternRole && $bestBachIsFuture) {
                $isUndergradIntern = true;
                $monthsToGrad = ($bestBachGradYear - $currentYear) * 12 + ($bestBachGradMonth - $currentMonth);
                $yearsToGrad = $monthsToGrad / 12.0;
                $gpaVal = $gpa ?? 2.5;

                if ($yearsToGrad > 3.0) {
                    $maxGpaPoints = 15;
                    $audit[] = "Undergrad Intern Rule: relevant major (" . $bestBach['course'] . "), graduation > 3 years (" . $bestBachGradMonth . "/" . $bestBachGradYear . "). Max GPA points: 15.";
                } elseif ($yearsToGrad >= 2.0 && $yearsToGrad <= 3.0) {
                    $maxGpaPoints = 20;
                    $audit[] = "Undergrad Intern Rule: relevant major (" . $bestBach['course'] . "), graduation 2-3 years (" . $bestBachGradMonth . "/" . $bestBachGradYear . "). Max GPA points: 20.";
                } else {
                    $maxGpaPoints = 30;
                    $audit[] = "Undergrad Intern Rule: relevant major (" . $bestBach['course'] . "), graduation < 2 years (" . $bestBachGradMonth . "/" . $bestBachGradYear . "). Max GPA points: 30.";
                }

                $gpaBounded = max(2.1, min(3.5, $gpaVal));
                $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);
                $baseScore = 8 + ($fraction * ($maxGpaPoints - 8));
                $audit[] = "Undergrad Intern calculated score (GPA: $gpaVal, bounds 2.1-3.5): " . round($baseScore, 2) . " points.";
            } else {
                $gpaBounded = max(2.1, min(3.5, $gpa ?? 2.5));
                $fraction = ($gpaBounded - 2.1) / (3.5 - 2.1);

                if ($isRelevant) {
                    $baseScore = 8 + ($fraction * (15 - 8));
                    $audit[] = "Relevant Bachelors detected (" . $bestBach['course'] . ") (GPA: " . ($gpa ?? 'N/A') . "). Interpolated score: " . round($baseScore, 2) . " points.";
                } else {
                    $baseScore = 8 + ($fraction * (10 - 8));
                    $audit[] = "Irrelevant Bachelors detected (" . $bestBach['course'] . ") (GPA: " . ($gpa ?? 'N/A') . "). Interpolated score: " . round($baseScore, 2) . " points.";
                }
            }
            $selectedDegreeInfo = "Bachelors in " . $bestBach['course'];
        } else {
            $audit[] = "Bachelors degrees ignored due to low GPA (<= 2.0).";
        }
    }

    // Certifications fallback if no degrees considered
    if ($level === 'none') {
        if (!empty($parsedCert)) {
            $level = 'certifications';
            $certPoints = 0;
            $matchedCerts = [];

            foreach ($parsedCert as $cert) {
                $cLower = strtolower($cert);
                $activatesCore = false;
                foreach ($activeKws['core'] as $kw) {
                    if (strpos($cLower, strtolower($kw)) !== false) {
                        $activatesCore = true;
                        break;
                    }
                }

                if ($activatesCore) {
                    $certPoints += 3;
                    $matchedCerts[] = "$cert (+3 pts, core)";
                } else {
                    $certPoints += 1;
                    $matchedCerts[] = "$cert (+1 pt)";
                }
            }

            $baseScore = min($certPoints, 10);
            $selectedDegreeInfo = "Certifications / Bootcamps (" . count($parsedCert) . " found)";
            $audit[] = "No degrees evaluated. Certifications fallback scored $baseScore/10 points. Details: " . implode(', ', $matchedCerts);
        } else {
            $selectedDegreeInfo = "No qualifications detected";
            $audit[] = "No degrees or certifications found.";
        }
    }

    // PHD enrolled check: Add 3 to 5 points for a 2.0 to 3.5 GPA
    if ($hasPhDEnrolled && $level !== 'phd') {
        $phdGpa = 2.5; // default fallback
        foreach ($parsedEdu as $edu) {
            $course = $edu['course'] ?? '';
            $gradeStr = $edu['grade'] ?? '';
            if (preg_match('/\b(ph\.?d|doctor|doctorate)\b/i', $course)) {
                if (preg_match('/\b([0-4]\.\d+)\b/', $gradeStr, $gm)) {
                    $phdGpa = (float)$gm[1];
                }
            }
        }
        $gpaBounded = max(2.0, min(3.5, $phdGpa));
        $fraction = ($gpaBounded - 2.0) / (3.5 - 2.0);
        $phdBonus = 3 + ($fraction * (5 - 3));
        $baseScore += $phdBonus;
        $audit[] = "Candidate actively in a PhD program (undergrad/masters scored as highest degree). Added PhD active bonus: " . round($phdBonus, 2) . " points (GPA: $phdGpa).";
    }

    // 2. Check fresh education age (< 4 years)
    if (!empty($allYears)) {
        $maxYear = max($allYears);
        if ($maxYear >= ($currentYear - 4) && $maxYear <= $currentYear) {
            $isFreshEdu = true;
            $freshYear = $maxYear;
            $audit[] = "Education considered is fresh (Graduation year: $maxYear, under 4 years old). Weight increased to 30 points.";
        }
    }

    // If education weight is increased to 30 points, points in education are multiplied by 1.5 and rounded
    // Skip multiplier if the undergrad intern rule was applied (which has its own 15/20/30 max score targets and weight shifts to 50)
    if ($isFreshEdu && !$isUndergradIntern) {
        $originalBase = $baseScore;
        $baseScore = round($baseScore * 1.5);
        $audit[] = "Fresh graduation detected: points multiplied by 1.5 and rounded ($originalBase -> $baseScore).";
    }

    // 3. Experienced Candidate modifier (> 5 years tenure)
    $educationEarnedPoints = $baseScore;
    $experienceBasePoints = 0;
    if ($tenureYears > 5) {
        $experienceBasePoints = 7;
        $educationEarnedPoints = $baseScore / 2.0;
        $audit[] = "Candidate has > 5 years experience ($tenureYears Years). Base 7 points awarded; education points halved ($baseScore -> $educationEarnedPoints).";
    }

    $finalScore = $experienceBasePoints + $educationEarnedPoints;

    // Cap at the maximum allowed points (default 20, or 30 if fresh, or 50 if undergrad intern)
    $maxCap = 20.0;
    if ($isUndergradIntern) {
        $maxCap = 50.0;
    } elseif ($isFreshEdu) {
        $maxCap = 30.0;
    }

    if ($finalScore > $maxCap) {
        $finalScore = $maxCap;
        $audit[] = "Total score capped at maximum allowed: $maxCap points.";
    }

    return [
        'score' => (int)round($finalScore),
        'base_score' => $baseScore,
        'level' => $level,
        'degree_info' => $selectedDegreeInfo,
        'is_fresh' => $isFreshEdu,
        'fresh_year' => $freshYear,
        'audit' => $audit,
        'earned_edu' => $educationEarnedPoints,
        'base_exp' => $experienceBasePoints,
        'is_undergrad_intern' => $isUndergradIntern
    ];
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
