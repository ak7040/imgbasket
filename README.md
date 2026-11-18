# ImgBasket

The code behind [imgbasket.com](https://imgbasket.com).

Free image hosting with a private drive, built in PHP + MySQL. Files are stored in a private
Telegram channel through a Telegram bot.

- **Public site** (`/`): anyone can upload images, browse the gallery of recent uploads, and get
  share links (page link, direct link, Markdown, HTML, BBCode). No account needed. Uploads can be
  public (in the gallery) or unlisted (link only).
- **Admin** (`/admin`, password protected): a drive with folders, search, grid/list view, and
  moderation of visitors' uploads, with the uploader's IP address.

## How it works

- Each file is sent to your private channel as a document. Files over 19 MB are split into
  pieces automatically, because bots can only download 20 MB at a time.
- MySQL stores the folder tree and which messages make up each file.
- Files up to 25 MB are also cached in `app/storage/`, so they load fast. Larger files are streamed
  from Telegram.
- Images get two small previews made with GD: a JPEG for link previews in chat apps (WhatsApp,
  Telegram, Discord, X…) and a WebP for gallery tiles.

## Requirements

- PHP 8.1+ with `curl`, `gd`, `pdo_mysql` and `exif`. These are standard on Hostinger.
- MySQL or MariaDB.
- Apache or LiteSpeed with `.htaccess` support (Hostinger), or PHP's built-in server for local use.

## Install on Hostinger

1. **Bot:** in Telegram, message [@BotFather](https://t.me/BotFather), send `/newbot`, and copy the token.
2. **Channel:** create a **private** channel. Add your bot as an **admin** with permission to
   post and delete messages.
3. **Database:** hPanel → Databases → create a MySQL database and user. Note the name, user and
   password. Hostinger adds a prefix like `u123456789_`.
4. **Package:** on your computer, run `scripts/package.sh`. It creates `imgbasket.zip`.
5. **Files:** hPanel → File Manager → `public_html`. Delete the placeholder files, upload
   `imgbasket.zip`, and extract it there.
6. **Config:** in `public_html/app/`, copy `config.sample.php` to `config.php`. Fill in
   `admin_password`, `contact_email`, `db` and `telegram.bot_token`. Leave `channel_id` empty for now.
7. **SSL:** turn it on in hPanel, so the admin password travels over HTTPS.
8. **Force HTTPS:** hPanel → Security → SSL → turn on **Force HTTPS**, so search engines only see
   `https://imgbasket.com`.
9. Open `https://imgbasket.com/admin` and sign in. Post any message in your channel and press
   **Try again**: the page shows the channel's ID (it starts with `-100`). Put it in `channel_id`
   and press **Try again**.

The database tables are created automatically on first use.

**Updating:** run `scripts/package.sh`, then upload and extract the zip over the old files.
`app/config.php` and `app/storage/` are never in the zip, so they're kept.

## Admin on a subdomain (recommended)

Put the admin at `https://admin.imgbasket.com` instead of `imgbasket.com/admin`. The main site then has
no admin at all, the sign-in cookie never reaches the public site, and the subdomain is kept out of
search engines (`noindex`, and its `robots.txt` blocks everything). It's the same installation, with
one database and one config.

1. **hPanel → Domains → Subdomains:** create `admin.imgbasket.com`. If hPanel lets you choose its
   folder, pick **`public_html`** itself (the main site's folder) and skip step 2.
2. **Separate folder:** if hPanel created a folder of its own instead (usually `public_html/admin`),
   upload the two files from `extras/admin-subdomain/` (`index.php` and `.htaccess`) into it. They
   load the main site from the folder above. If the subdomain's folder is somewhere else, change
   `MAIN_SITE` in that `index.php` to the main site's folder.
3. **SSL:** turn on SSL for the subdomain in hPanel.
4. **Config:** set `'admin_host' => 'admin.imgbasket.com'` in `app/config.php`.

Open `https://admin.imgbasket.com` and sign in. To go back to `/admin`, set `admin_host` to `''`.

## Run locally

```sh
cp app/config.sample.php app/config.php   # then fill it in
scripts/dev.sh                            # http://127.0.0.1:8080
```

## Configuration (`app/config.php`)

| Key | Meaning |
| --- | --- |
| `site_name` | Name shown in the header, titles and link previews (`ImgBasket`) |
| `site_description` | Home page description in search results and link previews |
| `contact_email` | Shown on Terms, Privacy and Report links, for takedown requests |
| `google_site_verification` | Optional code from Google Search Console's "HTML tag" method |
| `public_url` | Your site's address, e.g. `https://imgbasket.com`. Used in share links. |
| `admin_password` | Password for the admin |
| `admin_host` | Admin subdomain, e.g. `admin.imgbasket.com`. Empty keeps the admin at `/admin`. |
| `db` | MySQL host, name, user, password |
| `telegram.bot_token` / `telegram.channel_id` | Your bot and private channel |
| `public_max_upload` | Max size of visitors' uploads in bytes. `0` means only PHP's limit applies. |
| `public_uploads_per_hour` | Uploads per IP address per hour (default 30) |
| `cache_max_file` | Files up to this size are cached on disk (default 25 MB) |
| `ip_header` | Set to `HTTP_X_FORWARDED_FOR` if every upload shows the same IP (a CDN in front) |

**Upload size:** PHP's limit applies. `.user.ini` asks for 2 GB, but the host may cap it. Raise it
in hPanel → Advanced → PHP Configuration (`upload_max_filesize`, `post_max_size`). The admin and the
public site both show the current limit.

## Security and abuse protection

- `/admin` needs the password. Sign-ins last 30 days in a signed, HttpOnly cookie. Wrong passwords
  are limited to 10 per 15 minutes per IP, and changing the password signs everyone out.
- `app/` (code, config, stored files) can't be opened from the web.
- Visitors can only upload real images: JPG, PNG, GIF, WebP, AVIF and BMP, checked by file contents.
  SVG is rejected because it can contain scripts. Uploaded files are served with a sandbox policy,
  so they can't run scripts on the site.
- Uploads are limited per IP address per hour, and every upload records the uploader's IP.
- Uploads are published immediately. Check **Public uploads** in the admin regularly and delete
  anything that breaks your rules. The content is stored in your Telegram channel.

## SEO

- **Home page:** keyword-focused title and description, canonical URL, Open Graph/Twitter tags with a
  1200×630 share image (`og-image.png`), JSON-LD structured data (WebSite, Organization,
  WebApplication), and crawlable content (features and FAQ).
- **Image pages** (`/s/<token>`): a descriptive title and description built from the file name,
  canonical URL, `ImageObject` structured data, image alt text, and a large image preview for search.
- **`/sitemap.xml`:** the home page, legal pages and every **public gallery** image, with image tags.
  `/robots.txt` points to it and keeps `/admin` and `/api/` out of search.
- **Unlisted images stay private:** their pages carry `noindex`, and their files and previews are
  sent with `X-Robots-Tag: noindex`. They never appear in the sitemap. The admin is `noindex` too.
- **One address:** `www.imgbasket.com` redirects to `imgbasket.com` (the `public_url` host). Turn on
  Force HTTPS in hPanel for the `https://` part.
- **Speed:** compression and browser caching in `.htaccess`, lazy-loaded gallery thumbnails, and
  width/height on images so the page doesn't jump while loading.
- **Trust pages:** `/terms`, `/privacy`, and a **Report** link on every image page.

**After going live:**

1. Add the site in [Google Search Console](https://search.google.com/search-console). Use the
   "HTML tag" method: put the code in `google_site_verification`, then verify.
2. Submit `https://imgbasket.com/sitemap.xml` under Sitemaps.
3. Optionally do the same in [Bing Webmaster Tools](https://www.bing.com/webmasters), which can import
   from Search Console.
4. Test a shared link with the [Rich Results Test](https://search.google.com/test/rich-results) and a
   social preview checker.
5. Review `/terms` and `/privacy` (in `app/pages.php`) for your situation. They're general wording,
   not legal advice.

## Link previews

Each image page has Open Graph tags pointing at a small JPEG preview (`/t/<token>.jpg`, at most
1200 px), so pasted links show the image in chat apps. Previews only appear once the site is
reachable on the internet, and apps cache them for a while.

## Project layout

```
index.php            Front controller (all routes)
.htaccess            Sends requests to index.php; blocks app/ and dotfiles
.user.ini            PHP upload and time limits
site.css, site.js    Public site
icons.js, favicon.svg, apple-touch-icon.png, icon-192.png, icon-512.png, site.webmanifest
og-image.png         Share image for the home page
assets/admin/        Admin page script and styles
extras/admin-subdomain/  Bridge files for an admin subdomain with its own folder
app/                 PHP code, config, page templates (views/), and storage/ (not web-accessible)
scripts/             dev.sh (run locally), package.sh (build the upload zip), dev-router.php
```
