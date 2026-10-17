# Test environment of the Cloudflare Cache Purge package

Joomla 6.2.0 (or 5.4.10) + PHP 8.4 + MariaDB 11.4 in Docker, with a **fake Cloudflare API**: the
web container resolves `api.cloudflare.com` to itself and answers from `mock/index.php`, so no
real token is ever needed or used. Complete instructions in
[docs/cfpurge/DEVELOPING.md](../../docs/cfpurge/DEVELOPING.md#2-test-environment-docker).

What never gets committed here (already in `.gitignore`): `.cache/` (Joomla zips, JED Checker,
local update-server copy), `.env`, screenshots.
