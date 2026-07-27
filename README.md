# GitHub Auto Deploy

A WordPress plugin that automatically deploys themes/plugins from GitHub on push. OAuth-based setup — just connect your GitHub account, pick a repo, and go. No manual SSH key or webhook configuration needed.

## How It Works

1. **Connect** — Authorize via GitHub OAuth (one click)
2. **Pick** — Select a repo from your account dropdown
3. **Generate** — Plugin creates an SSH deploy key and installs it on GitHub automatically
4. **Webhook** — Plugin creates the webhook on GitHub automatically
5. **Deploy** — Every push to your branch auto-deploys via `git fetch && git reset --hard`

## Requirements

- WordPress 5.0+
- PHP 7.4+ with OpenSSL extension
- Git installed on the server
- `proc_open` enabled
- `exec()` enabled (for SSH key generation)
- A [GitHub OAuth App](https://github.com/settings/developers)

## Setup

### 1. Create a GitHub OAuth App

1. Go to [GitHub Settings → Developer settings → OAuth Apps](https://github.com/settings/developers)
2. Click **New OAuth App**
3. Fill in:
   - **Application name**: `My Site Deployer` (or anything)
   - **Homepage URL**: Your WordPress site URL
   - **Authorization callback URL**: The URL shown on the plugin settings page (copy it from there)
4. Click **Register application**
5. Copy the **Client ID** and **Client Secret**

### 2. Install & Configure

1. Upload `github-auto-deploy` to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **Tools → GitHub Deploy**
4. Enter your **Client ID** and **Client Secret** from the OAuth App
5. Click **Connect with GitHub** → authorize the app
6. Select a repo from the dropdown
7. Set the **branch** and **local path** on your server
8. Click **Generate & Add Deploy Key** — the plugin creates an SSH key and adds it as a deploy key
9. Click **Create Webhook on GitHub** — the plugin registers the webhook for push events

That's it. Next push to your branch auto-deploys.

## Usage

### Auto-Deploy

Every push to the configured branch triggers the webhook. The plugin runs `git fetch origin && git reset --hard origin/<branch>`.

### Manual Deploy

Click **Run Deploy Now** on the settings page to deploy without a webhook.

### Deployment Log

Each deployment is logged with timestamp, status, repo, branch, and full command output (last 100 entries).

## File Structure

```
github-auto-deploy/
├── github-auto-deploy.php       — Plugin bootstrap
├── assets/admin.js              — Admin AJAX (repo list, deploy key, webhook)
├── .gitignore
├── README.md
└── includes/
    ├── class-crypto.php         — AES-256-GCM encryption for tokens/keys
    ├── class-deployer.php       — Git clone/pull via SSH key
    ├── class-github-api.php     — GitHub OAuth + REST API wrapper
    ├── class-webhook.php        — REST endpoint for GitHub push events
    └── class-admin.php          — Settings page with OAuth flow
```

## What's Stored in the Database

All stored encrypted (AES-256-GCM) in the `wp_options` table:

| Key | Value |
|---|---|
| `ghad_settings` | OAuth tokens, SSH key, repo config, webhook config |
| `ghad_deploy_log` | Last 100 deployment logs |

The encryption key is derived from your WordPress `AUTH_SALT`.

## Troubleshooting

| Problem | Likely Cause | Solution |
|---|---|---|
| **"Callback URL mismatch"** | OAuth App callback URL doesn't match | Copy the exact URL from the plugin settings page into your GitHub OAuth App |
| **"Git not installed"** | Server lacks git | Run `apt install git` or `yum install git` on the server |
| **Deploy key fails** | `exec()` disabled or `ssh-keygen` missing | Enable `exec()` in PHP, ensure `ssh-keygen` is available |
| **"Permission denied" on deploy** | SSH key doesn't match repo | Re-generate the deploy key |
| **Webhook not firing** | Server not publicly accessible | Use ngrok for local dev; ensure GitHub can reach your server |

## Changelog

### 2.0.1
- Fixed Client Secret overwritten by placeholder on re-save
- Fixed OAuth redirect URI missing `ghad_oauth=1` param
- Added AJAX save for repo settings (no form submit)
- Added Detect Themes button to auto-fill local path
- Added AJAX auto-fetch repos on page load after connect
- Self-updater now checks GitHub releases for updates

### 2.0.0
- OAuth-based setup — connect GitHub account, pick repo from dropdown
- Auto-generate SSH deploy key and install on GitHub
- Auto-create webhook on GitHub
- Token and SSH key encrypted with AES-256-GCM
- Simplified settings page with guided flow

### 1.0.0
- Manual webhook + SSH key setup
- Basic deploy, log, and admin

## License

GPL-2.0+
