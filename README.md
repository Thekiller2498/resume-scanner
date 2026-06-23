# Resume Scanner & ATS Analyzer

An interactive web application designed to help job seekers parse, analyze, and optimize their resumes against specific job descriptions using AI. 

**🌐 Live Demo:** [resume-scanner-vercel.vercel.app](https://resume-scanner-vercel.vercel.app)

> ⚠️ **Project Status: Work in Progress (Beta)** > This project is currently incomplete and contains functional bugs. It is actively being refactored and developed. Contribution and bug reports are welcome!

---

## 🚀 Features (Planned & In-Development)

- **Resume Parsing:** Upload resumes in standard formats (PDF/DOCX) to extract text and structure.
- **Job Description Matching:** Paste a target job description to evaluate keyword and skill coverage.
- **ATS Compatibility Scoring:** Instantly receive an AI-driven percentage score estimating how well your profile aligns with the role.
- **Actionable Feedback:** Identifies key skill gaps, missing industry terms, and formatting suggestions.

---

## 🛠️ Tech Stack

- **Frontend:** React, Next.js (Deployed via Vercel), Tailwind CSS
- **Interactivity & State:** TypeScript
- **Styling UI Components:** Shadcn/ui (Planned/Partial)

---

## 🐛 Known Issues & Scope for Improvement

As this application is in active development, the following areas are currently experiencing bugs or need completion:
- **File Parsing Stability:** Some PDF structures or text layer variations might fail to extract properly.
- **State Evaluation Errors:** Inconsistent score calculation logic during edge-case text inputs.
- **UI/UX Consistency:** Certain responsive layouts and dark mode features are unpolished.
- **API Rate Limits / Error Handling:** Missing robust feedback UI when AI processing or data extraction endpoints timeout.

---

## 💻 Local Setup & Installation

To run this project locally, clone the repository and install its dependencies:

1. **Clone the repository:**
   ```bash
   git clone [https://github.com/Thekiller2498/resume-scanner.git](https://github.com/Thekiller2498/resume-scanner.git)
   cd resume-scanner
