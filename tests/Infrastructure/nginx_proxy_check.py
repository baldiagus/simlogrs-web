"""Run against Docker Nginx: python tests/Infrastructure/nginx_proxy_check.py"""
import re
import subprocess
import urllib.request


def asset_scheme(html):
    match = re.search(r'<link[^>]+rel="stylesheet"[^>]+href="(https?)://', html)
    assert match, "No absolute stylesheet URL found on /login"
    return match[1]


for forwarded, expected in [(None, "http"), ("https", "https"), ("http", "http"), ("invalid", "http")]:
    headers = {"X-Forwarded-For": "203.0.113.99"}
    if forwarded:
        headers["X-Forwarded-Proto"] = forwarded
    request = urllib.request.Request("http://127.0.0.1:8000/login", headers=headers)
    with urllib.request.urlopen(request, timeout=15) as response:
        actual = asset_scheme(response.read().decode())
    assert actual == expected, f"Forwarded proto {forwarded!r}: expected {expected}, got {actual}"

# The app container is not a trusted edge proxy; its HTTPS claim must be ignored.
php = '''$context = stream_context_create(['http' => ['header' => "Host: localhost\r\nX-Forwarded-Proto: https\r\nX-Forwarded-For: 203.0.113.99\r\n", 'timeout' => 15]]); echo file_get_contents('http://web/login', false, $context);'''
html = subprocess.run(
    ["docker", "exec", "simrslogv2-app", "php", "-r", php],
    check=True, capture_output=True, text=True, timeout=20,
).stdout
assert asset_scheme(html) == "http", "An untrusted peer changed the asset scheme"
print("PASS: local HTTP, trusted HTTPS, malformed protocol, and untrusted peer")
