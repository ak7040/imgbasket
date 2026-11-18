<?php
// Bridge for an admin subdomain whose folder is separate from the main site.
// Copy this file and .htaccess into the subdomain's folder (e.g. public_html/admin) and set
// MAIN_SITE to the folder that contains the main site's index.php.
const MAIN_SITE = __DIR__ . '/..';

require MAIN_SITE . '/index.php';
