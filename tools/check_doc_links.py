#!/usr/bin/env python3
"""Fail if a relative Markdown link in the repository docs points to a missing file."""
import glob
import os
import re
import sys

FILES = (
    glob.glob("docs/**/*.md", recursive=True)
    + glob.glob("tasks/*.md")
    + glob.glob("deploy/**/*.md", recursive=True)
    + glob.glob("plugins/*.md")
    + ["README.md", "plan.md", "CHANGELOG.md"]
)
LINK = re.compile(r"\]\(([^)#\s]+)(#[^)]*)?\)")

broken = []
for path in FILES:
    if not os.path.exists(path):
        continue
    base = os.path.dirname(path)
    with open(path, encoding="utf-8") as handle:
        for match in LINK.finditer(handle.read()):
            target = match.group(1)
            if target.startswith(("http://", "https://", "mailto:")):
                continue
            if not os.path.exists(os.path.normpath(os.path.join(base, target))):
                broken.append(f"{path}: {target}")

for item in broken:
    print("broken link:", item)
print(f"checked {len(FILES)} files, {len(broken)} broken links")
sys.exit(1 if broken else 0)
