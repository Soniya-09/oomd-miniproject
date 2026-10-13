"""Copy the real results from reports/results.json into the Actual Result and Status
columns of test_cases.md. Run after the test suite:  python fill_results.py
"""

import json
import re
from pathlib import Path

ROOT = Path(__file__).parent
results = json.loads((ROOT / "reports" / "results.json").read_text(encoding="utf-8"))
md_path = ROOT / "test_cases.md"
lines = md_path.read_text(encoding="utf-8").splitlines()

out = []
for line in lines:
    m = re.match(r"^\| (TC\d+) \|", line)
    if m and m.group(1) in results:
        # TC ID | Description | Steps | Expected | Actual | Status
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        r = results[m.group(1)]
        cells[4] = (r["actual"] or "").replace("|", "/").replace("\n", " ")
        cells[5] = f"**{r['status']}**"
        line = "| " + " | ".join(cells) + " |"
    out.append(line)

md_path.write_text("\n".join(out) + "\n", encoding="utf-8")
total = len(results)
passed = sum(r["status"] == "Pass" for r in results.values())
print(f"Updated {md_path.name}: {passed}/{total} passed")
