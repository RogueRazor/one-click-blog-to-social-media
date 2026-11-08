# One Click Blog to Social Media

Automatically cross-posts articles published on your WordPress site to your **Google Business Profile** and your **social media pages** (Facebook Pages, with more platforms planned) — all from one plugin.

Publish once, post everywhere.

## Features

- **One plugin, many destinations** — each destination (Google Business Profile, Facebook Pages, Instagram, …) is a self-contained "provider" driver with its own credentials, connection, and targets.
- **Automatic cross-posting** — when an article is published (including automatically by Soro or other tools), it is posted to every enabled, connected destination.
- **Per-destination control** — enable or disable each destination independently; each has its own target list (Google Business locations, Facebook Pages).
- **Language filter** — choose which language(s) may be posted. Articles in other languages are skipped. Uses Polylang / WPML assignments when available, otherwise automatic language detection.
- **Featured image reuse** — the article's featured image is reused where supported. Images are automatically resized/converted to meet Google's requirements (JPEG/PNG, min 400×300, max 5 MB). Optional fallback image.
- **Category filter** — optionally limit cross-posting to selected categories.
- **Posting log** — one unified log showing exactly what was posted to which destination, skipped (and why), or failed.
- **Manual trigger** — a "Cross-post" row action on the posts list for immediate posting.
- **Built-in updater** — updates are delivered via this repo's GitHub releases using WordPress's native update system.

## Requirements

- WordPress 5.8+ with PHP 7.4+
- The [GD image extension](https://www.php.net/manual/en/book.image.php) (standard in most installs) for image conversion

## Installation

1. Copy the `one-click-blog-to-social-media` folder into `wp-content/plugins/`.
2. Activate **One Click Blog to Social Media** in the Plugins screen.
3. Open **Settings → Blog → Social & Google**.

## Destination setup

### Google Business Profile

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project and enable the **Business Profile API**.
2. Configure the OAuth consent screen (user type External; add your Google account as a Test user).
3. Create an **OAuth client ID** (Web application) and add the redirect URI shown on the plugin settings page.
4. Enter the Client ID and Client Secret in the plugin, save, then click **Connect Google account**.
5. Refresh locations, pick the Business Profile locations to post to, and save.

### Facebook Pages

1. In [developers.facebook.com](https://developers.facebook.com/), create an app (type: Business).
2. Add the **Facebook Login for Business** product and add the redirect URI shown on the plugin settings page under Valid OAuth Redirect URIs.
3. Request the `pages_show_list`, `pages_manage_posts`, and `pages_read_engagement` permissions.
4. Enter the App ID and App Secret in the plugin, save, then click **Connect Facebook account**.
5. Refresh Pages, pick the Pages to post to, and save.

### Instagram

1. Instagram uses the **same Meta app** as the Facebook section — no separate credentials needed. In your [Meta app](https://developers.facebook.com/), request the `instagram_basic` and `instagram_content_publish` permissions (plus `pages_show_list`, `pages_read_engagement`).
2. Add the Instagram redirect URI shown on the plugin settings page under Valid OAuth Redirect URIs (it differs from the Facebook one).
3. Your Instagram account must be a **Business or Creator** account linked to a Facebook Page.
4. With the Meta app configured in the Facebook section, click **Connect Instagram account**.
5. Refresh accounts, pick the Instagram accounts to post to, and save.

Note: Instagram feed posts require an image, so articles without a featured image (and no fallback image) are skipped. Links are not clickable on Instagram, so the article URL is appended to the caption.

## Architecture (for contributors)

Each destination is a driver class extending `OCSM_Provider` (see `includes/class-ocsm-provider.php`):

- `includes/providers/class-ocsm-provider-google.php` — Google Business Profile
- `includes/providers/class-ocsm-provider-facebook.php` — Facebook Pages
- `includes/providers/class-ocsm-provider-instagram.php` — Instagram (Business/Creator accounts via the Facebook Graph API)

To add a new platform (X/Twitter, LinkedIn, Bluesky, …):

1. Create `includes/providers/class-ocsm-provider-{id}.php` implementing the abstract methods (`settings`, `is_connected`, `get_connect_url`, `exchange_code`, `get_targets`, `post`, `render_settings`, `handle_action`, …).
2. Register it in `OCSM_Providers::all()` (`includes/class-ocsm-providers.php`).
3. Require the file in the main plugin file.

The shared machinery — publish hook, language/category filters, image processing, logging, admin page, updater — lives in `includes/` and works for all providers.

## License

GPL-2.0-or-later
