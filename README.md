# One Click Blog to Social Media

Automatically cross-posts articles published on your WordPress site to your **Google Business Profile**, your **Facebook Page**, and your **Instagram business account** — all from one plugin.

Publish once, post everywhere.

## Features

- **One plugin, many destinations** — each destination (Google Business Profile, Facebook Pages, Instagram, …) is a self-contained "provider" driver with its own credentials, connection, and targets. Facebook and Instagram can share one Meta app or use separate apps.
- **Automatic cross-posting** — when an article is published (including automatically by Soro or other tools), it is posted to every enabled, connected destination.
- **Per-destination control** — the Destinations panel lets you turn each network on or off without disconnecting it; each has its own target list (Google Business locations, Facebook Pages, Instagram accounts).
- **Language filter** — choose which language(s) may be posted. Articles in other languages are skipped. Uses Polylang / WPML assignments when available, otherwise automatic language detection.
- **Featured image reuse** — the article's featured image is reused where supported. Images are automatically resized/converted to meet Google's requirements (JPEG/PNG, min 400×300, max 5 MB). Optional fallback image.
- **Category filter** — optionally limit cross-posting to selected categories.
- **Hashtags** — optional always-on hashtags (e.g. your business name) plus automatic hashtags generated from the article's tags and/or categories, with a maximum count. Applied to Facebook and Instagram posts.
- **Posting log** — one unified log showing exactly what was posted to which destination, skipped (and why), or failed. OAuth connects are logged too, including which app ID was used.
- **Manual trigger** — a "Cross-post" row action on the posts list for immediate posting.
- **Built-in updater** — updates are delivered via this repo's GitHub releases using WordPress's native update system.

## Requirements

- WordPress 5.8+ with PHP 7.4+
- The [GD image extension](https://www.php.net/manual/en/book.image.php) (standard in most installs) for image conversion

## Installation

1. Copy the `one-click-blog-to-social-media` folder into `wp-content/plugins/` (or upload the ZIP via Plugins → Add New → Upload Plugin).
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

Meta's developer dashboard is use-case based. Setup in order:

1. In [developers.facebook.com](https://developers.facebook.com/), create an app (type: Business) and select the **"Manage everything on your Page"** use case.
2. In **App settings → Basic**, fill in **everything** before saving: **App Domains** (your site's domain, bare — no `https://`, e.g. `example.com`; add both `www.` and non-www variants if both are used), the **Website platform** (+ Add Platform → Website → your site URL), Privacy Policy URL, Category, and an app icon. Meta silently rejects saves when required fields are missing — **reload the page after saving to verify everything persisted**.
3. In the **Facebook Login for Business** settings (added automatically by the use case; if the sidebar doesn't show it, try `https://developers.facebook.com/apps/APP_ID/fb-login/settings/`), add the redirect URI shown on the plugin settings page under **Valid OAuth Redirect URIs**. Enable **Client OAuth login** and **Web OAuth login**.
4. In the use case's **Customize → Permissions** list, click **Add** on `pages_read_engagement` and `pages_manage_posts` if they are not already attached. Meta attaches use cases but leaves optional permissions un-added until you click Add.
5. Enter the App ID and App Secret in the plugin, save, then click **Connect Facebook account** — and if the approval dialog offers **"Edit settings"**, use it and **opt in to all Pages** so every Page (including future ones) is granted.
6. Click **Refresh Pages**, tick the Pages to post to, and save.

**If your Facebook Page is managed through a business portfolio (Meta Business Suite):** the Page is only visible to tokens that carry the `business_management` permission. Add it in the use case's permissions list, then disconnect and reconnect. The plugin stores each Page's own **Page access token** and posts as the Page (not as your personal profile), which is what Meta requires for portfolio-managed Pages.

**Testing note:** apps in **Development mode** work fully with your own accounts — no App Review or business verification needed. Only publish the app if strangers will use it.

### Instagram

Instagram posting uses the Facebook Graph API with a Meta app that has the **"Manage messaging and content on Instagram"** use case.

1. Your Instagram account must be a **Business or Creator** account linked to a Facebook Page. Personal accounts cannot be posted to via any API — this is Meta's rule.
2. In your Meta app, add the **"Manage messaging and content on Instagram"** use case, then in its **Customize → Permissions** list explicitly click **Add** on `instagram_basic` and `instagram_content_publish`.
3. Add the Instagram redirect URI shown on the plugin settings page under Valid OAuth Redirect URIs (it differs from the Facebook one). Instagram can use the same Meta app as Facebook or a different one — it has its own App ID/Secret fields.
4. Enter the App ID and App Secret in the plugin's Instagram section, save, then click **Connect Instagram account**. Approve everything; opt in to all Pages if asked.
5. Click **Refresh accounts**, tick the Instagram accounts to post to, and save. Only Business/Creator accounts linked to your Facebook Pages appear.

**If your Instagram account doesn't appear after refreshing:** like Facebook, portfolio-managed assets need the `business_management` permission attached to the app — add it in the use case's permissions list, then disconnect and reconnect Instagram.

Notes:
- Instagram feed posts require an image, so articles without a featured image (and no fallback image) are skipped with a logged reason. Links are not clickable on Instagram, so the article URL is appended to the caption.
- The plugin waits for Instagram to finish processing each image before publishing (per Meta's documented container flow), so posting takes a few extra seconds.
- API-published posts count toward Instagram's limit of 100 posts per 24 hours.

### Troubleshooting OAuth errors

- **"domain is not included in the app's domains"** — the App Domains field must contain your bare domain (no `https://`), the Website platform must be added, and the save must have actually persisted (reload and check). Also confirm the plugin's App ID matches the app you're editing.
- **"Invalid Scopes"** — the app doesn't have that permission attached. Open the relevant use case → Customize → Permissions → click **Add** on the listed permission, then retry the connect.
- **"URL blocked / not whitelisted"** — the exact full redirect URI (including the query string) must be listed in Facebook Login for Business → Settings → Valid OAuth Redirect URIs.
- **"client_secret should not be passed to /oauth/token"** — the token exchange must use Facebook's `/oauth/access_token` endpoint (the plugin does this correctly from v0.2.2 onward).
- **"(#200) … with page token"** — posting to portfolio-managed Pages requires the Page's own access token; the plugin captures these when you click Refresh Pages. If this error appears, click Refresh Pages and re-tick your Pages.
- **"Media ID is not available"** — the image container wasn't ready yet; the plugin now polls and waits before publishing.
- Every connect attempt is written to the posting log — check there first.

## Architecture (for contributors)

Each destination is a driver class extending `OCSM_Provider` (see `includes/class-ocsm-provider.php`):

- `includes/providers/class-ocsm-provider-google.php` — Google Business Profile
- `includes/providers/class-ocsm-provider-facebook.php` — Facebook Pages (posts with Page access tokens)
- `includes/providers/class-ocsm-provider-instagram.php` — Instagram (Business/Creator accounts via the Facebook Graph API)

To add a new platform (X/Twitter, LinkedIn, Bluesky, …):

1. Create `includes/providers/class-ocsm-provider-{id}.php` implementing the abstract methods (`settings`, `is_connected`, `get_connect_url`, `exchange_code`, `get_targets`, `post`, `render_settings`, `handle_action`, …).
2. Register it in `OCSM_Providers::all()` (`includes/class-ocsm-providers.php`).
3. Require the file in the main plugin file.

The shared machinery — publish hook, language/category filters, image processing, hashtag generation, logging, admin page, updater — lives in `includes/` and works for all providers.

## License

GPL-2.0-or-later
