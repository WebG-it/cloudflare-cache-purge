#!/usr/bin/env python3
"""Dev-only: runs the JED Checker `master` branch (component com_jedchecker installed in the
test site, Joomla 6 needs the "Behaviour - Backward Compatibility 6" plugin on) on an extension
zip, the way the screen does it: upload, then one request per rule. Prints per rule the number
of errors, warnings and notices found in the report HTML.

    python scripts/jed_master.py path/to/extension.zip [more.zip ...]
"""

import re
import sys
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from admin_client import Admin  # noqa: E402

RULES = ["encoding", "errorreporting", "framework", "gpl", "jamss", "jexec", "language",
         "xmlfiles", "xmlinfo", "xmllicense", "xmlmanifest", "xmlupdateserver"]


def multipart(fields: dict, file_field: str, file_path: Path) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    body = b""
    for key, value in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{file_path.name}"\r\n'
             "Content-Type: application/zip\r\n\r\n").encode() + file_path.read_bytes() + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    return body, f"multipart/form-data; boundary={boundary}"


def main() -> int:
    env = dict(l.strip().split("=", 1) for l in (Path(__file__).resolve().parents[1] / ".env").read_text().splitlines() if "=" in l)
    admin = Admin(int(env.get("DEV_PORT", "8790")))
    admin.login("admin", env["DEV_ADMIN_PASSWORD"])
    totals_all = 0

    for arg in sys.argv[1:]:
        zip_path = Path(arg)
        _, page, _ = admin.request("/administrator/index.php?option=com_jedchecker&view=uploads")
        token = admin.token(page)
        body, ctype = multipart({"task": "uploads.upload", "option": "com_jedchecker", token: "1"}, "extension", zip_path)
        import urllib.request
        req = urllib.request.Request(admin._url("/administrator/index.php?option=com_jedchecker&task=uploads.upload"), data=body,
                                     headers={"Host": admin.host, "Content-Type": ctype})
        try:
            admin.opener.open(req, timeout=120)
        except Exception:
            pass  # the upload answers with a redirect
        print(f"== {zip_path.name}")
        total = {"error": 0, "warning": 0, "notice": 0}
        for rule in RULES:
            status, html, _ = admin.request(f"/administrator/index.php?option=com_jedchecker&task=police.check&format=raw&rule={rule}")
            counts = {
                "error": len(re.findall(r'class="[^"]*(?:alert-danger|bg-danger|label-important|badge-important)', html)),
                "warning": len(re.findall(r'class="[^"]*(?:alert-warning|bg-warning|badge-warning)', html)),
                "notice": len(re.findall(r'class="[^"]*(?:alert-info|bg-info|badge-info|alert-notice)', html)),
            }
            texts = re.sub(r"<[^>]+>", " ", html)
            texts = re.sub(r"\s+", " ", texts).strip()
            print(f"  {rule:15} HTTP {status} {counts} {texts[:160]}")
            for k in total:
                total[k] += counts[k]
        print(f"  TOTAL {total}")
        totals_all += total["error"] + total["warning"]
    return 1 if totals_all else 0


if __name__ == "__main__":
    sys.exit(main())
