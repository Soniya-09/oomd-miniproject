"""Draw the EduFlow UML diagrams as black-and-white SVGs with straight (orthogonal) lines,
in the style of the OOMD report template, and render them to PNG for the report.

    python uml_diagrams.py

Writes uml/<name>.svg and screenshots/UML_<name>.png (2.5x scale, headless Chrome).
Every coordinate is laid out by hand so that connectors are straight horizontal/vertical
segments and do not cross boxes.
"""

import html
from pathlib import Path

ROOT = Path(__file__).parent
SVG_DIR = ROOT / "uml"
PNG_DIR = ROOT / "screenshots"
FONT = "Arial, Helvetica, sans-serif"
TITLE_FONT = "'Times New Roman', Times, serif"
LH = 18  # line height inside class boxes


# ------------------------------------------------------------------ SVG primitives
class Svg:
    def __init__(self, w, h, title):
        self.w, self.h, self.items = w, h, []
        self.items.append(f'<rect x="4" y="4" width="{w - 8}" height="{h - 8}" fill="#fff" stroke="#000" stroke-width="1.5"/>')
        self.text(w / 2, 42, title, 26, bold=True, font=TITLE_FONT)

    def add(self, s):
        self.items.append(s)

    def text(self, x, y, s, size=15, anchor="middle", bold=False, italic=False, font=FONT, halo=False):
        lines = str(s).split("\n")
        y0 = y - (len(lines) - 1) * size * 0.6
        attrs = f'font-family="{font}" font-size="{size}" text-anchor="{anchor}"'
        if bold:
            attrs += ' font-weight="bold"'
        if italic:
            attrs += ' font-style="italic"'
        if halo:
            attrs += ' paint-order="stroke" stroke="#fff" stroke-width="5" stroke-linejoin="round"'
        for i, line in enumerate(lines):
            self.add(f'<text x="{x}" y="{y0 + i * size * 1.2 + size * 0.35:.1f}" {attrs}>{html.escape(line)}</text>')

    def rect(self, x, y, w, h, rx=0, dash=False, fill="#fff", sw=1.5):
        d = ' stroke-dasharray="7,5"' if dash else ""
        self.add(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" stroke="#000" stroke-width="{sw}"{d}/>')

    def line(self, pts, end=None, start=None, dash=False, sw=1.5):
        p = " ".join(f"{x},{y}" for x, y in pts)
        m = ""
        if end:
            m += f' marker-end="url(#{end})"'
        if start:
            m += f' marker-start="url(#{start})"'
        d = ' stroke-dasharray="8,6"' if dash else ""
        self.add(f'<polyline points="{p}" fill="none" stroke="#000" stroke-width="{sw}"{d}{m}/>')

    def save(self, name):
        defs = """<defs>
<marker id="open" viewBox="0 0 14 14" refX="13" refY="7" markerWidth="14" markerHeight="14" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M1,1 L13,7 L1,13" fill="none" stroke="#000" stroke-width="1.6"/></marker>
<marker id="filled" viewBox="0 0 14 14" refX="13" refY="7" markerWidth="14" markerHeight="14" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M0,1 L13,7 L0,13 z" fill="#000"/></marker>
<marker id="tri" viewBox="0 0 22 22" refX="21" refY="11" markerWidth="22" markerHeight="22" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M1,1 L21,11 L1,21 z" fill="#fff" stroke="#000" stroke-width="1.5"/></marker>
<marker id="diamond" viewBox="0 0 26 14" refX="25" refY="7" markerWidth="26" markerHeight="14" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M1,7 L13,1 L25,7 L13,13 z" fill="#000" stroke="#000" stroke-width="1.2"/></marker>
<marker id="odiamond" viewBox="0 0 26 14" refX="25" refY="7" markerWidth="26" markerHeight="14" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M1,7 L13,1 L25,7 L13,13 z" fill="#fff" stroke="#000" stroke-width="1.4"/></marker>
</defs>"""
        body = "\n".join(self.items)
        svg = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.w}px" height="{self.h}px" '
               f'viewBox="0 0 {self.w} {self.h}">{defs}\n{body}\n</svg>')
        SVG_DIR.mkdir(exist_ok=True)
        (SVG_DIR / f"{name}.svg").write_text(svg, encoding="utf-8")
        return SVG_DIR / f"{name}.svg"


class Box:
    def __init__(self, x, y, w, h):
        self.x, self.y, self.w, self.h = x, y, w, h

    @property
    def cx(self):
        return self.x + self.w / 2

    @property
    def cy(self):
        return self.y + self.h / 2

    @property
    def r(self):
        return self.x + self.w

    @property
    def b(self):
        return self.y + self.h


# ------------------------------------------------------------------ shapes
def uml_class(s, x, y, name, attrs, ops, w=240, abstract=False):
    head = 46 if abstract else 30
    ah = 8 + LH * len(attrs) + 6 if attrs else 14
    oh = 8 + LH * len(ops) + 6 if ops else 14
    h = head + ah + oh
    s.rect(x, y, w, h)
    if abstract:
        s.text(x + w / 2, y + 17, name, 15, bold=True, italic=True)
        s.text(x + w / 2, y + 35, "{abstract}", 12, italic=True)
    else:
        s.text(x + w / 2, y + 16, name, 15, bold=True)
    s.line([(x, y + head), (x + w, y + head)], sw=1.2)
    s.line([(x, y + head + ah), (x + w, y + head + ah)], sw=1.2)
    for i, a in enumerate(attrs):
        s.text(x + 10, y + head + 8 + LH * i + 9, a, 13, anchor="start")
    for i, o in enumerate(ops):
        s.text(x + 10, y + head + ah + 8 + LH * i + 9, o, 13, anchor="start")
    return Box(x, y, w, h)


def action(s, cx, cy, text, w=240, h=50, rx=14, size=14):
    s.rect(cx - w / 2, cy - h / 2, w, h, rx=rx)
    s.text(cx, cy, text, size)
    return Box(cx - w / 2, cy - h / 2, w, h)


def diamond(s, cx, cy, text="", w=60, h=60, size=13):
    s.add(f'<polygon points="{cx},{cy - h / 2} {cx + w / 2},{cy} {cx},{cy + h / 2} {cx - w / 2},{cy}" '
          f'fill="#fff" stroke="#000" stroke-width="1.5"/>')
    if text:
        s.text(cx, cy, text, size)
    return Box(cx - w / 2, cy - h / 2, w, h)


def start_node(s, cx, cy, r=13):
    s.add(f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="#000"/>')


def end_node(s, cx, cy, r=14):
    s.add(f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="#fff" stroke="#000" stroke-width="2"/>')
    s.add(f'<circle cx="{cx}" cy="{cy}" r="{r - 5}" fill="#000"/>')


def actor(s, cx, top, name, stereo=None):
    s.add(f'<circle cx="{cx}" cy="{top + 11}" r="11" fill="#fff" stroke="#000" stroke-width="1.8"/>')
    s.line([(cx, top + 22), (cx, top + 52)], sw=1.8)
    s.line([(cx - 20, top + 32), (cx + 20, top + 32)], sw=1.8)
    s.line([(cx - 16, top + 76), (cx, top + 52), (cx + 16, top + 76)], sw=1.8)
    y = top + 94
    if stereo:
        s.text(cx, y, stereo, 12, italic=True)
        y += 17
    s.text(cx, y, name, 15, bold=True)
    return Box(cx - 20, top, 40, 80)


def usecase(s, cx, cy, text, rx=125, ry=25):
    s.add(f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.text(cx, cy, text, 14)
    return Box(cx - rx, cy - ry, 2 * rx, 2 * ry)


def node3d(s, x, y, w, h, title, stereo="«device»", depth=14, size=15):
    s.add(f'<polygon points="{x},{y} {x + depth},{y - depth} {x + w + depth},{y - depth} {x + w},{y}" '
          f'fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.add(f'<polygon points="{x + w},{y} {x + w + depth},{y - depth} {x + w + depth},{y + h - depth} {x + w},{y + h}" '
          f'fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.rect(x, y, w, h)
    s.text(x + w / 2, y + 18, stereo, 12, italic=True)
    s.text(x + w / 2, y + 38, title, size, bold=True)
    return Box(x, y, w, h)


def component_icon(s, x, y):
    s.rect(x, y, 16, 20, sw=1.2)
    s.rect(x - 5, y + 4, 10, 4, sw=1.2)
    s.rect(x - 5, y + 12, 10, 4, sw=1.2)


def component(s, cx, cy, text, w=220, h=58, stereo="«component»", size=14):
    s.rect(cx - w / 2, cy - h / 2, w, h)
    component_icon(s, cx + w / 2 - 26, cy - h / 2 + 7)
    if stereo:
        s.text(cx - 6, cy - h / 2 + 15, stereo, 11, italic=True)
        s.text(cx - 6, cy + 9, text, size, bold=True)
    else:
        s.text(cx - 6, cy, text, size, bold=True)
    return Box(cx - w / 2, cy - h / 2, w, h)


def artifact(s, cx, cy, text, w=170, h=48, stereo="«artifact»"):
    s.rect(cx - w / 2, cy - h / 2, w, h)
    x, y = cx + w / 2 - 22, cy - h / 2 + 6
    s.add(f'<path d="M{x},{y} h10 l5,5 v14 h-15 z M{x + 10},{y} v5 h5" fill="#fff" stroke="#000" stroke-width="1.1"/>')
    s.text(cx - 6, cy - 9, stereo, 11, italic=True)
    s.text(cx - 6, cy + 10, text, 13)
    return Box(cx - w / 2, cy - h / 2, w, h)


def cylinder(s, cx, top, w, h, text, size=13):
    ry = 9
    x0, x1, bottom = cx - w / 2, cx + w / 2, top + h
    s.add(f'<path d="M{x0},{top + ry} A{w / 2},{ry} 0 0 1 {x1},{top + ry} V{bottom - ry} '
          f'A{w / 2},{ry} 0 0 1 {x0},{bottom - ry} Z" fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.add(f'<path d="M{x0},{top + ry} A{w / 2},{ry} 0 0 0 {x1},{top + ry}" fill="none" stroke="#000" stroke-width="1.5"/>')
    s.text(cx, top + h / 2 + 6, text, size)
    return Box(x0, top, w, h)


def package(s, x, y, w, h, name, dash=False):
    tw = max(130, len(name) * 9 + 24)
    s.rect(x, y, tw, 26, dash=dash)
    s.rect(x, y + 26, w, h - 26, dash=dash)
    s.text(x + tw / 2, y + 13, name, 14, bold=True)
    return Box(x, y + 26, w, h - 26)


def state(s, cx, cy, name, note=None, w=210):
    h = 66 if note else 46
    s.rect(cx - w / 2, cy - h / 2, w, h, rx=12)
    if note:
        s.text(cx, cy - 16, name, 15, bold=True)
        s.line([(cx - w / 2, cy - 2), (cx + w / 2, cy - 2)], sw=1)
        s.text(cx, cy + 16, note, 12, italic=True)
    else:
        s.text(cx, cy, name, 15, bold=True)
    return Box(cx - w / 2, cy - h / 2, w, h)


def label(s, x, y, t, size=13, anchor="middle", italic=False):
    s.text(x, y, t, size, anchor=anchor, italic=italic, halo=True)


# ------------------------------------------------------------------ 1 class diagram
def class_diagram():
    s = Svg(1530, 1090, "Class Diagram – EduFlow")
    C = [30, 340, 660, 960, 1260]
    notif = uml_class(s, C[0], 90, "Notification", ["- title : string", "- message : string", "- readAt : DateTime"], ["+ markRead() : void"])
    user = uml_class(s, C[0], 290, "User", ["- id : int", "- fullName : string", "- email : string", "- passwordHash : string",
                                             "- role : Role", "- isActive : bool"],
                     ["+ login(email, password) : bool", "+ logout() : void", "+ changePassword(old, new)"], abstract=True)
    ann = uml_class(s, C[0], 600, "Announcement", ["- title : string", "- body : string", "- publishedAt : DateTime"], [])
    admin = uml_class(s, C[1], 90, "Admin", [], ["+ createUser(data) : User", "+ deactivateUser(u : User)", "+ enroll(s : Student, c : Class)"])
    teacher = uml_class(s, C[1], 290, "Teacher", ["- employeeNo : string", "- designation : string"],
                        ["+ createAssignment(data)", "+ grade(s, marks, feedback)", "+ returnForRevision(s, feedback)"])
    student = uml_class(s, C[1], 520, "Student", ["- admissionNo : string", "- rollNo : string"],
                        ["+ submit(a, files) : Submission", "+ addTask(title, dueDate)"])
    subject = uml_class(s, C[2], 316, "Subject", ["- code : string", "- name : string"], [])
    klass = uml_class(s, C[2], 529, "Class", ["- name : string", "- division : string"], ["+ students() : Student[]"])
    assign = uml_class(s, C[3], 300, "Assignment", ["- title : string", "- description : string", "- dueAt : DateTime",
                                                    "- maxMarks : decimal", "- allowLate : bool", "- status : string"],
                       ["+ publish() : void", "+ close() : void", "+ isOverdue() : bool"])
    sub = uml_class(s, C[3], 640, "Submission", ["- attempt : int", "- submittedAt : DateTime", "- isLate : bool",
                                                 "- marks : decimal", "- status : SubmissionStatus"], ["+ resubmit(files) : void"])
    sfile = uml_class(s, C[4], 630, "SubmissionFile", ["- fileName : string", "- sizeBytes : int"], [])
    fb = uml_class(s, C[4], 760, "Feedback", ["- comment : string", "- createdAt : DateTime"], [])
    task = uml_class(s, C[1], 860, "PersonalTask", ["- title : string", "- priority : string", "- dueAt : DateTime",
                                                    "- status : string", "- completedAt : DateTime"],
                     ["+ complete() : void", "+ reopen() : void"])
    cat = uml_class(s, C[0], 900, "TaskCategory", ["- name : string", "- color : string"], [])

    # User receives Notification / posts Announcement
    s.line([(150, user.y), (150, notif.b)], start="diamond")
    label(s, 158, user.y - 14, "1", anchor="start"); label(s, 158, notif.b + 14, "*", anchor="start")
    label(s, 100, (user.y + notif.b) / 2, "receives")
    s.line([(150, user.b), (150, ann.y)])
    label(s, 158, user.b + 14, "1", anchor="start"); label(s, 158, ann.y - 12, "*", anchor="start")
    label(s, 112, (user.b + ann.y) / 2, "posts")
    # generalisation Admin / Teacher / Student -> User
    bx = 305
    s.line([(bx, user.cy), (user.r, user.cy)], end="tri")
    s.line([(bx, admin.cy), (bx, student.cy)])
    for c in (admin, teacher, student):
        s.line([(bx, c.cy), (c.x, c.cy)])
    # Teacher teaches Subject, Student enrolled in Class, Class taught Subject
    s.line([(teacher.r, 362), (subject.x, 362)])
    label(s, 620, 350, "teaches"); label(s, 592, 378, "*"); label(s, 650, 378, "*")
    s.line([(student.r, 583), (klass.x, 583)])
    label(s, 620, 571, "enrolled in", 12); label(s, 592, 599, "*"); label(s, 650, 599, "*")
    s.line([(780, subject.b), (780, klass.y)])
    label(s, 813, (subject.b + klass.y) / 2, "taught"); label(s, 770, subject.b + 14, "*", anchor="end"); label(s, 770, klass.y - 10, "*", anchor="end")
    # Assignment -> Subject, Assignment -> Class, Teacher creates Assignment
    s.line([(assign.x, 362), (subject.r, 362)], end="open")
    label(s, 950, 350, "*", anchor="end"); label(s, 910, 350, "1", anchor="start")
    s.line([(1000, assign.b), (1000, 583), (klass.r, 583)], end="open")
    label(s, 1010, assign.b + 14, "*", anchor="start"); label(s, 910, 570, "1", anchor="start")
    s.line([(520, teacher.y), (520, 250), (1120, 250), (1120, assign.y)], end="open")
    label(s, 820, 238, "creates"); label(s, 530, teacher.y - 12, "1", anchor="start"); label(s, 1130, assign.y - 12, "*", anchor="start")
    # Assignment has Submissions; Submission has files and feedback
    s.line([(1120, assign.b), (1120, sub.y)], start="diamond")
    label(s, 1130, assign.b + 16, "1", anchor="start"); label(s, 1130, sub.y - 10, "*", anchor="start")
    s.line([(sub.r, sfile.cy), (sfile.x, sfile.cy)], start="diamond")
    label(s, sub.r + 14, sfile.cy - 12, "1", anchor="start"); label(s, sfile.x - 6, sfile.cy - 12, "1..*", anchor="end")
    s.line([(sub.r, 780), (fb.x, 780)], start="diamond")
    label(s, sub.r + 14, 768, "1", anchor="start"); label(s, fb.x - 6, 768, "*", anchor="end")
    # Student makes Submission
    s.line([(540, student.b), (540, 720), (sub.x, 720)])
    label(s, 780, 708, "makes"); label(s, 550, student.b + 14, "1", anchor="start"); label(s, sub.x - 6, 708, "*", anchor="end")
    # Student owns PersonalTasks and TaskCategories; TaskCategory groups PersonalTasks
    s.line([(460, student.b), (460, task.y)], start="diamond")
    label(s, 470, student.b + 16, "1", anchor="start"); label(s, 470, task.y - 10, "*", anchor="start")
    s.line([(380, student.b), (380, 820), (150, 820), (150, cat.y)], start="diamond")
    label(s, 160, cat.y - 10, "*", anchor="start"); label(s, 265, 808, "owns")
    s.line([(cat.r, cat.cy), (task.x, cat.cy)], start="odiamond")
    label(s, cat.r + 8, cat.cy + 16, "0..1", anchor="start"); label(s, task.x - 6, cat.cy - 12, "*", anchor="end")
    return s


# ------------------------------------------------------------------ 2 state model
def state_diagram():
    s = Svg(1060, 1050, "State Model – Assignment Submission")
    start_node(s, 500, 90)
    ns = state(s, 500, 180, "Not submitted")
    sb = state(s, 500, 340, "Submitted", "files can still be replaced")
    ms = state(s, 830, 340, "Missing", "deadline passed, no submission")
    ur = state(s, 500, 510, "Under review", "files locked")
    gr = state(s, 500, 700, "Graded", "marks + feedback")
    rt = state(s, 830, 700, "Returned", "needs changes")
    rs = state(s, 830, 870, "Resubmitted", "attempt + 1")
    end_node(s, 430, 975)

    s.line([(500, 103), (500, ns.y)], end="filled")
    label(s, 512, 128, "assignment published", anchor="start")
    s.line([(500, ns.b), (500, sb.y)], end="filled")
    label(s, 512, 248, "upload work [before deadline]", anchor="start")
    s.line([(ns.r, 180), (830, 180), (830, ms.y)], end="filled")
    label(s, 720, 168, "deadline passes")
    s.line([(ms.x, 340), (sb.r, 340)], end="filled")
    label(s, 665, 300, "upload work [late allowed]\n/ mark late")
    s.line([(500, sb.b), (500, ur.y)], end="filled")
    label(s, 512, 425, "teacher opens", anchor="start")
    s.line([(sb.x, 340), (220, 340), (220, 700), (gr.x, 700)], end="filled")
    label(s, 228, 520, "teacher grades", anchor="start")
    s.line([(480, ur.b), (480, gr.y)], end="filled")
    label(s, 470, 605, "teacher grades", anchor="end")
    s.line([(560, ur.b), (560, 600), (830, 600), (830, rt.y)], end="filled")
    label(s, 700, 588, "teacher returns / add comments")
    s.line([(gr.r, 700), (rt.x, 700)], end="filled")
    label(s, 665, 672, "student requests\nresubmission [allowed]", 12)
    s.line([(830, rt.b), (830, rs.y)], end="filled")
    label(s, 842, 785, "student uploads again", anchor="start")
    s.line([(rs.r, 870), (990, 870), (990, 510), (ur.r, 510)], end="filled")
    label(s, 800, 498, "teacher opens")
    s.line([(rs.x, 870), (540, 870), (540, gr.b)], end="filled")
    label(s, 640, 858, "teacher grades")
    s.line([(430, gr.b), (430, 961)], end="filled")
    label(s, 420, 840, "assignment closed", anchor="end")
    return s


# ------------------------------------------------------------------ 3 activity diagram
def activity_diagram():
    s = Svg(1330, 1540, "Activity Diagram – Assignment Process")
    lanes = [("Teacher", 20, 450), ("System", 450, 880), ("Student", 880, 1310)]
    for name, x0, x1 in lanes:
        s.rect(x0, 70, x1 - x0, 1452, sw=1.5)
        s.rect(x0, 70, x1 - x0, 38, sw=1.5)
        s.text((x0 + x1) / 2, 89, name, 16, bold=True)
    T, S, U = 235, 680, 1095

    start_node(s, T, 138)
    a1 = action(s, T, 210, "Create assignment\n(title, class, subject, due date, marks)", w=300, h=58)
    a2 = action(s, T, 295, "Attach study material (optional)")
    d1 = diamond(s, T, 385, "Publish now?", w=150, h=64)
    draft = action(s, 100, 470, "Save as draft,\npublish later", w=150, h=52)
    m = diamond(s, T, 560, w=44, h=44)
    a3 = action(s, S, 640, "Make assignment visible to class", w=270)
    a4 = action(s, S, 725, "Send notification to students", w=270)
    a5 = action(s, U, 725, "Open assignment and\nread instructions", w=240, h=56)
    a6 = action(s, U, 815, "Upload work")
    d2 = diamond(s, S, 910, "Deadline passed and\nlate not allowed?", w=250, h=84)
    rej = action(s, 525, 1000, "Reject submission", w=130, h=46, size=13)
    d3 = diamond(s, 680, 1055, "File type and\nsize valid?", w=190, h=74)
    a7 = action(s, 680, 1150, "Save submission\n(mark late if after deadline)", w=250, h=56)
    a8 = action(s, 680, 1235, "Notify student\n\"Submission received\"", w=250, h=56)
    a9 = action(s, T, 1235, "Review submission", w=220)
    d4 = diamond(s, T, 1325, "Work acceptable?", w=180, h=64)
    resub = action(s, U, 1325, "Resubmit improved work", w=240)
    a10 = action(s, T, 1415, "Give marks and feedback", w=240)
    a11 = action(s, 680, 1415, "Notify student \"Graded\"", w=250)
    a12 = action(s, U, 1415, "View grade and feedback", w=240)

    s.line([(T, 151), (T, a1.y)], end="filled")
    s.line([(T, a1.b), (T, a2.y)], end="filled")
    s.line([(T, a2.b), (T, d1.y)], end="filled")
    s.line([(d1.x, 385), (100, 385), (100, draft.y)], end="filled")
    label(s, 118, 373, "no")
    s.line([(T, d1.b), (T, m.y)], end="filled")
    label(s, 250, 470, "yes", anchor="start")
    s.line([(100, draft.b), (100, 560), (m.x, 560)], end="filled")
    s.line([(T, m.b), (T, 640), (a3.x, 640)], end="filled")
    s.line([(S, a3.b), (S, a4.y)], end="filled")
    s.line([(a4.r, 725), (a5.x, 725)], end="filled")
    s.line([(U, a5.b), (U, a6.y)], end="filled")
    s.line([(U, a6.b), (U, 910), (d2.r, 910)], end="filled")
    s.line([(d2.x, 910), (525, 910), (525, rej.y)], end="filled")
    label(s, 510, 898, "yes", anchor="end")
    end_node(s, 525, 1075)
    s.line([(525, rej.b), (525, 1061)], end="filled")
    s.line([(680, 931), (680, d3.y)], end="filled")
    label(s, 692, 990, "no", anchor="start")
    s.line([(d3.r, 1055), (1250, 1055), (1250, 815), (a6.r, 815)], end="filled")
    label(s, 1000, 1043, "no – show error, student uploads a correct file")
    s.line([(680, d3.b), (680, a7.y)], end="filled")
    label(s, 692, 1105, "yes", anchor="start")
    s.line([(680, a7.b), (680, a8.y)], end="filled")
    s.line([(a8.x, 1235), (a9.r, 1235)], end="filled")
    s.line([(T, a9.b), (T, d4.y)], end="filled")
    s.line([(d4.r, 1325), (resub.x, 1325)], end="filled")
    label(s, 650, 1313, "no – return for revision with comments")
    s.line([(U, resub.y), (U, 1150), (a7.r, 1150)], end="filled")
    s.line([(T, d4.b), (T, a10.y)], end="filled")
    label(s, 250, 1372, "yes", anchor="start")
    s.line([(a10.r, 1415), (a11.x, 1415)], end="filled")
    s.line([(a11.r, 1415), (a12.x, 1415)], end="filled")
    s.line([(U, a12.b), (U, 1473)], end="filled")
    end_node(s, U, 1487)
    return s


# ------------------------------------------------------------------ 4 use case diagram
def usecase_diagram():
    s = Svg(1160, 1560, "Use Case Diagram – EduFlow")
    s.rect(330, 70, 800, 1470)
    s.text(730, 92, "EduFlow System", 17, bold=True)
    A, B = 560, 950
    uc = {}
    rows = [
        ("login", A, 150, "Log in"), ("profile", A, 225, "Manage profile"), ("reset", B, 150, "Reset password"),
        ("users", A, 320, "Manage users"), ("import", B, 320, "Import users (CSV)"),
        ("classes", A, 385, "Manage classes & subjects"), ("enrol", A, 450, "Enroll students"),
        ("assignT", A, 515, "Assign teachers"), ("announce", A, 610, "Post announcement"),
        ("reports", A, 680, "View reports"), ("create", A, 790, "Create assignment"),
        ("publish", A, 860, "Publish assignment"), ("grade", A, 930, "Review & grade submission"),
        ("return", B, 1010, "Return for revision"), ("notify", B, 860, "Send notification"),
        ("view", A, 1090, "View assignments"), ("submit", A, 1160, "Submit assignment"),
        ("validate", B, 1160, "Validate file"), ("tasks", A, 1230, "Manage personal tasks"),
        ("calendar", A, 1300, "View calendar"), ("progress", A, 1370, "View progress dashboard"),
        ("remind", A, 1470, "Send reminders"),
    ]
    for key, x, y, t in rows:
        uc[key] = usecase(s, x, y, t)

    user = actor(s, 90, 110, "User")
    adm = actor(s, 230, 380, "Admin")
    tea = actor(s, 230, 760, "Teacher")
    stu = actor(s, 230, 1150, "Student")
    cron = actor(s, 90, 1420, "Scheduler", "«system»")

    def assoc(a, key):
        u = uc[key]
        s.line([(a.cx + 24, a.y + 32), (u.x, u.cy)])

    for k in ("login", "profile"):
        assoc(user, k)
    for k in ("users", "classes", "enrol", "assignT", "announce", "reports"):
        assoc(adm, k)
    for k in ("announce", "reports", "create", "publish", "grade"):
        assoc(tea, k)
    for k in ("view", "submit", "tasks", "calendar", "progress"):
        assoc(stu, k)
    s.line([(cron.cx + 24, 1470), (uc["remind"].x, 1470)])

    # actor generalisation: Admin, Teacher, Student are Users
    s.line([(90, stu.y + 32), (90, 225)], end="tri")
    for a in (adm, tea, stu):
        s.line([(a.cx - 22, a.y + 32), (90, a.y + 32)])

    def dep(frm, to, text, pts=None, at=None):
        a, b = uc[frm], uc[to]
        if pts is None:
            pts = [(a.x if a.cx > b.cx else a.r, a.cy), (b.r if a.cx > b.cx else b.x, b.cy)]
        s.line(pts, end="open", dash=True)
        x, y = at or ((pts[0][0] + pts[1][0]) / 2, (pts[0][1] + pts[1][1]) / 2 - 12)
        label(s, x, y, text, 12, italic=True)

    dep("reset", "login", "«extend»")
    dep("import", "users", "«extend»")
    dep("return", "grade", "«extend»", [(uc["return"].x, 1010), (620, 1010), (620, 953)], at=(720, 998))
    dep("submit", "validate", "«include»")
    dep("publish", "notify", "«include»")
    dep("grade", "notify", "«include»", [(uc["grade"].r, 930), (B, 930), (B, uc["notify"].b)], at=(B + 46, 912))
    dep("announce", "notify", "«include»", [(uc["announce"].r, 610), (B, 610), (B, uc["notify"].y)], at=(B + 46, 720))
    dep("remind", "notify", "«include»", [(uc["remind"].r, 1470), (1100, 1470), (1100, 860), (uc["notify"].r, 860)],
        at=(1060, 1200))
    return s


# ------------------------------------------------------------------ 5 component diagram
def assembly(s, cx, req_y, prov_y, cy, name):
    """Assembly connector (ball-and-socket) on a vertical line: the requiring component is above
    (line from req_y down to the socket), the providing component below (line from the ball down to prov_y)."""
    s.line([(cx, req_y), (cx, cy - 15)])
    s.add(f'<path d="M{cx - 15},{cy} A15,15 0 0 1 {cx + 15},{cy}" fill="none" stroke="#000" stroke-width="1.5"/>')
    s.add(f'<circle cx="{cx}" cy="{cy}" r="9" fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.line([(cx, cy + 9), (cx, prov_y)])
    label(s, cx + 24, cy + 1, name, 13, anchor="start")


def use(s, pts, text="«use»", at=None, anchor="start"):
    """Dependency: dashed line with an open arrowhead towards the component that is used."""
    s.line(pts, end="open", dash=True)
    if text and at:
        label(s, at[0], at[1], text, 12, anchor=anchor, italic=True)


def component_diagram():
    s = Svg(1300, 1300, "Component Diagram – EduFlow")
    package(s, 30, 64, 1240, 230, "Frontend (Browser)")
    pages = component(s, 200, 135, "Pages\n(HTML + Bootstrap)", w=250, h=62)
    dash = component(s, 650, 135, "Dashboards\n(Chart.js charts)", w=250, h=62)
    tjs = component(s, 1100, 135, "Task & Calendar\nscripts", w=250, h=62)
    app = component(s, 650, 245, "App Script", w=280, h=56)
    use(s, [(200, pages.b), (200, 245), (app.x, 245)], at=(360, 233), anchor="middle")
    use(s, [(650, dash.b), (650, app.y)], at=(660, 197))
    use(s, [(1100, tjs.b), (1100, 245), (app.r, 245)], at=(940, 233), anchor="middle")

    package(s, 30, 330, 1240, 760, "Backend (PHP)")
    router = component(s, 650, 400, "Router", w=240, h=52, stereo=None)
    assembly(s, 650, app.b, router.y, 323, "JSON API (HTTPS)")
    sec = component(s, 650, 483, "Security\n(login, role check, CSRF)", w=300, h=58, stereo=None)
    ctrl = component(s, 650, 570, "Controllers\n(Admin, Teacher, Student)", w=300, h=58, stereo=None)
    s.line([(650, router.b), (650, sec.y)])            # association
    s.line([(650, sec.b), (650, ctrl.y)])              # association

    svc = package(s, 60, 680, 1180, 280, "Services")
    assembly(s, 650, ctrl.b, svc.y, 645, "Service interface")
    uc = component(s, 190, 775, "User & Class\nManagement", w=210, stereo=None)
    asg = component(s, 480, 775, "Assignment\nService", w=210, stereo=None)
    sub = component(s, 770, 775, "Submission\nService", w=210, stereo=None)
    fs = component(s, 1080, 775, "File\nStorage", w=200, stereo=None)
    component(s, 190, 895, "Personal Task\nService", w=210, stereo=None)
    nt = component(s, 480, 895, "Notification\nService", w=210, stereo=None)
    component(s, 1080, 895, "Analytics\nService", w=200, stereo=None)
    use(s, [(480, asg.b), (480, nt.y)], at=(490, 845))
    use(s, [(720, sub.b), (720, 895), (nt.r, 895)], at=(730, 845))
    use(s, [(sub.r, 775), (fs.x, 775)], at=(925, 763), anchor="middle")

    dba = component(s, 820, 1045, "Database Access (PDO)", w=290, h=52, stereo=None)
    assembly(s, 820, svc.b, dba.y, 990, "Data access interface")
    cron = component(s, 430, 1045, "Reminder Job (cron)", w=260, h=52, stereo=None)
    use(s, [(430, cron.y), (430, nt.b)], at=(442, 990))

    db = cylinder(s, 820, 1130, 180, 80, "MySQL Database")
    s.line([(820, dba.b), (820, db.y)])                # association
    label(s, 832, 1110, "PDO / TCP 3306", 12, anchor="start", italic=True)
    s.add('<path d="M1040,1140 h60 l10,10 h110 v54 h-180 z" fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.text(1130, 1180, "Uploaded Files", 14, bold=True)
    s.line([(fs.r, 775), (1215, 775), (1215, 1140)])  # association
    label(s, 1205, 1110, "stores", 12, anchor="end", italic=True)

    # legend
    s.rect(40, 1110, 360, 170, dash=True, sw=1.2)
    s.text(60, 1130, "Legend", 14, bold=True, anchor="start")
    component_icon(s, 62, 1148)
    s.text(100, 1158, "Component", 13, anchor="start")
    s.line([(55, 1190), (125, 1190)])
    s.text(140, 1190, "Association (communicates with)", 13, anchor="start")
    s.line([(55, 1220), (125, 1220)], end="open", dash=True)
    s.text(140, 1220, "Dependency «use»", 13, anchor="start")
    s.line([(55, 1255), (82, 1255)])
    s.add('<path d="M82,1240 A15,15 0 0 1 82,1270" fill="none" stroke="#000" stroke-width="1.5"/>')
    s.add('<circle cx="88" cy="1255" r="8" fill="#fff" stroke="#000" stroke-width="1.5"/>')
    s.line([(96, 1255), (125, 1255)])
    s.text(140, 1255, "Assembly connector (socket / ball)", 13, anchor="start")
    return s


# ------------------------------------------------------------------ 6 sequence diagram
def sequence_diagram():
    parts = [("Student", 90, "actor"), ("Browser", 260, "box"), ("Controller", 430, "box"),
             ("SubmissionService", 610, "box"), ("FileStorage", 790, "box"), ("MySQL", 950, "db"),
             ("NotificationService", 1125, "box"), ("Teacher", 1300, "actor")]
    X = {n: x for n, x, _ in parts}
    msgs = [
        ("Student", "Browser", "open assignment"),
        ("Browser", "Controller", "GET /assignments/{id}"),
        ("Controller", "MySQL", "load assignment (only if addressed to student)"),
        ("MySQL", "Controller", "assignment details", "ret"),
        ("Controller", "Browser", "instructions and due date", "ret"),
        ("Student", "Browser", "choose file, click Submit"),
        ("Browser", "Controller", "POST submission (file + CSRF)"),
        ("Controller", "SubmissionService", "submit(assignment, files)"),
        ("SubmissionService", "SubmissionService", "check open and late rule", "self"),
        ("ALT", "not allowed (closed / too late)"),
        ("SubmissionService", "Browser", "error \"Submissions closed\"", "ret"),
        ("ELSE", "allowed"),
        ("SubmissionService", "FileStorage", "store(file)"),
        ("FileStorage", "FileStorage", "check type, content, size", "self"),
        ("ALT2", "invalid file"),
        ("FileStorage", "Browser", "error \"File type not allowed\"", "ret"),
        ("ELSE2", "valid file"),
        ("FileStorage", "SubmissionService", "stored file name", "ret"),
        ("SubmissionService", "MySQL", "save submission (submitted, isLate)"),
        ("SubmissionService", "NotificationService", "notify(\"Submission received\")"),
        ("NotificationService", "MySQL", "save notification"),
        ("SubmissionService", "Browser", "\"Submitted successfully\"", "ret"),
        ("END2",), ("END",),
        ("Teacher", "Controller", "open review queue"),
        ("Controller", "MySQL", "load submissions for teacher's classes"),
        ("Teacher", "SubmissionService", "grade(marks, feedback)"),
        ("SubmissionService", "MySQL", "update status = graded, save feedback"),
        ("SubmissionService", "NotificationService", "notify student \"Graded\""),
        ("NotificationService", "MySQL", "save notification"),
        ("Student", "Browser", "view marks and feedback"),
    ]
    H = 1450
    s = Svg(1400, H, "Sequence Diagram – Submit and Grade an Assignment")
    top = 70
    for name, x, kind in parts:
        if kind == "actor":
            actor(s, x, top, name)
            y0 = top + 104
        elif kind == "db":
            cylinder(s, x, top + 8, 110, 60, name, 14)
            y0 = top + 68
        else:
            w = max(130, len(name) * 9 + 20)
            s.rect(x - w / 2, top + 12, w, 46)
            s.text(x, top + 35, name, 14, bold=True)
            y0 = top + 58
        s.line([(x, y0), (x, H - 30)], dash=True, sw=1)

    y, n, frames = 205, 0, []
    for m in msgs:
        if m[0] in ("ALT", "ALT2"):
            inner = m[0] == "ALT2"
            frames.append({"y": y, "x0": 175 if not inner else 195, "x1": 1035 if not inner else 1015, "guard": m[1], "else": []})
            y += 52
            continue
        if m[0] in ("ELSE", "ELSE2"):
            frames[-1]["else"].append((y, m[1]))
            y += 48
            continue
        if m[0] in ("END", "END2"):
            f = frames.pop()
            s.rect(f["x0"], f["y"], f["x1"] - f["x0"], y - f["y"] + 4, fill="none", sw=1.2)
            s.add(f'<path d="M{f["x0"]},{f["y"]} h48 v16 l-8,8 h-40 z" fill="#fff" stroke="#000" stroke-width="1.2"/>')
            s.text(f["x0"] + 22, f["y"] + 12, "alt", 13, bold=True)
            label(s, f["x0"] + 58, f["y"] + 14, f"[{f['guard']}]", 12, anchor="start", italic=True)
            for ey, g in f["else"]:
                s.line([(f["x0"], ey), (f["x1"], ey)], dash=True, sw=1.2)
                label(s, f["x0"] + 10, ey + 15, f"[{g}]", 12, anchor="start", italic=True)
            y += 22
            continue
        frm, to, text = m[0], m[1], m[2]
        kind = m[3] if len(m) > 3 else "call"
        n += 1
        x1, x2 = X[frm], X[to]
        if kind == "self":
            s.line([(x1, y - 6), (x1 + 40, y - 6), (x1 + 40, y + 12), (x1 + 2, y + 12)], end="filled")
            label(s, x1 + 48, y + 3, f"{n}. {text}", 12, anchor="start")
            y += 44
            continue
        s.line([(x1, y), (x2, y)], end="open" if kind == "ret" else "filled", dash=kind == "ret")
        label(s, (x1 + x2) / 2, y - 9, f"{n}. {text}", 12)
        y += 36
    return s


# ------------------------------------------------------------------ 7 deployment diagram
def deployment_diagram():
    s = Svg(1420, 1060, "Deployment Diagram – EduFlow")
    dev = node3d(s, 40, 90, 470, 210, "User Device (PC / Mobile)")
    s.rect(70, 150, 410, 130, sw=1.3)
    s.text(275, 168, "«execution environment»  Web Browser", 13, italic=True)
    artifact(s, 175, 225, "HTML pages", w=170)
    artifact(s, 375, 225, "JavaScript & CSS", w=170)

    cdn = node3d(s, 930, 90, 430, 210, "CDN (external)", stereo="«external»")
    artifact(s, 1145, 215, "Bootstrap 5, Chart.js 4", w=260)
    s.line([(dev.r, 190), (cdn.x, 190)], end="open")
    label(s, 720, 176, "HTTPS")

    web = node3d(s, 40, 390, 860, 620, "Web Server (XAMPP / Linux)")
    s.rect(70, 460, 380, 170, sw=1.3)
    s.text(260, 478, "«execution environment»  Apache Web Server", 13, italic=True)
    s.rect(110, 495, 300, 115, sw=1.1, dash=True)
    s.text(260, 512, "public/", 13, bold=True)
    artifact(s, 260, 565, "index.php", w=200)

    s.rect(490, 460, 380, 250, sw=1.3)
    s.text(680, 478, "«execution environment»  PHP 8 Runtime", 13, italic=True)
    app = artifact(s, 680, 545, "EduFlow Application", w=260)
    artifact(s, 680, 640, ".env config", w=200)

    s.rect(70, 680, 380, 130, sw=1.3)
    s.text(260, 698, "«execution environment»  Task Scheduler", 13, italic=True)
    artifact(s, 260, 755, "reminders.php", w=200)

    s.rect(490, 790, 380, 190, sw=1.3, dash=True)
    s.text(680, 810, "storage/  (outside the web root)", 13, bold=True)
    artifact(s, 680, 865, "Uploaded files", w=220)
    artifact(s, 680, 935, "Logs", w=220)

    s.line([(260, dev.b), (260, 460)], end="open")
    label(s, 272, 345, "HTTPS", anchor="start")
    s.line([(450, 545), (490, 545)], end="open")
    s.line([(450, 700), (490, 700)], end="open")
    label(s, 470, 720, "runs", 12)
    s.line([(680, 710), (680, 790)], end="open")
    label(s, 692, 752, "reads / writes", 12, anchor="start")

    dbs = node3d(s, 1010, 420, 360, 270, "Database Server")
    s.rect(1040, 480, 300, 190, sw=1.3)
    s.text(1190, 498, "«execution environment»  MySQL 8", 13, italic=True)
    cylinder(s, 1190, 530, 170, 110, "«database»\neduflow", 14)
    s.line([(870, 545), (1040, 545)], end="open")
    label(s, 955, 531, "TCP 3306")

    mail = node3d(s, 1010, 790, 360, 150, "Mail Server", stereo="«external»")
    artifact(s, 1190, 893, "SMTP mail service", w=230)
    s.line([(870, 600), (960, 600), (960, 865), (mail.x, 865)], end="open")
    label(s, 968, 760, "SMTP\n(password reset)", 12, anchor="start")
    return s


# ------------------------------------------------------------------ render
DIAGRAMS = {
    "class": class_diagram, "state": state_diagram, "activity": activity_diagram, "use-case": usecase_diagram,
    "component": component_diagram, "sequence": sequence_diagram, "deployment": deployment_diagram,
}


def main():
    from selenium import webdriver

    opts = webdriver.ChromeOptions()
    opts.add_argument("--headless=new")
    opts.add_argument("--hide-scrollbars")
    driver = webdriver.Chrome(options=opts)
    try:
        for name, fn in DIAGRAMS.items():
            s = fn()
            path = s.save(name)
            driver.execute_cdp_cmd("Emulation.setDeviceMetricsOverride",
                                   {"width": s.w, "height": s.h, "deviceScaleFactor": 2.5, "mobile": False})
            driver.get(path.as_uri())
            out = PNG_DIR / f"UML_{name}.png"
            driver.save_screenshot(str(out))
            print("Wrote", out)
    finally:
        driver.quit()


if __name__ == "__main__":
    main()
