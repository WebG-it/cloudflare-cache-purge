#!/usr/bin/env python3
"""Dev-only HTTP client for the local Joomla test site (run on the host, not in the container).

Logs in to the administrator like a browser, keeps the session cookie, and talks to
com_ajax the way the plugin buttons do. The site answers on 127.0.0.1:<port> but believes it is
www.cfdemo.localhost:<port> (live_site), so the Host header is set and redirects are followed by hand.

    from admin_client import Admin
    a = Admin(8790); a.login('admin', password)
    print(a.ajax(action='verify', typed='1', zone_id='...', api_token='...'))

Passwords come from the caller (read from .env), never from this file.
"""

import html.parser
import http.client
import http.cookiejar
import json
import re
import urllib.parse
import urllib.request


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class _FormReader(html.parser.HTMLParser):
    """Collects the fields of one <form> the way a browser would submit them."""

    def __init__(self, form_id: str):
        super().__init__()
        self.form_id = form_id
        self.inside = False
        self.fields: list[tuple[str, str]] = []
        self._select = None
        self._textarea = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.inside = attrs.get("id") == self.form_id
        if not self.inside:
            return
        name = attrs.get("name")
        if tag == "input" and name and "disabled" not in attrs:
            kind = attrs.get("type", "text").lower()
            if kind in ("checkbox", "radio"):
                if "checked" in attrs:
                    self.fields.append((name, attrs.get("value", "on")))
            elif kind not in ("submit", "button", "file", "image"):
                self.fields.append((name, attrs.get("value", "")))
        elif tag == "select" and name:
            self._select = [name, None, None]
        elif tag == "option" and self._select is not None:
            value = attrs.get("value", "")
            if self._select[2] is None:
                self._select[2] = value  # first option, used if none is selected
            if "selected" in attrs:
                self._select[1] = value
        elif tag == "textarea" and name:
            self._textarea = [name, ""]

    def handle_data(self, data):
        if self._textarea is not None:
            self._textarea[1] += data

    def handle_endtag(self, tag):
        if tag == "select" and self._select is not None:
            name, selected, first = self._select
            self.fields.append((name, selected if selected is not None else (first or "")))
            self._select = None
        elif tag == "textarea" and self._textarea is not None:
            self.fields.append((self._textarea[0], self._textarea[1]))
            self._textarea = None
        elif tag == "form":
            self.inside = False


class Admin:
    def __init__(self, port: int = 8790, host: str = "www.cfdemo.localhost"):
        self.port = port
        self.host = f"{host}:{port}"
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect())

    def _url(self, path: str) -> str:
        parsed = urllib.parse.urlparse(path)
        rest = parsed.path + (("?" + parsed.query) if parsed.query else "")
        return f"http://127.0.0.1:{self.port}{rest}"

    def request(self, path: str, data: dict | list | None = None, follow: bool = True, headers: dict | None = None):
        """Returns (status, body text, final path). Redirects are followed by hand."""
        for _ in range(8):
            body = urllib.parse.urlencode(data).encode() if data is not None else None
            req = urllib.request.Request(self._url(path), data=body, headers={"Host": self.host, **(headers or {})})
            try:
                resp = self.opener.open(req, timeout=60)
                return resp.status, resp.read().decode("utf-8", "replace"), path
            except urllib.error.HTTPError as err:
                if err.code in (301, 302, 303, 307) and follow:
                    path = err.headers["Location"]
                    data = None
                    continue
                return err.code, err.read().decode("utf-8", "replace"), path
        raise RuntimeError("too many redirects")

    def token(self, html: str) -> str:
        found = re.search(r'name="([0-9a-f]{32})" value="1"', html)
        if not found:
            raise RuntimeError("no form token in the page")
        return found.group(1)

    def login(self, user: str, password: str) -> None:
        _, html, _ = self.request("/administrator/index.php")
        token = self.token(html)
        status, html, path = self.request(
            "/administrator/index.php",
            {"username": user, "passwd": password, "option": "com_login", "task": "login", "return": "aW5kZXgucGhw", token: "1"},
        )
        if "task=logout" not in html and "logout" not in html.lower():
            raise RuntimeError(f"login failed for {user} (status {status})")

    def page_token(self, path: str = "/administrator/index.php") -> str:
        """The form token of the logged-in session (the same one the page scripts use)."""
        _, html, _ = self.request(path)
        found = re.search(r'"csrf\.token":"([0-9a-f]{32})"', html)
        if found:
            return found.group(1)
        return self.token(html)

    def ajax(self, action: str, token: str | None = None, **fields) -> tuple[int, dict]:
        """POST to the plugin like the buttons do; returns (HTTP status, decoded JSON)."""
        token = token or self.page_token()
        payload = {token: "1", "action": action, **fields}
        status, text, _ = self.request("/administrator/index.php?option=com_ajax&group=content&plugin=cfpurge&format=json", payload, follow=False)
        try:
            return status, json.loads(text)
        except ValueError:
            return status, {"raw": text[:400]}

    def submit_form(self, path: str, data: dict) -> tuple[int, str]:
        """POST a form with a fresh token taken from the page at path (for article saves etc.)."""
        _, html, _ = self.request(path)
        token = self.token(html)
        status, text, _ = self.request(path, {**data, token: "1"})
        return status, text

    def edit_form(self, path: str, form_id: str, changes: dict, task: str) -> tuple[int, str]:
        """Opens an edit form, changes some fields and submits it with the given task."""
        _, page, _ = self.request(path)
        reader = _FormReader(form_id)
        reader.feed(page)
        fields = [(k, v) for k, v in reader.fields if k not in changes and k != "task"]
        fields += list(changes.items()) + [("task", task)]
        status, text, _ = self.request(path, fields, follow=False)
        return status, text
