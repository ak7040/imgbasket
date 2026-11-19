<?php
// Copy this file to config.php (same folder) and fill it in.
return [
    // Shown in the header, page titles and link previews
    'site_name' => 'ImgBasket',

    // Used in search results and link previews of the home page. Lead with the main keyword and the
    // things that make the site different (no account, unlimited uploads, size limit).
    'site_description' => 'Free image hosting with unlimited uploads and no account: upload an image up to 2 GB and get a direct link, HTML or BBCode in seconds.',

    // Shown on the Terms, Privacy and Report links, for takedown requests and questions
    'contact_email' => 'support@imgbasket.com',

    // Optional: the code from Google Search Console's "HTML tag" verification method
    'google_site_verification' => '',

    // Your site's address, without a trailing slash. Used in share links and link previews.
    'public_url' => 'https://imgbasket.com',

    // Password for the admin. Use a long, unique one.
    'admin_password' => '',

    // Optional: put the admin on its own subdomain, e.g. 'admin.imgbasket.com' (no https://).
    // The admin is then at https://admin.imgbasket.com and imgbasket.com/admin no longer exists.
    // Leave empty to keep the admin at https://imgbasket.com/admin.
    'admin_host' => '',

    // hPanel > Databases > MySQL Databases
    'db' => [
        'host' => 'localhost',
        'name' => '',
        'user' => '',
        'pass' => '',
    ],

    // Where files are stored:
    //   'saved_messages'  your Telegram account's Saved Messages (log in from the admin with a QR code)
    //   'bot'             a private channel, through a bot
    'storage' => 'saved_messages',

    'telegram' => [
        // Saved Messages: from https://my.telegram.org/apps (log in, "API development tools",
        // create an app with any name)
        'api_id' => '',
        'api_hash' => '',

        // Bot storage only: from @BotFather (/newbot)
        'bot_token' => '',
        // Bot storage only: your private channel's ID, usually starting with -100. Leave empty at
        // first: the admin page then lists the chats your bot can see.
        'channel_id' => '',
        // Bot storage only: change this only if you run your own Bot API server
        'api_base' => 'https://api.telegram.org',
    ],

    // Visitors' uploads: max size in bytes (0 = only PHP's upload limit) and uploads per IP per hour
    'public_max_upload' => 0,
    'public_uploads_per_hour' => 30,

    // Where the visitor's IP comes from. Leave empty to use the connection's address. If every
    // upload shows the same IP (because a CDN or proxy sits in front), set e.g. 'HTTP_X_FORWARDED_FOR'.
    'ip_header' => '',
];
