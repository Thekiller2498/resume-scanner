?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATS Resume Archetype Scanner</title>
    <meta name="description" content="Upload your PDF resume to get an instant ATS benchmark score, engineering archetype, and impact bullet analysis.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Google+Sans+Display:wght@400;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <!-- PDF.js library for client-side PDF text extraction -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
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
            <input type="hidden" name="extracted_text" id="extracted-text-input">
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
            <div class="bar-head"><span class="bar-head-title">Skill Match (Pillar 1)</span><span><?php echo $winningPillar1; ?> / <?php echo $p1Max; ?></span></div>
            <div class="bar-track"><div class="bar-fill contact" style="width:<?php echo ($winningPillar1 / $p1Max) * 100; ?>%"></div></div>
        </div>
        <div id="rubric-p1" class="rubric-dropdown">
            <div class="rubric-section-title">Core Skills (<?php echo $coreWeight; ?> pts each)</div>
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

            <div class="rubric-section-title">Supporting Skills (<?php echo $supportingWeight; ?> pts each)</div>
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
            <div class="bar-head"><span class="bar-head-title">Tenure &amp; Longevity (Pillar 2)</span><span><?php echo $winningPillar2; ?> / <?php echo $p2Max; ?></span></div>
            <div class="bar-track"><div class="bar-fill keywords" style="width:<?php echo ($winningPillar2 / $p2Max) * 100; ?>%"></div></div>
        </div>
        <div id="rubric-p2" class="rubric-dropdown">
            <div class="rubric-section-title">Longevity Points Milestones</div>
            <ul class="rubric-list">
                <?php 
                $milestones = [1, 3, 6, 10, 15, 20];
                foreach ($milestones as $mYr):
                    // Calculate points at this milestone year using the active curve
                    $isTech = ($identifiedField === 'Technology & Engineering');
                    if ($isTech) {
                        $mPts = ($mYr >= 20) ? 30 : (int)round(30 * (1 - exp(-0.32 * $mYr)));
                    } else {
                        $mPts = ($mYr >= 20) ? 50 : (int)round(50 * (1 - exp(-0.16 * $mYr)));
                    }
                    $isMilestoneReached = ($tenureYears >= $mYr);
                ?>
                <li class="rubric-item <?php echo $isMilestoneReached ? 'matched' : 'unmatched'; ?>">
                    <div class="rubric-item-header">
                        <span class="rubric-badge <?php echo $isMilestoneReached ? 'matched' : 'unmatched'; ?>">
                            <?php echo $isMilestoneReached ? '✓ Reached' : 'Milestone'; ?>
                        </span>
                        <strong><?php echo $mYr; ?> Year<?php echo $mYr > 1 ? 's' : ''; ?> Mark</strong> — <?php echo $mPts; ?> points
                    </div>
                </li>
                <?php endforeach; ?>
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
            <div class="bar-head"><span class="bar-head-title">Education &amp; Certifications (Pillar 3)</span><span><?php echo $winningPillar3; ?> / <?php echo $p3Max; ?></span></div>
            <div class="bar-track"><div class="bar-fill impact" style="width:<?php echo ($winningPillar3 / $p3Max) * 100; ?>%"></div></div>
        </div>
        <div id="rubric-p3" class="rubric-dropdown">
            <div class="rubric-section-title">Education Rubric Details</div>
            <p style="margin-bottom: 10px; color: var(--text-muted); font-size: 0.8rem;">
                Evaluates PhD, Masters, Bachelors, or Certifications/Bootcamps (highest considered).
                <?php if ($winningEduResult['is_fresh']): ?>
                    <br><strong style="color: var(--primary);">✦ Fresh Education bonus active (under 4 years old): Max P3 points raised to 30!</strong>
                <?php endif; ?>
            </p>

            <ul class="rubric-list">
                <li class="rubric-item matched">
                    <div class="rubric-item-header">
                        <span class="rubric-badge matched">
                            ✓ Status
                        </span>
                        <strong>Level Evaluated:</strong> <?php echo htmlspecialchars(ucfirst($winningEduResult['level'])); ?>
                    </div>
                    <?php if (!empty($winningEduResult['degree_info'])): ?>
                        <div class="rubric-quote">Selected Qualification: <?php echo htmlspecialchars($winningEduResult['degree_info']); ?></div>
                    <?php endif; ?>
                </li>

                <?php if ($tenureYears > 5): ?>
                    <li class="rubric-item matched">
                        <div class="rubric-item-header">
                            <span class="rubric-badge matched">
                                ✓ Reached
                            </span>
                            <strong>Experienced Candidate:</strong> +7 points base (tenure > 5 years), all education points halved.
                        </div>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="rubric-section-title" style="margin-top: 14px;">Calculation Audit Logs</div>
            <ul class="rubric-list">
                <?php foreach ($winningEduResult['audit'] as $log): ?>
                    <li class="rubric-item matched" style="font-size: 0.8rem; margin-bottom: 6px;">
                        <div style="display: flex; gap: 8px; align-items: flex-start;">
                            <span style="color: var(--primary);">•</span>
                            <span><?php echo htmlspecialchars($log); ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="divider"></div>

        <!-- Archetype Comparison bars -->
        <div class="section-label">Industry Match Signal Breakdown (Top 4 Fields)</div>
        <?php
            $displayScores = array_slice($scores, 0, 4);
            foreach ($displayScores as $arcType => $arcScore):
                $isWinner = ($arcType === $identifiedArchetype);
        ?>
        <div class="archetype-row">
            <div class="arch-name" style="width: 200px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?php echo htmlspecialchars($arcType); ?>">
                <?php echo $fieldIcons[$arcType] ?? '🤔'; ?> <?php echo htmlspecialchars($arcType); ?>
            </div>
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
            <?php if ($winningPillar2 < 15): ?>• Add date ranges or explicitly declare your years of professional experience.<br><?php endif; ?>
            <?php if ($winningPillar3 < $p3Max): ?>• Ensure you specify your degree type (PhD, Masters, Bachelors) along with a high GPA (3.5+) and a relevant major, or list core certifications.<br><?php endif; ?>
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

    // Configure PDF.js worker
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

    // ── Spatial PDF Extraction ──────────────────────────────────────────────
    // Replaces the flat Y-threshold text approach. Collects all text tokens
    // with their exact (x, y, fontSize) coordinates, groups them into visual
    // rows, detects section headers, and emits a structured JSON blob so the
    // PHP parser can skip fragile regex-based section splitting entirely.
    // ────────────────────────────────────────────────────────────────────────

    const DATE_RANGE_RE = /(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})?[\s\/\-]*(?:19\d\d|20[0-4]\d)\s*(?:–|—|-|to)\s*(?:(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{1,2})?[\s\/\-]*(?:20[0-4]\d)|present|current|now)/i;
    const BULLET_RE     = /^[\u2022\u2023\u2043\u204B\u25CF\u25AA\u25E6\u2B25\u2022\u274F\u27A2\u2714\u2713\u2012\u2013\u2014\uE000-\uF8FF•\-*●▪◦■♦★✦❖>]\s*(.*)/u;

    const SECTION_PATTERNS = [
        { re: /^(?:PROFESSIONAL\s+)?SUMMARY$/i,                           key: 'summary'        },
        { re: /^ABOUT\s+ME$/i,                                             key: 'summary'        },
        { re: /^OBJECTIVE$/i,                                              key: 'summary'        },
        { re: /^(?:WORK\s+|PROFESSIONAL\s+|EMPLOYMENT\s+)?EXPERIENCE$/i,  key: 'experience'     },
        { re: /^WORK\s+HISTORY$/i,                                         key: 'experience'     },
        { re: /^CAREER\s+HISTORY$/i,                                       key: 'experience'     },
        { re: /^LEADERSHIP(?:\s+(?:&|AND)\s+ACTIVITIES)?$/i,              key: 'experience'     },
        { re: /^(?:PROFESSIONAL\s+|ACADEMIC\s+)?EDUCATION$/i,             key: 'education'      },
        { re: /^ACADEMIC\s+BACKGROUND$/i,                                  key: 'education'      },
        { re: /^(?:.*?\s+)?PROJECTS$/i,                                    key: 'projects'       },
        { re: /^(?:.*?\s+)?CERTIFICATIONS?$/i,                             key: 'certifications' },
        { re: /^CREDENTIALS$/i,                                             key: 'certifications' },
        { re: /^LICENSES$/i,                                                key: 'certifications' },
        { re: /^(?:TECHNICAL\s+|KEY\s+)?SKILLS?$/i,                       key: 'skills'         },
        { re: /^(?:.*?\s+)?TECHNOLOGIES$/i,                                key: 'skills'         },
        { re: /^(?:.*?\s+)?(?:INTERESTS|HOBBIES|VOLUNTEERING?|COMMUNITY|ACTIVITIES|CAMPUS\s+INVOLVEMENT)$/i, key: 'culture' },
    ];

    function detectSectionKey(text) {
        const t = text.trim();
        for (const { re, key } of SECTION_PATTERNS) {
            if (re.test(t)) return key;
        }
        return null;
    }

    // Parse a page's getTextContent items into spatial rows
    function pageItemsToRows(items, pageHeight) {
        // Collect tokens with flipped Y (PDF Y goes bottom-up; flip to top-down)
        const tokens = [];
        for (const item of items) {
            const str = item.str;
            if (!str || !str.trim()) continue;
            const x  = item.transform[4];
            const y  = pageHeight - item.transform[5]; // top-down
            const fs = item.height || item.transform[0] || 10;
            tokens.push({ str, x, y, fs });
        }

        if (tokens.length === 0) return [];

        // Sort top-down, then left-right
        tokens.sort((a, b) => a.y - b.y || a.x - b.x);

        // Group tokens into rows (Y within ±4pt)
        const rows = [];
        let curRow = null;
        for (const tok of tokens) {
            if (!curRow || Math.abs(tok.y - curRow.y) > 4) {
                curRow = { y: tok.y, maxFs: tok.fs, tokens: [tok] };
                rows.push(curRow);
            } else {
                if (tok.fs > curRow.maxFs) curRow.maxFs = tok.fs;
                curRow.tokens.push(tok);
            }
        }

        // Sort tokens within each row left-to-right
        for (const row of rows) {
            row.tokens.sort((a, b) => a.x - b.x);
            // Build row text; insert extra space if there's a large X-gap (column separator)
            let text = '';
            let prevX = null, prevW = null;
            for (const tok of row.tokens) {
                if (prevX !== null) {
                    const gap = tok.x - (prevX + (prevW || 0));
                    text += (gap > 60) ? '  ' : ' ';   // double-space = column break hint
                }
                text += tok.str;
                prevX = tok.x;
                prevW = tok.str.length * tok.fs * 0.5; // rough char width estimate
            }
            row.text = text.trim();
        }

        return rows;
    }

    // Parse experience rows into structured job entries
    function parseExpRows(rows) {
        const jobs = [];
        let cur = null;

        for (let ri = 0; ri < rows.length; ri++) {
            const text = rows[ri].text.trim();
            if (!text) continue;

            const dateMatch = text.match(DATE_RANGE_RE);

            if (dateMatch) {
                if (cur) jobs.push(cur);

                const dateStr  = dateMatch[0].trim();
                // Role = everything on this line that isn't the date
                let roleStr = text.replace(dateStr, '').trim().replace(/^[\s|,\-–—]+|[\s|,\-–—]+$/g, '');

                // Company: look back for the first non-bullet non-date non-empty line
                let company = '';
                for (let back = ri - 1; back >= 0; back--) {
                    const prev = rows[back].text.trim();
                    if (!prev) continue;
                    if (DATE_RANGE_RE.test(prev) || BULLET_RE.test(prev)) break;
                    company = prev;
                    break;
                }

                cur = { role: roleStr, company, dates: dateStr, bullets: [] };
            } else if (cur) {
                const bulletMatch = text.match(BULLET_RE);
                if (bulletMatch) {
                    cur.bullets.push(bulletMatch[1].trim());
                } else {
                    // Non-bullet, non-date line inside a job:
                    // Could be a wrapped bullet, an address line, or company of next job.
                    // If the NEXT row has a date range, this is the company of the next entry → skip.
                    let nextHasDate = false;
                    for (let fwd = ri + 1; fwd < rows.length; fwd++) {
                        const nxt = rows[fwd].text.trim();
                        if (!nxt) continue;
                        if (DATE_RANGE_RE.test(nxt)) { nextHasDate = true; }
                        break;
                    }
                    if (nextHasDate) continue; // upcoming company name — skip appending

                    // Otherwise append to last bullet (wrapped line) or ignore
                    if (cur.bullets.length > 0) {
                        cur.bullets[cur.bullets.length - 1] += ' ' + text;
                    }
                }
            }
        }
        if (cur) jobs.push(cur);
        return jobs;
    }

    // Parse project section rows into structured entries.
    // Uses X-position to tell project headers (left-margin) from wrapped bullet
    // continuation lines (indented), which fixes the issue where multi-line
    // bullet text was misidentified as a new project name.
    function parseProjectRows(rows) {
        if (!rows || rows.length === 0) return [];

        // Determine the left margin from the minimum token X in the section
        let leftMargin = Infinity;
        for (const row of rows) {
            if (row.tokens && row.tokens.length > 0) {
                leftMargin = Math.min(leftMargin, row.tokens[0].x);
            }
        }
        if (!isFinite(leftMargin)) leftMargin = 0;

        const projects = [];
        let cur = null;

        for (const row of rows) {
            const text = row.text.trim();
            if (!text) continue;

            const bulletMatch = text.match(BULLET_RE);
            const firstX = (row.tokens && row.tokens.length > 0) ? row.tokens[0].x : leftMargin;
            // A row is at the left margin if its first token starts within 25pt of leftMargin
            const isAtLeftMargin = (firstX - leftMargin) <= 25;

            if (bulletMatch) {
                if (!cur) cur = { name: '', bullets: [] };
                cur.bullets.push(bulletMatch[1].trim());
            } else if (isAtLeftMargin) {
                // Left-aligned non-bullet → new project header
                if (cur) projects.push(cur);
                cur = { name: text, bullets: [] };
            } else {
                // Indented non-bullet → wrapped continuation of last bullet
                if (cur && cur.bullets.length > 0) {
                    cur.bullets[cur.bullets.length - 1] += ' ' + text;
                } else if (cur) {
                    cur.name += ' ' + text; // subtitle / extra info on project title
                }
            }
        }
        if (cur) projects.push(cur);
        return projects;
    }

    function buildResumeText(structured) {
        const parts = [];
        if (structured.header)          parts.push(structured.header);
        if (structured.sections.summary)        parts.push('SUMMARY\n' + structured.sections.summary);
        if (structured.sections.skills)         parts.push('SKILLS\n' + structured.sections.skills);
        if (structured.sections.certifications) parts.push('CERTIFICATIONS\n' + structured.sections.certifications);
        if (structured.sections.experience && structured.sections.experience.length > 0) {
            parts.push('EXPERIENCE');
            for (const j of structured.sections.experience) {
                parts.push([j.company, j.role, j.dates].filter(Boolean).join('\n'));
                for (const b of j.bullets) parts.push('• ' + b);
            }
        }
        if (structured.sections.education_text) parts.push('EDUCATION\n' + structured.sections.education_text);
        if (structured.sections.projects) {
            if (Array.isArray(structured.sections.projects)) {
                parts.push('PROJECTS');
                for (const p of structured.sections.projects) {
                    if (p.name) parts.push(p.name);
                    for (const b of p.bullets) parts.push('\u2022 ' + b);
                }
            } else {
                parts.push('PROJECTS\n' + structured.sections.projects);
            }
        }
        if (structured.sections.culture)         parts.push(structured.sections.culture);
        return parts.join('\n\n');
    }

    async function extractStructuredFromPdf(pdf) {
        // Collect all rows from all pages (Y offset by page)
        let allRows      = [];
        let cumulativeY  = 0;

        for (let p = 1; p <= pdf.numPages; p++) {
            const page       = await pdf.getPage(p);
            const viewport   = page.getViewport({ scale: 1.0 });
            const tc         = await page.getTextContent();
            const pageRows   = pageItemsToRows(tc.items, viewport.height);

            // Offset Y by cumulative page height so pages stack vertically
            for (const row of pageRows) {
                allRows.push({ ...row, y: row.y + cumulativeY });
            }
            cumulativeY += viewport.height + 20; // 20pt gap between pages
        }

        if (allRows.length === 0) return null;

        // Compute median font-size (used to detect headings)
        const fsSorted = allRows.map(r => r.maxFs).sort((a,b) => a - b);
        const medianFs = fsSorted[Math.floor(fsSorted.length / 2)];

        // Assign section keys to header rows
        for (const row of allRows) {
            const t = row.text.trim();
            const secKey = detectSectionKey(t);
            // A row is a heading if it matches a section pattern, OR if it's short + large font
            if (secKey) {
                row.sectionKey = secKey;
            } else if (t.length > 0 && t.length < 50 && row.maxFs >= medianFs * 1.15 && t === t.toUpperCase()) {
                // ALL CAPS large font → treat as unlabelled heading (skip content)
                row.sectionKey = '__heading__';
            }
        }

        // Bucket rows into sections
        const buckets     = {};
        let curSection    = null;
        const headerLines = [];

        for (const row of allRows) {
            if (row.sectionKey) {
                if (row.sectionKey !== '__heading__') {
                    // Merge duplicate section types (e.g. two experience sections)
                    if (curSection === row.sectionKey) {
                        // same section continues — don't reset
                    } else {
                        curSection = row.sectionKey;
                        if (!buckets[curSection]) buckets[curSection] = [];
                    }
                }
                // Don't add the heading row itself to bucket content
                continue;
            }
            if (!curSection) {
                headerLines.push(row.text);
            } else {
                buckets[curSection].push(row);
            }
        }

        const structured = {
            header:   headerLines.join('\n'),
            sections: {}
        };

        // Experience → structured jobs array
        if (buckets.experience) {
            structured.sections.experience = parseExpRows(buckets.experience);
        }

        // Education → raw text (PHP still parses this with existing parseEducation())
        if (buckets.education) {
            structured.sections.education_text = buckets.education.map(r => r.text).join('\n');
        }

        // Skills, summary, projects, certifications, culture → raw text
        // Projects uses spatial parseProjectRows() for correct header vs continuation detection
        if (buckets.projects) {
            structured.sections.projects = parseProjectRows(buckets.projects);
        }
        for (const key of ['skills', 'summary', 'certifications', 'culture']) {
            if (buckets[key]) {
                structured.sections[key] = buckets[key].map(r => r.text).join('\n');
            }
        }

        return structured;
    }

    form.addEventListener('submit', async (e) => {
        // If we already extracted the text and set it, let the form submit normally
        if (document.getElementById('extracted-text-input').value.length > 20) {
            return;
        }

        e.preventDefault();
        btn.textContent = '⏳ Reading PDF locally...';
        btn.disabled = true;

        const file = input.files[0];
        if (!file) {
            alert('Please select a PDF file.');
            btn.textContent = '✦   Scan & Categorize Resume';
            btn.disabled = false;
            return;
        }

        try {
            const reader = new FileReader();
            reader.onload = async function() {
                try {
                    const typedarray = new Uint8Array(this.result);
                    const pdf = await pdfjsLib.getDocument({ data: typedarray }).promise;

                    // Try structured spatial extraction first
                    let structured = null;
                    try {
                        structured = await extractStructuredFromPdf(pdf);
                    } catch (spatialErr) {
                        console.warn('Spatial extraction failed, falling back to flat text:', spatialErr);
                    }

                    if (structured) {
                        // Reconstruct plain text for PHP's text-based analyses (tenure, metrics, etc.)
                        const plainText = buildResumeText(structured);
                        if (plainText.trim().length > 20) {
                            // Embed plain text in the structured JSON so PHP has both
                            structured._plainText = plainText;
                            const jsonPayload = JSON.stringify(structured);
                            document.getElementById('extracted-text-input').value = jsonPayload;
                            btn.textContent = '⏳ Analyzing...';
                            form.submit();
                            return;
                        }
                    }

                    // Fallback: flat-text extraction (original approach)
                    let fullText = '';
                    for (let i = 1; i <= pdf.numPages; i++) {
                        const page = await pdf.getPage(i);
                        const textContent = await page.getTextContent();
                        let pageText = '';
                        let lastY = null;
                        for (const item of textContent.items) {
                            const str = item.str;
                            if (!str.trim()) continue;
                            const y = item.transform[5];
                            if (lastY !== null && Math.abs(y - lastY) > 5) pageText += '\n';
                            else if (lastY !== null) pageText += ' ';
                            pageText += str;
                            lastY = y;
                        }
                        fullText += pageText + '\n\n';
                    }

                    if (fullText.trim().length > 20) {
                        document.getElementById('extracted-text-input').value = fullText;
                        btn.textContent = '⏳ Analyzing...';
                        form.submit();
                    } else {
                        throw new Error('No text content found in PDF.');
                    }
                } catch (err) {
                    console.error('Browser PDF extraction failed, falling back to server:', err);
                    btn.textContent = '⏳ Analyzing (Server Fallback)...';
                    form.submit();
                }
            };
            reader.readAsArrayBuffer(file);
        } catch (err) {
            console.error('File reading failed, falling back to server:', err);
            btn.textContent = '⏳ Analyzing (Server Fallback)...';
            form.submit();
        }
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
