"""Assemble STQA_Mini_Project_Report.docx from the Markdown deliverables and screenshots.

    python build_report.py

Order: Title, Problem Definition, Prepared By, Prerequisite, Software Requirements, Hardware
Requirements, Theory, Test Plan, Bug Taxonomy, Test Cases, Narration of regression scripts,
Code and output screenshots, GUI screenshots, Bug report, Test report, Conclusion.
"""

import json
import re
import sys
from pathlib import Path

from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).parent
SHOTS = ROOT / "screenshots"
NAVY = RGBColor(0, 0, 0)  # black-and-white document: all text is black
BW_DIR = ROOT / "reports" / "bw_images"
TEAM = [("Soniya Pawar", "[Roll No]"), ("Sakshi Jagadale", "[Roll No]"), ("Priti Merurkar", "[Roll No]")]

doc = Document()


# ------------------------------------------------------------------ styling helpers
def setup_styles():
    st = doc.styles["Normal"]
    st.font.name = "Calibri"
    st.font.size = Pt(11)
    st.element.rPr.rFonts.set(qn("w:eastAsia"), "Calibri")
    for lvl, size in ((1, 16), (2, 13), (3, 12)):
        h = doc.styles[f"Heading {lvl}"]
        h.font.name = "Calibri"
        h.font.size = Pt(size)
        h.font.color.rgb = NAVY
        h.element.rPr.rFonts.set(qn("w:eastAsia"), "Calibri")
    sec = doc.sections[0]
    sec.left_margin = sec.right_margin = Cm(2)
    sec.top_margin = sec.bottom_margin = Cm(2)


def shade(cell, hex_fill):
    """Black-and-white document: no cell fills."""
    return
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_fill)
    tc_pr.append(shd)


INLINE = re.compile(r"(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\))")


def add_inline(par, text, bold=False, size=None):
    """Add text with **bold**, `code` and [link](url) handled."""
    for part in INLINE.split(text):
        if not part:
            continue
        if part.startswith("**") and part.endswith("**"):
            run = par.add_run(part[2:-2])
            run.bold = True
        elif part.startswith("`") and part.endswith("`"):
            run = par.add_run(part[1:-1])
            run.font.name = "Consolas"
            run.font.size = Pt(9.5)
        elif re.fullmatch(r"\[([^\]]+)\]\(([^)]+)\)", part):
            run = par.add_run(re.match(r"\[([^\]]+)\]", part).group(1))
        else:
            run = par.add_run(part)
        if bold:
            run.bold = True
        if size:
            run.font.size = size


def add_table(rows, font_size=Pt(9)):
    header, body = rows[0], rows[1:]
    t = doc.add_table(rows=1, cols=len(header))
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(header):
        cell = t.rows[0].cells[i]
        cell.text = ""
        add_inline(cell.paragraphs[0], h, bold=True, size=font_size)
        shade(cell, "DBEAFE")
    for r in body:
        cells = t.add_row().cells
        for i, v in enumerate(r[: len(header)]):
            cells[i].text = ""
            add_inline(cells[i].paragraphs[0], v, size=font_size)
            if v.strip("*") == "Pass":
                shade(cells[i], "CCFBF1")
            elif v.strip("*") == "Fail":
                shade(cells[i], "FEE2E2")
    doc.add_paragraph()
    return t


def md_to_doc(path: Path, skip_title=True, level_shift=0):
    """Convert a Markdown file (headings, paragraphs, lists, tables, code) into the document."""
    lines = path.read_text(encoding="utf-8").splitlines()
    i = 0
    table: list[list[str]] = []
    code: list[str] | None = None
    while i < len(lines):
        line = lines[i]
        if code is not None:
            if line.startswith("```"):
                p = doc.add_paragraph()
                run = p.add_run("\n".join(code))
                run.font.name = "Consolas"
                run.font.size = Pt(9)
                code = None
            else:
                code.append(line)
            i += 1
            continue
        if line.startswith("```"):
            code = []
        elif line.startswith("|"):
            cells = [c.strip() for c in line.strip().strip("|").split("|")]
            if not all(re.fullmatch(r":?-{3,}:?", c) for c in cells):
                table.append(cells)
            if i + 1 >= len(lines) or not lines[i + 1].startswith("|"):
                add_table(table)
                table = []
        elif line.startswith("#"):
            level = len(line) - len(line.lstrip("#"))
            text = line.lstrip("#").strip()
            if not (skip_title and level == 1):
                doc.add_heading(text, min(level - 1 + level_shift, 3) or 1)
        elif re.match(r"^\s*[*-] ", line):
            p = doc.add_paragraph(style="List Bullet")
            add_inline(p, re.sub(r"^\s*[*-] ", "", line))
        elif re.match(r"^\s*\d+\. ", line):
            p = doc.add_paragraph(style="List Number")
            add_inline(p, re.sub(r"^\s*\d+\. ", "", line))
        elif line.strip() and not line.startswith("---"):
            # join wrapped paragraph lines
            buf = [line.strip()]
            while i + 1 < len(lines) and lines[i + 1].strip() and not re.match(r"^(\s*[*-] |\s*\d+\. |#|\||```)", lines[i + 1]):
                i += 1
                buf.append(lines[i].strip())
            add_inline(doc.add_paragraph(), " ".join(buf))
        i += 1


def heading(text, level=1):
    doc.add_heading(text, level)


def para(text):
    add_inline(doc.add_paragraph(), text)


def bullets(items):
    for it in items:
        add_inline(doc.add_paragraph(style="List Bullet"), it)


def grayscale(path: Path) -> Path:
    """Grayscale copy of a screenshot for the black-and-white document (originals untouched)."""
    from PIL import Image

    BW_DIR.mkdir(parents=True, exist_ok=True)
    out = BW_DIR / path.name
    Image.open(path).convert("L").save(out)
    return out


def image(path: Path, caption: str, width=Cm(16)):
    if not path.exists():
        para(f"[Missing image: {path.name}]")
        return
    doc.add_picture(str(grayscale(path)), width=width)
    doc.paragraphs[-1].alignment = WD_ALIGN_PARAGRAPH.CENTER
    cap = doc.add_paragraph()
    cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = cap.add_run(caption)
    r.italic = True
    r.font.size = Pt(9)


def page_break():
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)


# ------------------------------------------------------------------ document
def build():
    setup_styles()
    results = json.loads((ROOT / "reports" / "results.json").read_text(encoding="utf-8"))
    total = len(results)
    passed = sum(r["status"] == "Pass" for r in results.values())

    # Title page
    for _ in range(4):
        doc.add_paragraph()
    t = doc.add_paragraph()
    t.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = t.add_run("STQA Mini Project")
    r.bold, r.font.size, r.font.color.rgb = True, Pt(28), NAVY
    s = doc.add_paragraph()
    s.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = s.add_run("Testing of “EduFlow” — Academic Management & Productivity Portal\nusing Selenium WebDriver, Selenium IDE and pytest")
    r.font.size = Pt(14)
    doc.add_paragraph()
    m = doc.add_paragraph()
    m.alignment = WD_ALIGN_PARAGRAPH.CENTER
    m.add_run("Software Testing and Quality Assurance\nBE Computer Engineering").font.size = Pt(12)
    page_break()

    heading("1. Title")
    para("**Testing of EduFlow, an academic management and student productivity web portal, using Selenium WebDriver (Python, pytest) and Selenium IDE.**")

    heading("2. Problem Definition")
    para("EduFlow is a web application used by an institution's administrators, teachers and students. Admins manage users and classes, teachers publish assignments and grade submissions, and students submit work, manage personal tasks and track their progress. Because it handles accounts, grades and uploaded files, defects in login, access control, validation or the assignment workflow directly affect users.")
    para("The problem is to plan and carry out systematic testing of EduFlow: write a test plan, define a bug taxonomy, design test cases covering valid and invalid inputs, boundary values, navigation and session handling, automate them with Selenium WebDriver, record Selenium IDE flows, perform exploratory testing, and report the defects found and the overall quality against defined exit criteria.")

    heading("3. Prepared By")
    add_table([["Sr. No.", "Name", "Roll No.", "Class"]] + [[str(i + 1), n, rn, "BE COMP"] for i, (n, rn) in enumerate(TEAM)], Pt(10))

    heading("4. Prerequisite")
    bullets([
        "**Core Java:** object-oriented concepts (classes, objects, inheritance, exceptions) and collections. Selenium's API (WebDriver, WebElement, By, WebDriverWait) is the same object model in Java and Python, so these concepts carry over directly.",
        "**Python 3:** functions, modules, exceptions, and the pytest testing framework (fixtures, assertions). The automation scripts in this project are written in Python.",
        "Basic HTML/CSS (element ids, classes, forms) and CSS/XPath locators.",
        "Software testing fundamentals: test plan, test case design, black-box techniques (equivalence partitioning, boundary value analysis), defect life cycle.",
    ])

    heading("5. Software Requirements")
    add_table([
        ["Software", "Version / details"],
        ["Operating system", "Windows 11 (64-bit)"],
        ["Application under test", "EduFlow (PHP 8.4.5, MySQL 8.0, Bootstrap 5.3, Chart.js 4.4)"],
        ["Web server", "PHP built-in development server (or Apache via XAMPP)"],
        ["Browser", "Google Chrome 154"],
        ["Language", "Python 3.13"],
        ["Automation", "Selenium WebDriver 4.27.1 (Selenium Manager for ChromeDriver)"],
        ["Test framework / reporting", "pytest 8.3.4, pytest-html 4.1.1"],
        ["Record & playback", "Selenium IDE browser extension"],
        ["Documentation", "Markdown, Microsoft Word (python-docx 1.1.2 to assemble)"],
        ["Version control", "Git, GitHub"],
    ], Pt(10))

    heading("6. Hardware Requirements")
    add_table([
        ["Component", "Minimum"],
        ["Processor", "Intel Core i3 / AMD Ryzen 3 or better, 64-bit"],
        ["RAM", "8 GB (Chrome, MySQL and PHP run together)"],
        ["Storage", "2 GB free disk space"],
        ["Display", "1366 × 768 or higher"],
        ["Network", "Internet access for first-time ChromeDriver download and CDN assets"],
    ], Pt(10))

    heading("7. Theory")
    heading("7.1 Introduction to the mini project", 2)
    para("This mini project applies the software testing life cycle to a complete, working web application. EduFlow has three roles (Admin, Teacher, Student) and features such as authentication, user and class management, assignment publishing and grading, file submission, personal task management, notifications, a calendar and analytics charts. We tested it as a black box through its web interface: planning, designing test cases, automating them, exploring the application manually, and reporting defects.")
    para("**Software testing** is the process of executing a program to find defects and to check that it meets its requirements. **Quality assurance** is the wider set of activities that make sure a process produces quality software. Testing types used here are integration testing (UI, server and database working together), system testing (complete user journeys), regression testing (re-running the automated suite after changes) and exploratory testing (simultaneous learning, test design and execution).")
    heading("7.2 Selenium", 2)
    para("Selenium is an open-source suite of tools for automating web browsers. It supports several languages (Java, Python, C#, JavaScript, Ruby) and all major browsers. It consists of four components:")
    bullets([
        "**Selenium IDE (Integrated Development Environment):** a browser extension for Chrome and Firefox that records user actions (clicks, typing) as commands and plays them back. Recordings are saved as `.side` project files. It needs no programming and is useful for quick smoke tests and for learning locators. In this project it records the login, quick-add task and logout flows.",
        "**Selenium RC (Remote Control):** the original Selenium 1 tool. A server injected JavaScript into the browser to drive it, and test programs talked to that server. It was slow and limited by browser security, and has been deprecated and replaced by WebDriver since Selenium 3.",
        "**Selenium WebDriver:** a programming interface that controls the browser directly through the browser's native automation support (ChromeDriver, GeckoDriver, etc.) using the W3C WebDriver protocol. Tests locate elements with `By` locators (ID, NAME, CSS_SELECTOR, XPATH, LINK_TEXT…), perform actions, and use explicit waits (`WebDriverWait` with `expected_conditions`) to synchronise with dynamic pages. This project's 36 automated tests use Selenium 4 WebDriver with Python.",
        "**Selenium Grid:** runs WebDriver tests in parallel on many machines, browsers and operating systems through a hub that distributes sessions to nodes. It reduces execution time and enables cross-browser testing. It was not needed for this single-machine project.",
    ])
    heading("7.3 pytest and pytest-html", 2)
    para("pytest discovers functions named `test_*`, runs them and reports assertions that fail. Fixtures provide set-up and tear-down (here: starting the app, resetting the database, opening and closing Chrome). The pytest-html plug-in produces a self-contained HTML report with each test's result, duration and failure screenshot.")

    page_break()
    heading("8. Test Plan")
    md_to_doc(ROOT / "test_plan.md", level_shift=1)

    page_break()
    heading("9. Bug Taxonomy")
    md_to_doc(ROOT / "bug_taxonomy.md", level_shift=1)

    page_break()
    heading("10. Test Cases")
    para(f"36 test cases were designed and automated. Results below are from the final run ({passed} passed, {total - passed} failed).")
    rows = []
    for line in (ROOT / "test_cases.md").read_text(encoding="utf-8").splitlines():
        if line.startswith("|") and not re.fullmatch(r"\|[\s:|-]+\|", line):
            rows.append([c.strip() for c in line.strip().strip("|").split("|")])
    sec = doc.add_section()
    sec.orientation = WD_ORIENT.LANDSCAPE
    sec.page_width, sec.page_height = sec.page_height, sec.page_width
    add_table(rows, Pt(8))
    sec = doc.add_section()
    sec.orientation = WD_ORIENT.PORTRAIT
    sec.page_width, sec.page_height = sec.page_height, sec.page_width

    heading("11. Narration of Regression Scripts")
    md_to_doc(ROOT / "docs" / "narration.md", level_shift=1)

    page_break()
    heading("12. Code and Output Screenshots")
    for name, caption in [
        ("CODE_helpers.png", "Figure 1: helpers.py — reusable App class with explicit waits, login/logout and screenshot helpers"),
        ("CODE_conftest.png", "Figure 2: conftest.py — fixtures for server, database reset, Chrome driver and failure screenshots"),
        ("CODE_test_auth.png", "Figure 3: test_01_auth.py — login, lockout, logout and access-control tests"),
        ("CODE_test_teacher.png", "Figure 4: test_03_teacher.py — assignment publishing and grading tests"),
        ("OUTPUT_console.png", "Figure 5: Console output of the full regression run with the pass/fail summary"),
        ("OUTPUT_html_report.png", "Figure 6: pytest-html report generated in reports/test_report.html"),
        ("OUTPUT_side_replay.png", "Figure 7: Selenium IDE flows replayed with WebDriver"),
    ]:
        image(SHOTS / name, caption)

    page_break()
    heading("13. GUI Screenshots (captured by Selenium)")
    for name, caption in [
        ("TC03_student_dashboard.png", "Figure 8: Student dashboard after a successful login (TC03)"),
        ("TC22_assignment_published.png", "Figure 9: Teacher's assignment page after Save & publish (TC22)"),
        ("TC35_progress.png", "Figure 10: Student progress analytics with Chart.js charts (TC35)"),
        ("TC23_required_fields.png", "Figure 11: Required-field validation on a new assignment — evidence for BUG-01 (TC23)"),
    ]:
        image(SHOTS / name, caption)

    page_break()
    heading("14. Bug Report")
    md_to_doc(ROOT / "bug_report.md", level_shift=1)

    page_break()
    heading("15. Test Report")
    md_to_doc(ROOT / "reports" / "final_report.md", level_shift=1)
    heading("15.1 Exploratory testing", 2)
    md_to_doc(ROOT / "exploratory_testing.md", level_shift=2)

    heading("16. Conclusion")
    md_to_doc(ROOT / "docs" / "conclusion.md")

    out = ROOT / (sys.argv[1] if len(sys.argv) > 1 else "STQA_Mini_Project_Report.docx")
    doc.save(out)
    print(f"Wrote {out}")


if __name__ == "__main__":
    build()
