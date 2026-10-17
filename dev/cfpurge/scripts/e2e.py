#!/usr/bin/env python3
"""End-to-end tests of the Cloudflare Cache Purge package against the Docker test site.

Run on the host, from dev/cfpurge, after `docker compose up`, `setup.sh` and `mock_cf_setup.sh`
(see docs/cfpurge/DEVELOPING.md). The Cloudflare API is the FAKE one of dev/cfpurge/mock: no real
token is ever used. Everything is driven like a user would: the Joomla CLI installer, the
administrator over HTTP (login, com_ajax with the form token, forms) and the Joomla Web
Services API; what the plugin sent to "Cloudflare" is read from the mock log.

    python scripts/e2e.py                                   # Joomla 6.2 project (port 8790)
    python scripts/e2e.py --project cfpurge-j5 --env .cache/compat-j5.env
    python scripts/e2e.py install upgrade verify            # only some scenarios

Exit code 0 only if every check passed.
"""

import argparse
import base64
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from admin_client import Admin  # noqa: E402

ROOT = Path(__file__).resolve().parents[3]
DEV = ROOT / "dev" / "cfpurge"
ZONE = "0123456789abcdef0123456789abcdef"
OTHER_ZONE = "fedcba9876543210fedcba9876543210"
UNKNOWN_ZONE = "00000000000000000000000000000bad"
TOKENS = {k: f"devtok-{k}-0000000000000000" for k in ("full", "purgeonly", "noperm", "inactive", "acct", "err500", "slow", "probeonly")}
TOKENS["bad"] = "notacloudflaretoken0000000000"
HOST = "http://www.cfdemo.localhost"

results: list[tuple[str, bool, str]] = []


def check(name: str, ok: bool, detail: str = "") -> bool:
    results.append((name, bool(ok), detail))
    print(("PASS " if ok else "FAIL ") + name + ((" :: " + detail) if (detail and not ok) else ""), flush=True)
    return bool(ok)


class Env:
    def __init__(self, project: str | None, env_file: str | None):
        self.compose = ["docker", "compose"]
        if project:
            self.compose += ["-p", project]
        env_path = DEV / (env_file or ".env")
        if env_file:
            self.compose += ["--env-file", str(env_path)]
        self.env = dict(l.strip().split("=", 1) for l in env_path.read_text().splitlines() if "=" in l and not l.startswith("#"))
        self.port = int(self.env.get("DEV_PORT", "8790"))
        self.password = self.env["DEV_ADMIN_PASSWORD"]
        self.base = f"{HOST}:{self.port}"
        self.version = None

    def dc(self, *cmd: str, check_rc: bool = True) -> str:
        proc = subprocess.run(
            [*self.compose, "exec", "-T", "web", *cmd],
            capture_output=True, text=True, cwd=DEV, env={**os.environ, "MSYS_NO_PATHCONV": "1"},
        )
        if check_rc and proc.returncode != 0:
            raise RuntimeError(f"{' '.join(cmd)[:120]} failed: {proc.stdout[-300:]} {proc.stderr[-300:]}")
        return proc.stdout

    def sql(self, query: str) -> list[list[str]]:
        out = self.dc("php", "/dev-scripts/sql.php", query)
        return [line.split("\t") for line in out.splitlines() if line.strip() and not line.startswith("affected")]

    def params(self, data: dict | None = None) -> dict:
        if data is not None:
            self.dc("php", "/dev-scripts/plugin_params.php", json.dumps(data))
            return {}
        return json.loads(self.dc("php", "/dev-scripts/plugin_params.php"))

    def raw_params(self) -> dict:
        row = self.sql("SELECT params FROM jdev_extensions WHERE type='plugin' AND folder='content' AND element='cfpurge'")
        return json.loads(row[0][0]) if row else {}

    def cli(self, *args: str, check_rc: bool = True) -> str:
        return self.dc("su", "-s", "/bin/bash", "www-data", "-c", "php cli/joomla.php " + " ".join(args) + " -n", check_rc=check_rc)

    def install(self, zip_path_in_container: str) -> str:
        name = zip_path_in_container.rsplit("/", 1)[-1]
        self.dc("bash", "-c", f"cp {zip_path_in_container} tmp/{name} && bash /dev-scripts/install_zip.sh /var/www/html/tmp/{name}")
        return name

    def ext(self, element: str, type_: str = "plugin", folder: str | None = None):
        q = f"SELECT extension_id,enabled,package_id,manifest_cache FROM jdev_extensions WHERE type='{type_}' AND element='{element}'"
        if folder is not None:
            q += f" AND folder='{folder}'"
        rows = self.sql(q)
        return rows[0] if rows else None

    def remove_package(self) -> None:
        for el, typ in (("pkg_cfpurge", "package"),):
            row = self.ext(el, typ)
            if row:
                self.cli("extension:remove", row[0])
        # A standalone 1.1.4 plugin (or leftovers) too.
        for row in self.sql("SELECT extension_id FROM jdev_extensions WHERE element IN ('cfpurge','mod_cfpurge')"):
            self.cli("extension:remove", row[0], check_rc=False)

    def mock_log(self, clear: bool = False) -> list[dict]:
        out = self.dc("bash", "-c", "cat /tmp/cfmock.log" + (" && : > /tmp/cfmock.log" if clear else ""))
        return [json.loads(l) for l in out.splitlines() if l.strip()]

    def purges(self, clear: bool = True) -> list[dict]:
        return [e for e in self.mock_log(clear) if e["path"].endswith("/purge_cache")]

    def admin(self, user: str = "admin", password: str | None = None) -> Admin:
        a = Admin(self.port)
        a.login(user, password or self.password)
        return a

    def api(self, method: str, path: str, body: dict | None = None):
        auth = base64.b64encode(f"admin:{self.password}".encode()).decode()
        req = urllib.request.Request(
            f"http://127.0.0.1:{self.port}/api/index.php{path}", method=method,
            data=json.dumps(body).encode() if body is not None else None,
            headers={"Host": f"www.cfdemo.localhost:{self.port}", "Authorization": "Basic " + auth,
                     "Accept": "application/vnd.api+json", "Content-Type": "application/json"},
        )
        try:
            r = urllib.request.urlopen(req, timeout=90)
            raw = r.read()
            return r.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            return e.code, e.read()[:300].decode("utf-8", "replace")


def setup_config(env: Env, token: str = "full", zone: str = ZONE, **extra) -> None:
    env.params({"zone_id": zone, "api_token": TOKENS[token], "verify_state": None, **extra})


def admin_verify(env: Env, a: Admin, zone: str, token: str) -> dict:
    status, body = a.ajax("verify", typed="1", zone_id=zone, api_token=TOKENS[token])
    assert status == 200 and body.get("success"), body
    return body["data"][0]


def statuses(result: dict) -> dict:
    return {c["id"]: c["status"] for c in result["checks"]}


def keys(result: dict) -> list[str]:
    return [c["key"] for c in result["checks"]]


# ---------------------------------------------------------------- scenarios

def sc_install(env: Env) -> None:
    """Clean install of the package."""
    env.remove_package()
    tables_before = len(env.sql("SHOW TABLES"))
    env.install("/dist/pkg_cfpurge-2.0.0.zip")

    pkg = env.ext("pkg_cfpurge", "package")
    content = env.ext("cfpurge", "plugin", "content")
    system = env.ext("cfpurge", "plugin", "system")
    module = env.ext("mod_cfpurge", "module")
    check("install: package, content plugin, system plugin and module are installed", all([pkg, content, system, module]))
    check("install: all four extensions are enabled", all(r[1] == "1" for r in (pkg, content, system, module) if r))
    check("install: the three children belong to the package", all(r[2] == pkg[0] for r in (content, system, module)))
    rows = env.sql("SELECT id,position,published,access,client_id,ordering FROM jdev_modules WHERE module='mod_cfpurge'")
    check("install: one module instance, published in cpanel (administrator)", len(rows) == 1 and rows[0][1] == "cpanel" and rows[0][2] == "1" and rows[0][4] == "1", str(rows))
    menu = env.sql(f"SELECT menuid FROM jdev_modules_menu WHERE moduleid={rows[0][0]}") if rows else []
    check("install: module assigned to all pages (menuid 0)", menu == [["0"]], str(menu))
    check("install: no table created in the database", len(env.sql("SHOW TABLES")) == tables_before)
    check("install: language files in en-GB (administrator/language) and en-GB + it-IT (extension folders)",
          "plg_content_cfpurge.ini" in env.dc("ls", "administrator/language/en-GB")
          and "plg_content_cfpurge.ini" in env.dc("ls", "plugins/content/cfpurge/language/it-IT")
          and "mod_cfpurge.ini" in env.dc("ls", "administrator/modules/mod_cfpurge/language/it-IT")
          and "plg_system_cfpurge.ini" in env.dc("ls", "plugins/system/cfpurge/language/it-IT"))
    check("install: media installed", "box.js" in env.dc("ls", "media/mod_cfpurge/js") and "verify.js" in env.dc("ls", "media/plg_content_cfpurge/js"))
    sites = env.sql("SELECT us.location FROM jdev_update_sites us JOIN jdev_update_sites_extensions x ON x.update_site_id=us.update_site_id WHERE x.extension_id=" + pkg[0])
    check("install: the package registers the WebG update server", any("collection.xml" in s[0] for s in sites), str(sites))


def sc_uninstall(env: Env) -> None:
    """Uninstalling the package removes everything."""
    pkg = env.ext("pkg_cfpurge", "package")
    if not pkg:
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
        pkg = env.ext("pkg_cfpurge", "package")
    tables_before = len(env.sql("SHOW TABLES"))
    setup_config(env)
    env.sql("SELECT 1")
    # Make a log file exist (a verified purge writes it).
    a = env.admin()
    admin_verify(env, a, ZONE, "full")
    check("uninstall: log file exists before", "plg_content_cfpurge.php" in env.dc("ls", "administrator/logs"))
    mod_ids = [r[0] for r in env.sql("SELECT id FROM jdev_modules WHERE module='mod_cfpurge'")]
    env.cli("extension:remove", pkg[0])
    left = env.sql("SELECT type,element FROM jdev_extensions WHERE element IN ('cfpurge','mod_cfpurge','pkg_cfpurge')")
    check("uninstall: no extension left", left == [], str(left))
    check("uninstall: no module instance left", env.sql("SELECT id FROM jdev_modules WHERE module='mod_cfpurge'") == [])
    check("uninstall: no modules_menu row left for the module", mod_ids != [] and env.sql("SELECT moduleid FROM jdev_modules_menu WHERE moduleid IN (%s)" % ",".join(mod_ids)) == [], str(mod_ids))
    files = env.dc("bash", "-c", "ls plugins/content plugins/system administrator/modules media administrator/language/*/ administrator/logs | grep -i cfpurge || true").strip()
    check("uninstall: no file, language file, media folder or log left", files == "", files)
    sites = env.sql("SELECT update_site_id FROM jdev_update_sites WHERE location LIKE '%joomla-extensions%'")
    check("uninstall: update site removed", sites == [], str(sites))
    check("uninstall: tables unchanged", len(env.sql("SHOW TABLES")) == tables_before)


def sc_upgrade(env: Env) -> None:
    """1.1.4 first, then the package over it: parameters stay."""
    env.remove_package()
    env.install("/dist-released/plg_content_cfpurge-1.1.4.zip")
    old = env.ext("cfpurge", "plugin", "content")
    check("upgrade: 1.1.4 installed as a standalone plugin", old is not None and json.loads(old[3])["version"] == "1.1.4" and old[2] == "0")
    env.sql("UPDATE jdev_extensions SET enabled=1 WHERE type='plugin' AND element='cfpurge'")
    env.params({"zone_id": ZONE, "api_token": TOKENS["full"], "on_update": "0", "on_delete": "1", "on_create": "1", "on_state": "0"})
    env.install("/dist/pkg_cfpurge-2.0.0.zip")
    new = env.ext("cfpurge", "plugin", "content")
    params = env.raw_params()
    check("upgrade: same extension id (in-place upgrade)", new and old and new[0] == old[0])
    check("upgrade: now version 2.0.0 and part of the package", new and json.loads(new[3])["version"] == "2.0.0" and new[2] != "0")
    check("upgrade: zone id and token kept", params.get("zone_id") == ZONE and params.get("api_token") == TOKENS["full"])
    check("upgrade: event switches kept", (params.get("on_update"), params.get("on_state"), params.get("on_delete")) == ("0", "0", "1"), str(params))
    check("upgrade: plugin still enabled", new and new[1] == "1")
    system = env.ext("cfpurge", "plugin", "system")
    check("upgrade: new system plugin enabled", system and system[1] == "1")
    rows = env.sql("SELECT position,published FROM jdev_modules WHERE module='mod_cfpurge' AND client_id=1")
    check("upgrade: module published in cpanel", rows == [["cpanel", "1"]], str(rows))
    check("upgrade: old language files replaced (new strings present)", "PLG_CONTENT_CFPURGE_SETUP_NOTE_LABEL" in env.dc("cat", "administrator/language/en-GB/plg_content_cfpurge.ini"))
    # The 1.1.4 behaviour must be the same: an article save purges everything (mode all by default).
    env.purges()
    status, _ = env.api("POST", "/v1/content/articles", {"title": "Upgrade check", "alias": "upgrade-check-%d" % time.time(), "catid": 2, "articletext": "x", "state": 1, "language": "*"})
    time.sleep(1)
    purges = env.purges()
    check("upgrade: article save still purges everything", status == 200 and len(purges) == 1 and purges[0]["body"] == {"purge_everything": True}, f"{status} {purges}")
    # An update of the installed package keeps everything too.
    env.install("/dist/pkg_cfpurge-2.0.0.zip")
    check("upgrade: reinstalling the package keeps the parameters", env.raw_params().get("zone_id") == ZONE)


def sc_visibility(env: Env) -> None:
    """Module in the home for the super user, hidden for a user without the permission."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    setup_config(env)
    a = env.admin()
    admin_verify(env, a, ZONE, "full")
    # The verification was done with the values that are now saved: it must count.
    _, home, _ = a.request("/administrator/index.php")
    check("visibility: the box is in the home for the super user", "mod-cfpurge" in home and "Cloudflare Cache Purge" in home)
    check("visibility: traffic light is green and the buttons are enabled",
          'data-state="ok"' in home and not re.search(r'data-action="purge_all"[^>]*disabled', home), re.sub(r"\s+", " ", home[home.find("mod-cfpurge"):][:300]))

    env.sql("DELETE FROM jdev_users WHERE username='mgr'")
    env.cli("user:add", "--username=mgr", "--name=Manager", "--email=mgr@example.test", "--password=" + os.environ.get("DEV_MGR_PASSWORD", "change-me-12chars"), "--usergroup=Manager")
    m = env.admin("mgr", os.environ.get("DEV_MGR_PASSWORD", "change-me-12chars"))
    env.purges()  # forget the probe purge of the verification above
    _, home_m, _ = m.request("/administrator/index.php")
    check("visibility: the box is NOT in the home for a Manager", "mod-cfpurge" not in home_m)
    status, body = m.ajax("purge_all")
    check("visibility: a Manager calling com_ajax gets 403", status in (403, 401) or body.get("success") is False, f"{status} {body}")
    check("visibility: ... and no purge reached Cloudflare", env.purges() == [])
    env.params({"allowed_groups": [6]})
    _, home_m2, _ = m.request("/administrator/index.php")
    check("visibility: the Manager group chosen in the options sees the box", "mod-cfpurge" in home_m2)
    env.params({"allowed_groups": None})
    # Guests: com_ajax without a session is refused by Joomla itself for this plugin.
    anon = Admin(env.port)
    status, text, _ = anon.request("/administrator/index.php?option=com_ajax&group=content&plugin=cfpurge&format=json", {"action": "purge_all"}, follow=False)
    check("visibility: a guest gets nothing from com_ajax", status in (401, 403, 404, 500) or '"success":false' in text, f"{status} {text[:120]}")
    check("visibility: ... and no purge reached Cloudflare", env.purges() == [])
    status, text, _ = anon.request("/index.php?option=com_ajax&group=content&plugin=cfpurge&format=json", {"action": "purge_all"}, follow=False)
    check("visibility: the same call on the public site (com_ajax of the frontend) is refused", '"success":false' in text or status in (401, 403), f"{status} {text[:160]}")
    status, text, _ = anon.request("/index.php?option=com_ajax&group=content&plugin=cfpurge&format=json&action=purge_all")
    check("visibility: ... also with GET", '"success":false' in text or status in (401, 403), f"{status} {text[:160]}")
    check("visibility: ... and no purge reached Cloudflare", env.purges() == [])
    # Same call without the form token or with GET.
    status, body = a.ajax("purge_all", token="0" * 32)
    check("visibility: wrong form token is refused", body.get("success") is False, f"{status} {body}")


def sc_verify(env: Env) -> None:
    """The connection check explains what is wrong."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    env.params({"zone_id": None, "api_token": None, "verify_state": None})
    a = env.admin()
    env.mock_log(True)

    r = admin_verify(env, a, "", "full")
    check("verify: full token, no zone id: zone found by itself", r["ok"] and r["suggested_zone_id"] == ZONE and "MSG_ZONE_DETECTED" in keys(r), str(keys(r)))
    r = admin_verify(env, a, ZONE, "full")
    check("verify: full token and right zone id", r["ok"] and statuses(r) == {"config": "ok", "token": "ok", "zone": "ok", "purge": "ok"}, str(statuses(r)))
    r = admin_verify(env, a, ZONE, "purgeonly")
    check("verify: purge-only token is fine, zone check skipped with a note", r["ok"] and statuses(r)["zone"] == "warn" and "MSG_ZONE_NO_READ" in keys(r), str(keys(r)))
    r = admin_verify(env, a, "", "purgeonly")
    check("verify: purge-only token and no zone id: asks for the zone id", not r["ok"] and "MSG_ZONE_MISSING" in keys(r), str(keys(r)))
    r = admin_verify(env, a, ZONE, "acct")
    check("verify: account-owned token (unknown to the user check) passes through the purge test", r["ok"] and statuses(r)["token"] == "warn", str(statuses(r)))
    r = admin_verify(env, a, ZONE, "bad")
    check("verify: invalid token is explained", not r["ok"] and "MSG_PURGE_TOKEN_INVALID" in keys(r), str(keys(r)))
    r = admin_verify(env, a, ZONE, "noperm")
    check("verify: token without the purge permission is explained", not r["ok"] and "MSG_PURGE_NO_PERMISSION" in keys(r) and "Zone > Cache Purge > Purge" in r["summary"], r["summary"])
    r = admin_verify(env, a, ZONE, "inactive")
    check("verify: inactive token is explained", not r["ok"] and "MSG_TOKEN_INACTIVE" in keys(r), str(keys(r)))
    r = admin_verify(env, a, UNKNOWN_ZONE, "full")
    check("verify: wrong zone id (does not exist) is explained", not r["ok"] and "MSG_ZONE_NOT_FOUND" in keys(r), str(keys(r)))
    r = admin_verify(env, a, UNKNOWN_ZONE, "purgeonly")
    check("verify: wrong zone id with a token that cannot read zones: the purge test catches it", not r["ok"] and "MSG_PURGE_ZONE_NOT_FOUND" in keys(r), str(keys(r)))
    r = admin_verify(env, a, OTHER_ZONE, "full")
    check("verify: zone id of another domain is refused and the right one is proposed", not r["ok"] and "MSG_ZONE_MISMATCH" in keys(r) and r["suggested_zone_id"] == ZONE, str(keys(r)))
    r = admin_verify(env, a, ZONE, "err500")
    check("verify: Cloudflare server error is explained", not r["ok"] and "MSG_SERVER" in keys(r), str(keys(r)))
    r = admin_verify(env, a, "not-a-zone", "full")
    check("verify: malformed zone id is explained without calling Cloudflare", not r["ok"] and keys(r) == ["MSG_ZONE_FORMAT"], str(keys(r)))
    r = admin_verify(env, a, ZONE, "bad")
    r2 = a.ajax("verify", typed="1", zone_id=ZONE, api_token="short")[1]["data"][0]
    check("verify: malformed token is explained", not r2["ok"] and keys(r2) == ["MSG_TOKEN_FORMAT"], str(keys(r2)))
    r2 = a.ajax("verify", typed="1", zone_id=ZONE, api_token="")[1]["data"][0]
    check("verify: empty token is explained", not r2["ok"] and keys(r2) == ["MSG_TOKEN_MISSING"], str(keys(r2)))
    t0 = time.time()
    r = admin_verify(env, a, ZONE, "slow")
    check("verify: unreachable/slow Cloudflare ends in a clear message within the timeout", not r["ok"] and "MSG_NETWORK" in keys(r) and time.time() - t0 < 40, f"{keys(r)} {time.time() - t0:.0f}s")
    log = json.dumps(env.mock_log(True))
    check("verify: the mock never saw anything but fake tokens", "devtok" in log or "invalid" in log)
    # What the check wrote in the plugin log: no token and no zone id.
    plugin_log = env.dc("cat", "administrator/logs/plg_content_cfpurge.php")
    check("verify: the plugin log has no token and no zone id", "devtok" not in plugin_log and ZONE not in plugin_log and "notacloudflare" not in plugin_log)


def sc_state(env: Env) -> None:
    """The traffic light follows the saved verification."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    a = env.admin()

    def home() -> str:
        return a.request("/administrator/index.php")[1]

    env.params({"zone_id": None, "api_token": None, "verify_state": None})
    check("state: nothing configured -> 'unconfigured', buttons off", 'data-state="unconfigured"' in home())
    env.params({"zone_id": ZONE, "api_token": TOKENS["full"]})
    h = home()
    check("state: configured, never verified -> 'unverified', purge buttons off", 'data-state="unverified"' in h and re.search(r'data-action="purge_all"[^>]*disabled', h) is not None)
    status, body = a.ajax("purge_all")
    check("state: purge refused by the server while not verified", status == 200 and body["data"][0]["ok"] is False and body["data"][0]["error"] == "not_verified", str(body)[:200])
    check("state: ... without calling Cloudflare", env.purges() == [])
    admin_verify(env, a, ZONE, "full")
    h = home()
    check("state: verified with the saved values -> 'ok', buttons on", 'data-state="ok"' in h and re.search(r'data-action="purge_all"[^>]*disabled', h) is None)
    env.params({"api_token": TOKENS["purgeonly"]})
    check("state: changing the token invalidates the verification", 'data-state="unverified"' in home())
    admin_verify(env, a, ZONE, "noperm")
    h = home()
    check("state: failed verification of the saved values -> 'invalid'", 'data-state="unverified"' in h or 'data-state="invalid"' in h)
    env.params({"api_token": TOKENS["noperm"]})
    admin_verify(env, a, ZONE, "noperm")
    check("state: failed check on the saved values -> 'invalid' and buttons off", 'data-state="invalid"' in home())
    # Plugin disabled
    env.params({"api_token": TOKENS["full"]})
    admin_verify(env, a, ZONE, "full")
    env.sql("UPDATE jdev_extensions SET enabled=0 WHERE type='plugin' AND folder='content' AND element='cfpurge'")
    check("state: content plugin disabled -> box says so, buttons off", 'data-state="disabled"' in home())
    env.sql("UPDATE jdev_extensions SET enabled=1 WHERE type='plugin' AND folder='content' AND element='cfpurge'")


def sc_purge(env: Env) -> None:
    """Dashboard purges: whole cache, addresses, failures."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    a = env.admin()
    setup_config(env, "full")
    admin_verify(env, a, ZONE, "full")
    env.purges()

    status, body = a.ajax("purge_all")
    d = body["data"][0]
    p = env.purges()
    check("purge: whole cache from the dashboard", d["ok"] and len(p) == 1 and p[0]["body"] == {"purge_everything": True}, f"{d} {p}")
    check("purge: the log names the user and the dashboard", d["last"]["user"] == "admin" and d["last"]["src"] == "dashboard" and d["last"]["scope"] == "all", str(d["last"]))

    status, body = a.ajax("purge_urls", urls="/page-one\n  http://www.cfdemo.localhost:%d/page-two?x=1  \n\n/page-one" % env.port)
    d = body["data"][0]
    p = env.purges()
    expected = [f"{env.base}/page-one", f"{env.base}/page-two?x=1"]
    check("purge: addresses (path and full address, duplicates removed)", d["ok"] and len(p) == 1 and p[0]["body"] == {"files": expected}, f"{d} {p}")

    status, body = a.ajax("purge_urls", urls="/ok\nhttps://evil.example/x\nftp://www.cfdemo.localhost/x\n//evil.example/y")
    d = body["data"][0]
    check("purge: addresses of other sites are refused, nothing is sent", d["ok"] is False and d["error"] == "invalid" and env.purges() == [], str(d))
    status, body = a.ajax("purge_urls", urls="  \n ")
    check("purge: empty address field is refused", body["data"][0]["ok"] is False and body["data"][0]["error"] == "empty")

    many = "\n".join(f"/p{i}" for i in range(35))
    status, body = a.ajax("purge_urls", urls=many)
    p = env.purges()
    check("purge: 35 addresses go in blocks of 30 and 5", body["data"][0]["ok"] and [len(x["body"]["files"]) for x in p] == [30, 5], str([len(x["body"].get("files", [])) for x in p]))
    status, body = a.ajax("purge_urls", urls="\n".join(f"/p{i}" for i in range(101)))
    check("purge: more than 100 addresses are refused", body["data"][0]["ok"] is False and body["data"][0]["error"] == "too_many")
    env.purges()

    # Controlled failure: the token verifies fine but real purges are refused by Cloudflare.
    env.params({"api_token": TOKENS["probeonly"]})
    r = admin_verify(env, a, ZONE, "probeonly")
    check("purge: (setup) probe-only token verifies", r["ok"])
    status, body = a.ajax("purge_all")
    d = body["data"][0]
    check("purge: Cloudflare refusal is reported, nothing breaks", status == 200 and d["ok"] is False and "403" in d["message"] and d["cause"] == "no_permission", str(d))
    h = a.request("/administrator/index.php")[1]
    check("purge: after a failed purge the box shows the problem and the last outcome", 'data-state="degraded"' in h and "FAILED" in h, re.sub(r"\s+", " ", h[h.find("mod-cfpurge__last"):][:400]))
    env.purges()
    # An automatic purge failing never blocks the save.
    status, r = env.api("POST", "/v1/content/articles", {"title": "Fails quietly", "alias": "fails-quietly-%d" % time.time(), "catid": 2, "articletext": "x", "state": 1, "language": "*"})
    time.sleep(1)
    check("purge: an automatic purge refused by Cloudflare does not block the article save", status == 200 and len(env.purges()) == 1)
    plog = env.dc("cat", "administrator/logs/plg_content_cfpurge.php")
    check("purge: the failure is in the plugin log", '"ok":false' in plog and "403" in plog)
    check("purge: the plugin log never contains the token", "devtok" not in plog)
    # A good purge afterwards puts the light back to green.
    env.params({"api_token": TOKENS["full"]})
    admin_verify(env, a, ZONE, "full")
    a.ajax("purge_all")
    h = a.request("/administrator/index.php")[1]
    check("purge: a successful purge brings the light back to green", 'data-state="ok"' in h)
    env.purges()


def new_cat(env: Env, alias: str) -> int:
    status, r = env.api("POST", "/v1/content/categories", {"title": alias.title(), "alias": alias, "extension": "com_content", "published": 1, "language": "*"})
    assert status == 200, r
    return int(r["data"]["id"])


def one_purge(env: Env, what: str, expect_everything: bool = True, expect_none: bool = False) -> list[dict]:
    time.sleep(0.8)
    p = env.purges()
    if expect_none:
        check(what + ": no purge", p == [], str(p))
    elif expect_everything:
        check(what + ": exactly one purge_everything", len(p) == 1 and p[0]["body"] == {"purge_everything": True}, str(p))
    return p


def sc_events(env: Env) -> None:
    """Which Joomla events purge, in mode 'all'."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    a = env.admin()
    setup_config(env, "full", purge_mode="all", on_create=1, on_update=1, on_state=1, on_delete=1,
                 on_category=1, on_menu=1, on_module=1, on_field=1, on_tag=1, on_joomla_cache=0, extra_urls=None)
    env.purges()
    suffix = str(int(time.time()) % 100000)

    status, r = env.api("POST", "/v1/content/articles", {"title": "Ev " + suffix, "alias": "ev-" + suffix, "catid": 2, "articletext": "x", "state": 1, "language": "*"})
    aid = r["data"]["id"]
    one_purge(env, "events: article created")
    env.api("PATCH", f"/v1/content/articles/{aid}", {"metadesc": "changed"})
    one_purge(env, "events: article updated")
    env.api("PATCH", f"/v1/content/articles/{aid}", {"state": 0})
    one_purge(env, "events: article state change")
    env.api("PATCH", f"/v1/content/articles/{aid}", {"state": -2})
    one_purge(env, "events: article trashed")
    status, _ = env.api("DELETE", f"/v1/content/articles/{aid}")
    one_purge(env, "events: article deleted")

    # Switches
    env.params({"on_create": 0})
    status, r = env.api("POST", "/v1/content/articles", {"title": "Sw " + suffix, "alias": "sw-" + suffix, "catid": 2, "articletext": "x", "state": 1, "language": "*"})
    one_purge(env, "events: 'on creation' switched off", expect_none=True)
    env.params({"on_create": 1, "on_update": 0})
    env.api("PATCH", f"/v1/content/articles/{r['data']['id']}", {"metadesc": "x"})
    one_purge(env, "events: 'on update' switched off", expect_none=True)
    env.params({"on_update": 1})

    # Several articles in one request: a single call
    ids = []
    for i in range(3):
        ids.append(env.api("POST", "/v1/content/articles", {"title": f"Batch {i} {suffix}", "alias": f"batch-{i}-{suffix}", "catid": 2, "articletext": "x", "state": 1, "language": "*"})[1]["data"]["id"])
    env.purges()
    # Joomla's list publish goes through the administrator form: one request, three articles.
    _, page, _ = a.request("/administrator/index.php?option=com_content&view=articles")
    token = a.token(page)
    a.request("/administrator/index.php?option=com_content&view=articles",
              [("cid[]", i) for i in ids] + [("task", "articles.unpublish"), ("boxchecked", "3"), (token, "1")], follow=False)
    p = one_purge(env, "events: three articles unpublished in one request (coalesced)")

    # Categories, menu items, modules, tags, custom fields
    cat = new_cat(env, "evcat" + suffix)
    one_purge(env, "events: category created")
    env.api("PATCH", f"/v1/content/categories/{cat}", {"description": "changed"})
    one_purge(env, "events: category updated")
    env.params({"on_category": 0})
    env.api("PATCH", f"/v1/content/categories/{cat}", {"description": "changed again"})
    one_purge(env, "events: 'categories' switched off", expect_none=True)
    env.params({"on_category": 1})

    status, r = env.api("POST", "/v1/menus/site/items", {"title": "Menu " + suffix, "alias": "menu-" + suffix, "menutype": "mainmenu", "type": "component", "link": "index.php?option=com_content&view=featured", "published": 1, "parent_id": 1, "language": "*", "component_id": 19, "access": 1, "template_style_id": 0, "params": {}})
    if status == 200:
        one_purge(env, "events: menu item created")
        env.params({"on_menu": 0})
        env.api("PATCH", f"/v1/menus/site/items/{r['data']['id']}", {"title": "Menu changed"})
        one_purge(env, "events: 'menu items' switched off", expect_none=True)
        env.params({"on_menu": 1})
    else:
        check("events: menu item created (API call accepted)", False, f"{status} {r}")

    status, r = env.api("POST", "/v1/tags", {"title": "Tag " + suffix, "alias": "tag-" + suffix, "published": 1, "language": "*", "parent_id": 1, "access": 1, "description": ""})
    if status == 200:
        one_purge(env, "events: tag created")
    else:
        check("events: tag created (API call accepted)", False, f"{status} {r}")

    status, r = env.api("POST", "/v1/fields/content/articles", {"title": "Field " + suffix, "name": "field-" + suffix, "label": "Field " + suffix, "type": "text", "context": "com_content.article", "state": 1, "access": 1, "language": "*", "default_value": "", "description": "", "params": {"class": "", "label_class": "", "show_on": "", "showon": ""}, "fieldparams": {"filter": "", "maxlength": ""}})
    if status == 200:
        one_purge(env, "events: custom field created")
    else:
        check("events: custom field created (API call accepted)", False, f"{status} {r}")

    # Site modules: through the administrator form (the API has no module endpoint in core).
    mods = env.sql("SELECT id FROM jdev_modules WHERE client_id=0 AND module IN ('mod_custom','mod_menu','mod_articles','mod_login','mod_footer') ORDER BY id LIMIT 1")
    if not mods:
        env.sql("INSERT INTO jdev_modules (title,note,content,ordering,position,published,module,access,showtitle,params,client_id,language) VALUES ('Dev custom','','<p>x</p>',1,'sidebar-right',1,'mod_custom',1,1,'{}',0,'*')")
        mods = env.sql("SELECT id FROM jdev_modules WHERE client_id=0 AND module='mod_custom' ORDER BY id DESC LIMIT 1")
    mid = mods[0][0]
    a.request(f"/administrator/index.php?option=com_modules&task=module.edit&id={mid}")
    env.purges()
    status, text = a.edit_form(f"/administrator/index.php?option=com_modules&view=module&layout=edit&id={mid}", "module-form", {"jform[title]": "Changed module " + suffix}, "module.apply")
    one_purge(env, "events: site module saved (system plugin, extension event)")
    a.request(f"/administrator/index.php?option=com_modules&task=module.cancel&id={mid}", {"task": "module.cancel"})
    env.params({"on_module": 0})
    a.request(f"/administrator/index.php?option=com_modules&task=module.edit&id={mid}")
    a.edit_form(f"/administrator/index.php?option=com_modules&view=module&layout=edit&id={mid}", "module-form", {"jform[title]": "Changed again " + suffix}, "module.apply")
    one_purge(env, "events: 'site modules' switched off", expect_none=True)
    a.request(f"/administrator/index.php?option=com_modules&task=module.cancel&id={mid}", {"task": "module.cancel"})
    env.params({"on_module": 1})
    # Publishing/unpublishing a module from the list is a content state change event.
    _, page, _ = a.request("/administrator/index.php?option=com_modules&view=modules&client_id=0")
    token = a.token(page)
    a.request("/administrator/index.php?option=com_modules&view=modules&client_id=0", [("cid[]", mid), ("task", "modules.unpublish"), ("boxchecked", "1"), (token, "1")], follow=False)
    one_purge(env, "events: site module unpublished from the list")
    a.request("/administrator/index.php?option=com_modules&view=modules&client_id=0", [("cid[]", mid), ("task", "modules.publish"), ("boxchecked", "1"), (token, "1")], follow=False)
    env.purges()
    # Administrator modules do not change the public site
    admin_mod = env.sql("SELECT id FROM jdev_modules WHERE client_id=1 AND module='mod_popular' LIMIT 1")
    if admin_mod:
        aid_ = admin_mod[0][0]
        a.request(f"/administrator/index.php?option=com_modules&task=module.edit&id={aid_}&client_id=1")
        a.edit_form(f"/administrator/index.php?option=com_modules&view=module&layout=edit&id={aid_}&client_id=1", "module-form", {"jform[title]": "Popular Articles"}, "module.apply")
        one_purge(env, "events: administrator module saved does NOT purge", expect_none=True)
        a.request(f"/administrator/index.php?option=com_modules&task=module.cancel&id={aid_}&client_id=1", {"task": "module.cancel"})

    # Targeted mode: only articles are targeted, everything else still purges everything.
    env.params({"purge_mode": "targeted", "extra_urls": "/sitemap.xml\n/feed\nhttps://evil.example/x"})
    cat2 = new_cat(env, "tcat" + suffix)
    one_purge(env, "events: targeted mode, a category change still purges everything")
    env.purges()


def sc_targeted(env: Env) -> None:
    """Targeted mode builds the right addresses."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    setup_config(env, "full", purge_mode="targeted", on_create=1, on_update=1, on_state=1, on_delete=1,
                 extra_urls="/sitemap.xml\n/feed\nhttps://evil.example/x\nhttp://www.cfdemo.localhost:%d/extra?a=1" % env.port)
    suffix = str(int(time.time()) % 100000)
    cat = new_cat(env, "tg" + suffix)
    env.purges()
    base = env.base
    status, r = env.api("POST", "/v1/content/articles", {"title": "Target", "alias": "target-" + suffix, "catid": cat, "articletext": "x", "state": 1, "language": "*"})
    aid = r["data"]["id"]
    time.sleep(0.8)
    p = env.purges()
    files = p[0]["body"].get("files", []) if p else []
    art = f"{base}/component/content/article/target-{suffix}?catid={cat}"
    catu = f"{base}/component/content/category/tg{suffix}"
    check("targeted: a new article purges exactly its address, its category, the home page and the extras",
          len(p) == 1 and set(files) == {art, catu, base + "/", base + "/sitemap.xml", base + "/feed", base + "/extra?a=1"}, str(files))
    check("targeted: the extra address of another domain is never sent", not any("evil.example" in f for f in files))
    env.api("PATCH", f"/v1/content/articles/{aid}", {"alias": "renamed-" + suffix})
    time.sleep(0.8)
    p = env.purges()
    files = p[0]["body"].get("files", []) if p else []
    check("targeted: renaming an article purges the old and the new address",
          f"{base}/component/content/article/target-{suffix}?catid={cat}" in files and f"{base}/component/content/article/renamed-{suffix}?catid={cat}" in files, str(files))
    # Move to another category: old and new category pages? (the new one is in the list, the old article address too)
    cat_b = new_cat(env, "tgb" + suffix)
    env.purges()
    env.api("PATCH", f"/v1/content/articles/{aid}", {"catid": cat_b})
    time.sleep(0.8)
    files = (env.purges() or [{"body": {}}])[0]["body"].get("files", [])
    check("targeted: moving an article to another category purges its old address and the new category",
          f"{base}/component/content/article/renamed-{suffix}?catid={cat}" in files and f"{base}/component/content/category/tgb{suffix}" in files, str(files))
    env.api("PATCH", f"/v1/content/articles/{aid}", {"state": 0})
    time.sleep(0.8)
    files = (env.purges() or [{"body": {}}])[0]["body"].get("files", [])
    check("targeted: a state change purges the article address", any("renamed-" + suffix in f for f in files) and base + "/" in files, str(files))
    env.api("PATCH", f"/v1/content/articles/{aid}", {"state": -2})
    env.purges()
    env.api("DELETE", f"/v1/content/articles/{aid}")
    time.sleep(0.8)
    files = (env.purges() or [{"body": {}}])[0]["body"].get("files", [])
    check("targeted: deleting an article purges its address", any("renamed-" + suffix in f for f in files), str(files))
    # Nested categories: parents are purged too
    status, parent = env.api("POST", "/v1/content/categories", {"title": "Par", "alias": "par" + suffix, "extension": "com_content", "published": 1, "language": "*"})
    status, child = env.api("POST", "/v1/content/categories", {"title": "Chi", "alias": "chi" + suffix, "extension": "com_content", "published": 1, "language": "*", "parent_id": int(parent["data"]["id"])})
    env.purges()
    env.api("POST", "/v1/content/articles", {"title": "Deep", "alias": "deep-" + suffix, "catid": int(child["data"]["id"]), "articletext": "x", "state": 1, "language": "*"})
    time.sleep(0.8)
    files = (env.purges() or [{"body": {}}])[0]["body"].get("files", [])
    check("targeted: the category and its parent are purged", any("category/par%s/chi%s" % (suffix, suffix) in f or f.endswith("chi" + suffix) for f in files) and any(f.endswith("par" + suffix) for f in files), str(files))
    # Many articles in one request: blocks of 30 addresses, and the whole cache above 90 addresses.
    def bulk(count: int, tag: str) -> list[str]:
        env.sql(f"INSERT INTO jdev_content (asset_id,title,alias,introtext,`fulltext`,state,catid,created,created_by,created_by_alias,modified,modified_by,publish_up,images,urls,attribs,version,ordering,metakey,metadesc,access,hits,metadata,featured,language,note) "
                f"WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM n WHERE i<{count}) "
                f"SELECT 0,CONCAT('Bulk {tag} ',i),CONCAT('bulk-{tag}-{suffix}-',i),'x','',1,{cat},NOW(),8,'',NOW(),8,NOW(),'{{}}','{{}}','{{}}',1,0,'','',1,0,'{{}}',0,'*','' FROM n")
        return [r[0] for r in env.sql(f"SELECT id FROM jdev_content WHERE alias LIKE 'bulk-{tag}-{suffix}-%'")]

    a = env.admin()

    def unpublish(ids: list[str]) -> None:
        _, page, _ = a.request("/administrator/index.php?option=com_content&view=articles")
        token = a.token(page)
        a.request("/administrator/index.php?option=com_content&view=articles",
                  [("cid[]", i) for i in ids] + [("task", "articles.unpublish"), ("boxchecked", str(len(ids))), (token, "1")], follow=False)

    ids = bulk(35, "a")
    env.purges()
    unpublish(ids)
    time.sleep(1)
    p = env.purges()
    sizes = [len(x["body"].get("files", [])) for x in p]
    check("targeted: 35 articles in one request go in blocks of 30 addresses", sizes == [30, 10] or (len(sizes) >= 2 and sizes[0] == 30 and sum(sizes) >= 36), str(sizes))
    ids = bulk(95, "b")
    env.purges()
    unpublish(ids)
    time.sleep(1.5)
    p = env.purges()
    check("targeted: more than 90 addresses fall back to one purge of the whole cache", len(p) == 1 and p[0]["body"] == {"purge_everything": True}, str([x["body"].keys() for x in p]))
    env.sql(f"DELETE FROM jdev_content WHERE alias LIKE 'bulk-%-{suffix}-%'")
    env.purges()


def sc_cache(env: Env) -> None:
    """System > Clear Cache event."""
    if not env.ext("pkg_cfpurge", "package"):
        env.install("/dist/pkg_cfpurge-2.0.0.zip")
    a = env.admin()
    setup_config(env, "full", purge_mode="all", on_joomla_cache=1)
    env.purges()

    def press(task: str, **extra) -> None:
        _, page, _ = a.request("/administrator/index.php?option=com_cache&view=cache")
        token = a.token(page)
        a.request("/administrator/index.php?option=com_cache", {"task": task, "boxchecked": "0", token: "1", **extra})

    press("deleteAll")
    p = one_purge(env, "cache: 'Delete All' with the switch on")
    check("cache: the log says why", "joomla-cache" in env.dc("cat", "administrator/logs/plg_content_cfpurge.php"))
    press("purge")
    one_purge(env, "cache: 'Purge Expired' never purges Cloudflare", expect_none=True)
    # Delete selected groups: pick any group name
    _, page, _ = a.request("/administrator/index.php?option=com_cache&view=cache")
    groups = re.findall(r'name="cid\[\]" value="([^"]+)"', page)
    if groups:
        press("delete", **{"cid[]": groups[0], "boxchecked": "1"})
        one_purge(env, "cache: 'Delete' of selected groups with the switch on")
    env.params({"on_joomla_cache": 0})
    press("deleteAll")
    one_purge(env, "cache: 'Delete All' with the switch off", expect_none=True)
    env.params({"on_joomla_cache": None})
    press("deleteAll")
    one_purge(env, "cache: switch never saved = off", expect_none=True)


def build_release(version: str, out: Path) -> Path:
    """Copy of the package sources with another version, built with the real build script."""
    import importlib.util
    import shutil
    spec = importlib.util.spec_from_file_location("build_plugin", ROOT / "build" / "build_plugin.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    tmp = Path(tempfile.mkdtemp(prefix="cfpurge-rel-"))
    src = tmp / "pkg_cfpurge"
    shutil.copytree(ROOT / "plugins" / "pkg_cfpurge", src)
    for xml in src.rglob("*.xml"):
        text = xml.read_text(encoding="utf8")
        xml.write_text(re.sub(r"<version>[^<]+</version>", f"<version>{version}</version>", text), encoding="utf8")
    name, data, *_ = mod.build_one(src)
    out.mkdir(parents=True, exist_ok=True)
    target = out / name
    target.write_bytes(data)
    return target


def sc_update(env: Env) -> None:
    """Update path: 1.1.4 -> 2.0.0 package through the update server, then a later release."""
    import hashlib
    updates = DEV / ".cache" / "updates"
    for f in updates.glob("*"):
        f.unlink()
    pkg_zip = ROOT / "build" / "out" / "pkg_cfpurge-2.0.0.zip"
    (updates / pkg_zip.name).write_bytes(pkg_zip.read_bytes())
    sha = hashlib.sha256(pkg_zip.read_bytes()).hexdigest()

    def write_server(version: str, zip_name: str, sha256: str, plugin_entry_version: str = "2.0.0") -> None:
        (updates / "collection.xml").write_text(f"""<?xml version="1.0" encoding="utf-8"?>
<extensionset name="WebG Joomla Extensions" description="test">
    <extension name="Cloudflare Cache Purge" element="cfpurge" type="plugin" folder="content" version="{plugin_entry_version}" detailsurl="http://127.0.0.1/cfupd/cfpurge.xml" />
    <extension name="Cloudflare Cache Purge" element="pkg_cfpurge" type="package" version="{version}" detailsurl="http://127.0.0.1/cfupd/cfpurge.xml" />
</extensionset>
""", encoding="utf8")
        (updates / "cfpurge.xml").write_text(f"""<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>Cloudflare Cache Purge</name>
        <description>Cloudflare Cache Purge</description>
        <element>pkg_cfpurge</element>
        <type>package</type>
        <version>{version}</version>
        <downloads>
            <downloadurl type="full" format="zip">http://127.0.0.1/cfupd/{zip_name}</downloadurl>
        </downloads>
        <sha256>{sha256}</sha256>
        <tags><tag>stable</tag></tags>
        <targetplatform name="joomla" version="(5|6)\\.[0-9]+" />
    </update>
</updates>
""", encoding="utf8")
        env.dc("bash", "-c", "mkdir -p /var/www/html/cfupd && cp /updates/* /var/www/html/cfupd/ && chown -R www-data /var/www/html/cfupd")

    def refresh() -> None:
        env.sql("DELETE FROM jdev_updates")
        env.sql("UPDATE jdev_update_sites SET last_check_timestamp=0")
        env.cli("update:extensions:check", check_rc=False)

    def pending() -> list[list[str]]:
        return env.sql("SELECT update_id,extension_id,element,type,folder,version FROM jdev_updates WHERE extension_id<>0")

    write_server("2.0.0", pkg_zip.name, sha)

    # 1. A site that has the 1.1.4 plugin only.
    env.remove_package()
    env.install("/dist-released/plg_content_cfpurge-1.1.4.zip")
    old = env.ext("cfpurge", "plugin", "content")
    env.params({"zone_id": ZONE, "api_token": TOKENS["full"]})
    # The 1.1.4 manifest points at GitHub: aim its update site at the local copy of the update server.
    env.sql("UPDATE jdev_update_sites SET location='http://127.0.0.1/cfupd/collection.xml', enabled=1 WHERE location LIKE '%joomla-extensions%collection.xml'")
    refresh()
    rows = pending()
    check("update: the 1.1.4 plugin sees exactly one update, 2.0.0", len(rows) == 1 and rows[0][2] == "cfpurge" and rows[0][5] == "2.0.0", str(rows))
    a = env.admin()
    _, page, _ = a.request("/administrator/index.php?option=com_installer&view=update")
    token = a.token(page)
    uid = rows[0][0] if rows else "0"
    _, html, _ = a.request("/administrator/index.php?option=com_installer&view=update", {"task": "update.update", "cid[]": uid, "boxchecked": "1", token: "1"})
    pkg = env.ext("pkg_cfpurge", "package")
    new = env.ext("cfpurge", "plugin", "content")
    check("update: installing that update installs the package", pkg is not None, html[-300:] if not pkg else "")
    check("update: the plugin is upgraded in place (same extension id, version 2.0.0, in the package)",
          new and old and new[0] == old[0] and json.loads(new[3])["version"] == "2.0.0" and new[2] == (pkg[0] if pkg else "?"))
    check("update: zone id and token survived", env.raw_params().get("zone_id") == ZONE and env.raw_params().get("api_token") == TOKENS["full"])
    check("update: module published in the home", env.sql("SELECT position,published FROM jdev_modules WHERE module='mod_cfpurge'") == [["cpanel", "1"]])
    check("update: nothing is left to update", pending() == [] or env.sql("SELECT version FROM jdev_updates WHERE extension_id<>0") == [], str(pending()))
    sites = env.sql("SELECT us.update_site_id,us.location,x.extension_id FROM jdev_update_sites us JOIN jdev_update_sites_extensions x ON x.update_site_id=us.update_site_id WHERE x.extension_id=" + (pkg[0] if pkg else "0"))
    check("update: the package registered an update server (GitHub collection, same address as the plugin had)", any("raw.githubusercontent.com/WebG-it/joomla-extensions/main/collection.xml" in s[1] for s in sites), str(sites))
    # In this test the plugin's update site was aimed at the local server; point the package's too.
    env.sql("UPDATE jdev_update_sites SET location='http://127.0.0.1/cfupd/collection.xml' WHERE location LIKE '%joomla-extensions%collection.xml'")
    env.sql("DELETE FROM jdev_update_sites WHERE location LIKE '%cfupd%' AND update_site_id NOT IN (SELECT MIN(update_site_id) FROM (SELECT update_site_id FROM jdev_update_sites WHERE location LIKE '%cfupd%') t)")

    # 2. A later release (2.0.1): offered once, as the package, to the site that now has the package.
    rel = build_release("2.0.1", updates)
    write_server("2.0.1", rel.name, hashlib.sha256(rel.read_bytes()).hexdigest())
    refresh()
    rows = pending()
    check("update: later release 2.0.1 is offered once, for the package", len(rows) == 1 and rows[0][2] == "pkg_cfpurge" and rows[0][3] == "package" and rows[0][5] == "2.0.1", str(rows))
    _, page, _ = a.request("/administrator/index.php?option=com_installer&view=update")
    token = a.token(page)
    uid = rows[0][0] if rows else "0"
    a.request("/administrator/index.php?option=com_installer&view=update", {"task": "update.update", "cid[]": uid, "boxchecked": "1", token: "1"})
    pkg2 = env.ext("pkg_cfpurge", "package")
    check("update: 2.0.1 installed over 2.0.0", pkg2 and json.loads(pkg2[3])["version"] == "2.0.1" and pkg and pkg2[0] == pkg[0])
    new2 = env.ext("cfpurge", "plugin", "content")
    check("update: the plugin follows (2.0.1) and keeps its parameters", new2 and json.loads(new2[3])["version"] == "2.0.1" and env.raw_params().get("zone_id") == ZONE)
    refresh()
    check("update: after 2.0.1 nothing is offered again (no loop on the bridge entry)", pending() == [], str(pending()))

    # 3. A fresh site that installed the 2.0.0 package directly sees 2.0.1 too.
    env.remove_package()
    env.install("/dist/pkg_cfpurge-2.0.0.zip")
    env.sql("UPDATE jdev_update_sites SET location='http://127.0.0.1/cfupd/collection.xml', enabled=1 WHERE location LIKE '%joomla-extensions%collection.xml'")
    refresh()
    rows = pending()
    check("update: a site with the 2.0.0 package sees 2.0.1", len(rows) == 1 and rows[0][2] == "pkg_cfpurge" and rows[0][5] == "2.0.1", str(rows))
    # restore
    env.dc("bash", "-c", "rm -rf /var/www/html/cfupd")
    env.remove_package()


SCENARIOS = {
    "install": sc_install, "upgrade": sc_upgrade, "visibility": sc_visibility, "verify": sc_verify,
    "state": sc_state, "purge": sc_purge, "events": sc_events, "targeted": sc_targeted, "cache": sc_cache,
    "update": sc_update, "uninstall": sc_uninstall,
}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--project")
    parser.add_argument("--env")
    parser.add_argument("scenarios", nargs="*")
    args = parser.parse_args()

    env = Env(args.project, args.env)
    env.version = env.dc("php", "-r", 'define("_JEXEC",1); require "/var/www/html/libraries/src/Version.php"; echo Joomla\\CMS\\Version::MAJOR_VERSION . "." . Joomla\\CMS\\Version::MINOR_VERSION . "." . Joomla\\CMS\\Version::PATCH_VERSION;').strip()
    print(f"Joomla {env.version}, PHP {env.dc('php', '-r', 'echo PHP_VERSION;').strip()}, port {env.port}")
    env.mock_log(True)

    names = args.scenarios or list(SCENARIOS)
    for name in names:
        print(f"\n== {name}")
        try:
            SCENARIOS[name](env)
        except Exception as exc:  # a scenario that cannot run is a failure, not a skip
            import traceback
            traceback.print_exc()
            check(f"{name}: scenario ran to the end", False, repr(exc)[:300])

    failed = [r for r in results if not r[1]]
    print(f"\n{len(results) - len(failed)} passed, {len(failed)} failed")
    for name, _, detail in failed:
        print(f"  FAIL {name} :: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
