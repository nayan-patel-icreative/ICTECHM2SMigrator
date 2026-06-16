import sys
import re

if len(sys.argv) < 2:
    print("Usage: python3 update_tunnel.py <new_tunnel_url>")
    sys.exit(1)

new_url = sys.argv[1].strip().rstrip('/')
if not new_url.startswith("http://") and not new_url.startswith("https://"):
    new_url = "https://" + new_url

print(f"Updating configuration files with new tunnel URL: {new_url}")

# 1. Update backend/.env
env_path = "backend/.env"
try:
    with open(env_path, "r", encoding="utf-8") as f:
        env_content = f.read()

    # Replace APP_URL=...
    env_content = re.sub(r'^APP_URL\s*=.*$', f'APP_URL={new_url}', env_content, flags=re.MULTILINE)
    # Replace SHOPIFY_APP_URL=...
    env_content = re.sub(r'^SHOPIFY_APP_URL\s*=.*$', f'SHOPIFY_APP_URL={new_url}', env_content, flags=re.MULTILINE)

    with open(env_path, "w", encoding="utf-8") as f:
        f.write(env_content)
    print("✓ backend/.env updated successfully.")
except Exception as e:
    print(f"✗ Failed to update backend/.env: {e}")

# 2. Update shopify.app.toml
toml_path = "shopify.app.toml"
try:
    with open(toml_path, "r", encoding="utf-8") as f:
        toml_content = f.read()

    # Replace application_url = ...
    toml_content = re.sub(r'^application_url\s*=\s*".*?"', f'application_url = "{new_url}"', toml_content, flags=re.MULTILINE)
    # Replace redirect_urls = [ ... ]
    # Simple regex replacing trycloudflare callback url
    toml_content = re.sub(r'https://[a-zA-Z0-9\-]+\.trycloudflare\.com/auth/shopify/callback', f'{new_url}/auth/shopify/callback', toml_content)

    with open(toml_path, "w", encoding="utf-8") as f:
        f.write(toml_content)
    print("✓ shopify.app.toml updated successfully.")
except Exception as e:
    print(f"✗ Failed to update shopify.app.toml: {e}")
