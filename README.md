# GitHub Auto Deploy

A WordPress plugin that automatically deploys themes/plugins from GitHub on push. Uses SSH key authentication and webhook-based triggering. Designed to be reusable across any WordPress site.

## How It Works

1. GitHub sends a webhook to your WordPress site when you push to a branch
2. The plugin verifies the payload via HMAC-SHA256 signature
3. It runs `git fetch && git reset --hard origin/<branch>` (or `git clone` if first time)
4. The SSH private key is stored encrypted (AES-256-GCM) in `wp_options`

## Requirements

- WordPress 5.0+
- PHP 7.4+ with OpenSSL extension
- Git installed on the server
- `proc_open` enabled (for running git commands)
- SSH key with access to your GitHub repo

## Installation

1. Upload the `github-auto-deploy` folder to `/wp-content/plugins/`
2. Activate the plugin via WordPress **Plugins** page
3. Go to **Tools → GitHub Deploy** to configure

## Configuration

### Plugin Settings

| Field | Description |
|---|---|
| **GitHub Repo URL (SSH)** | SSH URL like `git@github.com:user/repo.git` |
| **Branch** | Branch that triggers deployment (default: `main`) |
| **Local Path** | Absolute path to the repo on your server (e.g., `/home/user/public_html/wp-content/themes/my-theme`) |
| **SSH Private Key** | Private key with access to the repo. Stored encrypted in the database. |
| **Webhook Secret** | A secret string used to verify webhook payloads (HMAC-SHA256). Generate a strong one. |

### GitHub Webhook Setup

1. Copy the **Webhook URL** from the plugin's settings page
2. Go to your GitHub repo → **Settings → Webhooks → Add webhook**
3. Set the following:

| Setting | Value |
|---|---|
| **Payload URL** | The webhook URL from the plugin page |
| **Content type** | `application/json` |
| **Secret** | Your webhook secret (must match the one in plugin settings) |
| **SSL verification** | Enabled |
| **Which events?** | **Just the push event** |

4. Click **Add webhook**

GitHub will send a `ping` event to test the connection. The plugin responds with "Pong — webhook is working". On subsequent pushes, it runs the deploy.

## Usage

### Auto-Deploy

Once configured, every push to the configured branch automatically deploys the repo to the local path on your server.

### Manual Deploy

Go to **Tools → GitHub Deploy** and click **Run Deploy Now** to trigger a deploy manually without a webhook.

### Deployment Log

Each deployment is logged with timestamp, status (success/failed), repo, branch, message, and full command output. The log shows the last 100 entries. You can clear it with the **Clear Log** button.

## Security

- **Webhook payloads** are verified via HMAC-SHA256 using your secret. Requests without a valid signature are rejected (HTTP 403).
- **SSH keys** are encrypted with AES-256-GCM before being stored in `wp_options`. The encryption key is derived from your WordPress `AUTH_SALT`.
- **Temporary key files** are written with `0600` permissions and deleted immediately after the deploy completes.
- **Only users** with `manage_options` capability (admin) can access the settings page and trigger manual deploys.

## Automation Tips

### CI/CD Integration

For a full pipeline, create a GitHub Actions workflow that runs build steps before triggering the deploy:

```yaml
name: Build & Deploy
on:
  push:
    branches: [main]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Install dependencies
        run: npm ci
      - name: Build assets
        run: npm run build
      - name: Commit built assets
        run: |
          git config user.name github-actions
          git config user.email github-actions@github.com
          git add -A
          git commit -m "Build assets [skip ci]" || true
          git push
        env:
          GITHUB_TOKEN: ${{ github.token }}
```

The push from GitHub Actions triggers the plugin's webhook, which deploys the built repo to your server.

### Multiple Repos

For sites with multiple repos (separate theme, plugin, mu-plugin repos), install the plugin once but configure different instances by:

1. Creating separate plugin instances in `/wp-content/mu-plugins/` with different settings
2. Or manually updating the options array for different deploy configs via `wp-cli`:

```bash
wp option update ghad_settings '{"repo_url":"git@github.com:user/repo2.git","branch":"main","local_path":"/path/to/other","ssh_key":"...","webhook_secret":"..."}'
```

## Troubleshooting

| Problem | Likely Cause | Solution |
|---|---|---|
| **"Invalid signature" (403)** | Webhook secret mismatch | Verify secret matches between plugin settings and GitHub webhook config |
| **Deploy fails — "Host key verification failed"** | Server hasn't connected to GitHub before | Run `ssh-keyscan github.com >> ~/.ssh/known_hosts` on the server (once) |
| **"Permission denied (publickey)"** | SSH key not added to GitHub deploy keys | Add the public key to GitHub repo → Settings → Deploy keys |
| **"Command exited with code 128"** | Git error (permissions, network) | Check the log details for the full error |
| **No webhook received** | Server not publicly accessible | Use ngrok for local dev; ensure live site accepts POST requests to the REST API endpoint |
| **Plugin settings page blank** | PHP error or missing OpenSSL | Verify `openssl` extension is loaded (`php -m \| grep openssl`) |

## Local Development

To test locally, use [ngrok](https://ngrok.com/) to expose your local WordPress site:

```bash
ngrok http 80
```

Then use the ngrok URL (e.g., `https://abc123.ngrok.io/wp-json/gh-deploy/v1/webhook`) as the webhook payload URL in GitHub.

## Changelog

### 1.0.0
- Initial release
- Webhook-based auto-deploy with HMAC-SHA256 verification
- SSH key auth (encrypted storage)
- Manual deploy button
- Deployment log with last 100 entries
- Admin settings page

## License

GPL-2.0+
