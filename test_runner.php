<?php
// Mock POST request environment to run the parsing pipeline
$_SERVER['REQUEST_METHOD'] = 'POST';
$_FILES['resume_pdf'] = [
    'name' => 'DevOps_sample_resume.pdf',
    'tmp_name' => __DIR__ . DIRECTORY_SEPARATOR . 'DevOps_sample_resume.pdf',
    'size' => @filesize(__DIR__ . DIRECTORY_SEPARATOR . 'DevOps_sample_resume.pdf') ?: 16058,
    'error' => 0
];

// Include ats_scanner.php and suppress its HTML output
ob_start();
include 'ats_scanner.php';
$html = ob_get_clean();

echo "------------------------------------------------" . PHP_EOL;
echo "RUNNING FUNCTIONAL TEST ON ats_scanner.php" . PHP_EOL;
echo "------------------------------------------------" . PHP_EOL;
echo "Form Submitted          : " . ($formSubmitted ? "YES" : "NO") . PHP_EOL;
echo "Upload Error            : " . ($uploadError ?: "None") . PHP_EOL;
echo "Candidate Name          : " . $name . PHP_EOL;
echo "Email Address           : " . $email . PHP_EOL;
echo "Phone Number            : " . $phone . PHP_EOL;
echo "Identified Archetype    : " . $identifiedArchetype . PHP_EOL;
echo "Archetype Title         : " . $archetypeEmoji . PHP_EOL;
echo "ATS Score (out of 100)  : " . $atsScore . PHP_EOL;
echo "Tenure / Longevity      : " . $tenureYears . " Years (Pillar 2 Score: " . $winningPillar2 . "/30)" . PHP_EOL;
echo "Skills Matched          : " . $matchedCoreCount . " Core, " . $matchedSupportingCount . " Supporting (Pillar 1 Score: " . $winningPillar1 . "/50)" . PHP_EOL;
echo "Impact Metrics Found    : " . count($extractedMetrics) . " (Pillar 3 Score: " . $winningPillar3 . "/20)" . PHP_EOL;
echo "Detailed Scores (Total) : " . PHP_EOL;
foreach ($scores as $type => $score) {
    echo "  - $type: $score" . PHP_EOL;
}
echo "------------------------------------------------" . PHP_EOL;
if ($identifiedArchetype === 'DevOps' && $name === 'MALIK RABB') {
    echo "SUCCESS: Candidate Malik Rabb correctly classified as DevOps!" . PHP_EOL;
} else {
    echo "FAILURE: Candidate name or archetype classification mismatch." . PHP_EOL;
}
echo "------------------------------------------------" . PHP_EOL;
