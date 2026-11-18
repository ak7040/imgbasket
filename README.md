# ImgBasket

The code behind [imgbasket.com](https://imgbasket.com).

Free image hosting with a private drive, built in PHP + MySQL. Files are stored on Telegram: in your
own account's **Saved Messages** (default), or in a private channel through a bot.

- **Public site** (`/`): anyone can upload images, browse the gallery of recent uploads, and get
  share links (page link, direct link, Markdown, HTML, BBCode). No account needed. Uploads can be
  public (in the gallery) or unlisted (link only).
- **Admin** (`/admin`, password protected): a drive with folders, search, grid/list view, and
  moderation of visitors' uploads, with the uploader's IP address.

## How it works

- **Saved Messages (default):** the site logs in as your Telegram account with
  [MadelineProto](https://docs.madelineproto.xyz) and sends each file to your Saved Messages as one
  document, up to 2 GB (4 GB with Premium). You log in once from the admin by scanning a QR code.
- **Bot (alternative):** each file goes to a private channel through a bot. Files over 19 MB are split
  into pieces, because bots can only download 20 MB at a time.
- MySQL stores the folder tree and which messages make up each file.
- Files up to 25 MB are also cached in `app/storage/`, so they load fast. Larger files are streamed
  from Telegram.
- Images get two small previews made with GD: a JPEG for link previews in chat apps (WhatsApp,
  Telegram, Discord, X…) and a WebP for gallery tiles.

## Requirements

- PHP 8.2+ (64-bit) with `curl`, `gd`, `pdo_mysql`, `exif`, `mbstring`, `xml`, `dom`, `openssl`,
  `zlib` and `fileinfo`. All are standard on Hostinger. `gmp` makes Telegram logins faster.
- For Saved Messages: outgoing connections to Telegram on port 443 and `memory_limit` of 256 MB or
  more. `extras/tg-check.php` checks this on a server; delete it afterwards.
- MySQL or MariaDB.
- Apache or LiteSpeed with `.htaccess` support (Hostinger), or PHP's built-in server for local use.

## Install on Hostinger

1. **Telegram API keys** (for Saved Messages): log in at <https://my.telegram.org/apps> with your phone
   number, open **API development tools**, create an app with any name, and copy the **api_id** and
   **api_hash**.
2. **Database:** hPanel → Databases → create a MySQL database and user. Note the name, user and
   password. Hostinger adds a prefix like `u123456789_`.
3. **Files:** deploy the repository with hPanel → **Git**, or run `scripts/package.sh` and extract
   `imgbasket.zip` into `public_html`. `vendor/` (MadelineProto) is part of the repository and the zip,
   so nothing needs installing on the server.
4. **Config:** in `public_html/app/`, copy `config.sample.php` to `config.php`. Fill in
   `admin_password`, `contact_email`, `db`, `telegram.api_id` and `telegram.api_hash`.
5. **SSL:** install SSL in hPanel and turn on **Force HTTPS** (Security → SSL).
6. **Connect Telegram:** open the admin and sign in. A QR code appears. On your phone, open Telegram →
   Settings → Devices → **Link Desktop Device** and scan it. If your account has two-step
   verification, enter that password. The admin then opens, and uploads work.

The database tables are created automatically on first use. The Telegram login is kept in
`app/storage/telegram/`. To disconnect, end the "ImgBasket server" session in Telegram → Settings →
Devices, and delete that folder.

**Using a bot instead:** set `'storage' => 'bot'`, create a bot with [@BotFather](https://t.me/BotFather)
(`/newbot`), add it as an admin of a private channel with permission to post and delete messages, and
put the token in `telegram.bot_token`. The admin then shows the channel ID to put in `channel_id`.
Files stored one way keep working after switching to the other.

**Updating:** pull with hPanel → Git, or upload and extract a new `imgbasket.zip`.
`app/config.php` and `app/storage/` are never in the repository or the zip, so they're kept.

### How Saved Messages works on shared hosting

MadelineProto keeps one background worker connected to Telegram. On hosts that disable `proc_open`
(Hostinger does), it starts that worker by requesting
`vendor/danog/madelineproto/src/Ipc/Runner/entry.php` from the site itself. `.htaccess` lets only that
file through, and only for those startup requests (`MadelineSelfRestart=1`); everything else in
`vendor/` is blocked. If the host stops the worker, it's started again on the next upload or download,
which takes a few seconds. Errors go to `app/storage/telegram/MadelineProto.log`.

**Updating MadelineProto:** after `composer update`, add `vendor/` with **`git add -f vendor`**, not a
plain `git add`. Some libraries ship `.gitignore` files that would otherwise silently leave required files
out (for example MadelineProto's `Broadcast.php`), and the site then fails on the server with
"Class … not found". Check that nothing is left out with `git ls-files --others --ignored --exclude-standard vendor`,
which should print nothing.

**Your account and visitors' uploads:** everything visitors upload is stored in your Telegram account.
If someone uploads illegal content, Telegram may restrict or ban that account. Consider a separate
Telegram account just for ImgBasket, and check **Public uploads** in the admin regularly.

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
| `storage` | `saved_messages` (default) or `bot` |
| `telegram.api_id` / `telegram.api_hash` | From my.telegram.org, for Saved Messages |
| `telegram.bot_token` / `telegram.channel_id` | Your bot and private channel, for bot storage |
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
  1200×630 share image (`og-image.png`), JSON-LD structured data (WebSite, Organization, WebApplication),
  and crawlable content: the unlimited-upload pitch, an ImgBasket vs Imgur vs ImgBB comparison table,
  feature cards, guide links and an FAQ.
- **Search-facing pages** (copy lives in `app/views/pages/`): `/imgur-alternative`,
  `/imgbb-alternative`, `/anonymous-image-hosting`, `/image-hosting-for-forums` and `/about`. Each has
  its own title, description, canonical URL, breadcrumbs and `BreadcrumbList` JSON-LD. These target
  "imgur alternative", "imgbb alternative", "anonymous image hosting" and "image hosting for forums".
  Edit the HTML files to change the copy; no PHP edits are needed.
- **Image pages** (`/s/<token>`): a descriptive title and description built from the file name,
  canonical URL, `ImageObject` and `BreadcrumbList` structured data, image alt text, a large image
  preview for search, and internal links back to the uploader and the forum guide.
- **`/sitemap.xml`:** the home page, the five content pages, legal pages and every **public gallery**
  image, with image tags and titles. `/robots.txt` points to it and keeps `/admin` and `/api/` out of
  search. It still returns the static pages if the database is briefly unavailable.
- **Crawlable gallery:** the first 12 public images are rendered into the home page HTML as real links
  (the gallery script then replaces the masonry), so image pages get internal links instead of being
  reachable only through the sitemap.
- **Unlisted images stay private:** their pages carry `noindex`, and their files and previews are
  sent with `X-Robots-Tag: noindex`. They never appear in the sitemap. The admin is `noindex` too.
- **One address:** `www.imgbasket.com` redirects to `imgbasket.com` (the `public_url` host), and a
  trailing slash on a content page redirects to the canonical URL. Turn on Force HTTPS in hPanel for the
  `https://` part.
- **`/llms.txt`:** a short plain-text page map for AI assistants. Google ignores it; it costs nothing.
- **IndexNow:** the `<32-hex>.txt` file in the repository root is the IndexNow key. Run
  `scripts/indexnow.sh` after a deploy to submit every sitemap URL to Bing (and Copilot) without waiting
  for a crawl. Pass URLs as arguments to submit only those.
- **Speed:** compression and browser caching in `.htaccess`, lazy-loaded gallery thumbnails, width/height
  on images so the page doesn't jump, and a small HTML payload (Lighthouse mobile: Performance 99,
  SEO 100).
- **Trust pages:** `/terms`, `/privacy`, `/about` and a **Report** link on every image page.

**After going live:**

1. Add the site in [Google Search Console](https://search.google.com/search-console). Use the
   "HTML tag" method: put the code in `google_site_verification`, then verify.
2. Submit `https://imgbasket.com/sitemap.xml` under Sitemaps. Request indexing for `/` and the five
   content pages under URL Inspection.
3. Do the same in [Bing Webmaster Tools](https://www.bing.com/webmasters) (it can import from Search
   Console), then run `scripts/indexnow.sh`.
4. Set `site_description` in the server's `app/config.php` to the value in `app/config.sample.php` — that
   file is not in the repository, so this is a manual change. It is the home page meta description.
5. Test a shared link with the [Rich Results Test](https://search.google.com/test/rich-results) and a
   social preview checker.
6. Review `/terms` and `/privacy` (in `app/pages.php`) for your situation. They're general wording,
   not legal advice.

**Ranking reality check:** these changes fix on-page targeting, internal linking and discovery. They do
not create authority by themselves. A new domain needs links and time: get listed in image-hosting
directories and "best free image host" roundups, answer real questions where a link is welcome, and seed
the public gallery with images people actually search for. Without backlinks, expect new pages to sit on
page 2–5 for competitive terms such as "free image hosting".

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
qrcode.js            QR code generator (qrcode-generator 1.4.4, MIT) used on upload results
icons.js, favicon.svg, apple-touch-icon.png, icon-192.png, icon-512.png, site.webmanifest
og-image.png         Share image for the home page
assets/admin/        Admin page script and styles
extras/admin-subdomain/  Bridge files for an admin subdomain with its own folder
app/                 PHP code, config, page templates (views/), and storage/ (not web-accessible)
  views/pages/       Copy for the SEO pages (/imgur-alternative, /about, …)
  telegram.php       Storage switch; storage_saved.php (Saved Messages), storage_bot.php (bot)
vendor/              MadelineProto and its libraries (composer.json); blocked from the web
scripts/             dev.sh (run locally), package.sh (build the upload zip), dev-router.php, indexnow.sh
```
