<?php
// Admin API, used by assets/admin/app.js: at /admin/api/... or, with an admin subdomain, at
// /api/... there. $path is always relative to the admin, e.g. "/api/files/12/share".

function admin_route(string $method, string $path): never
{
    if ($path === '/api/admin/login' && $method === 'POST') admin_login();
    if (!is_admin()) {
        // The page loads either way and shows the password screen
        if ($path === '/api/auth/status') json_out(['status' => 'admin']);
        json_out(['error' => 'Admin login required'], 401);
    }

    if ($path === '/api/admin/logout' && $method === 'POST') admin_logout();
    if ($path === '/api/auth/status') json_out(storage_status());
    if ($path === '/api/auth/retry' && $method === 'POST') json_out(storage_status(true));
    // Two-step verification password during the Saved Messages (QR) login
    if ($path === '/api/auth/password' && $method === 'POST') json_out(storage_submit_password((string) (json_body()['password'] ?? '')));

    // Everything else needs working Telegram storage; 401 sends the page back to the status screen
    $status = storage_status();
    if ($status['status'] !== 'ready') json_out(['error' => $status['error'] ?? 'Storage is not ready'], 401);

    $routes = [
        ['GET', '#^/api/summary$#', 'admin_summary'],
        ['GET', '#^/api/list$#', 'admin_list'],
        ['GET', '#^/api/files$#', 'admin_files'],
        ['POST', '#^/api/files$#', 'admin_upload'],
        ['POST', '#^/api/folders$#', 'admin_create_folder'],
        ['PATCH', '#^/api/folders/(\d+)$#', 'admin_rename_folder'],
        ['DELETE', '#^/api/folders/(\d+)$#', 'admin_delete_folder'],
        ['PATCH', '#^/api/files/(\d+)$#', 'admin_update_file'],
        ['DELETE', '#^/api/files/(\d+)$#', 'admin_delete_file'],
        ['GET', '#^/api/files/(\d+)/(raw|download|thumb)$#', 'admin_file_content'],
        ['POST', '#^/api/files/(\d+)/share$#', 'admin_share'],
        ['DELETE', '#^/api/files/(\d+)/share$#', 'admin_unshare'],
    ];
    foreach ($routes as [$verb, $pattern, $handler]) {
        if (($method === $verb || ($verb === 'GET' && $method === 'HEAD')) && preg_match($pattern, $path, $m)) {
            $handler(...array_slice($m, 1));
        }
    }
    json_out(['error' => 'Not found'], 404);
}

function to_id(mixed $v): ?int
{
    return $v === null || $v === '' || $v === 'root' ? null : (int) $v;
}

function find_file_or_404(string $id): array
{
    return get_file((int) $id) ?? json_out(['error' => 'File not found'], 404);
}

function admin_summary(): never
{
    $s = q_one("SELECT COUNT(*) AS files, COALESCE(SUM(size), 0) AS size, COUNT(share_token) AS shared,
                       COALESCE(SUM(source = 'public'), 0) AS public_uploads FROM files");
    json_out([
        'stats' => array_map('intval', $s),
        'folders' => array_map('folder_row', q_all('SELECT id, name, parent_id FROM folders ORDER BY name')),
        'maxFileSize' => php_upload_limit(),
        'user' => storage_status()['user'] ?? null,
        'publicSiteUrl' => public_url(),
        'adminAuth' => true,
    ]);
}

function admin_list(): never
{
    $folderId = to_id($_GET['folder'] ?? null);
    if ($folderId !== null && !get_folder($folderId)) json_out(['error' => 'Folder not found'], 404);
    // Visitors' uploads live in their own section, so the root of "My files" leaves them out
    $files = q_all(
        "SELECT * FROM files WHERE folder_id <=> ? AND (? IS NOT NULL OR source <> 'public') ORDER BY created_at DESC, id DESC",
        [$folderId, $folderId]
    );
    $folders = q_all(
        'SELECT f.*, (SELECT COUNT(*) FROM files WHERE folder_id = f.id) AS file_count
         FROM folders f WHERE parent_id <=> ? ORDER BY name',
        [$folderId]
    );
    json_out([
        'folderId' => $folderId,
        'path' => folder_path($folderId),
        'folders' => array_map('folder_row', $folders),
        'files' => array_map('with_links', $files),
    ]);
}

// Flat lists: everything, shared, public uploads, or a name search
function admin_files(): never
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $scope = (string) ($_GET['scope'] ?? '');
    if ($search !== '') {
        $files = q_all("SELECT * FROM files WHERE name LIKE ? ORDER BY created_at DESC, id DESC LIMIT 500",
            ['%' . addcslashes($search, '\\%_') . '%']);
    } elseif ($scope === 'shared') {
        $files = q_all('SELECT * FROM files WHERE share_token IS NOT NULL ORDER BY created_at DESC, id DESC');
    } elseif ($scope === 'public') {
        $files = q_all("SELECT * FROM files WHERE source = 'public' ORDER BY created_at DESC, id DESC");
    } else {
        $files = q_all('SELECT * FROM files ORDER BY created_at DESC, id DESC');
    }
    json_out(['files' => array_map('with_links', $files)]);
}

function admin_upload(): never
{
    $folderId = to_id($_POST['folderId'] ?? null);
    if ($folderId !== null && !get_folder($folderId)) json_out(['error' => 'Folder not found'], 404);
    $uploads = uploaded_files('files');
    if (!$uploads && ($problem = upload_problem(null))) json_out(['error' => $problem[1]], $problem[0]);

    $created = [];
    foreach ($uploads as $u) {
        if ($problem = upload_problem($u)) json_out(['error' => $problem[1]], $problem[0]);
        $image = inspect_image($u['tmp_name']);
        $video = $image ? null : inspect_video($u['tmp_name']);
        $mime = $image['mime'] ?? $video['mime'] ?? (mime_content_type($u['tmp_name']) ?: $u['type'] ?: 'application/octet-stream');
        $created[] = with_links(store_file($u['tmp_name'], [
            'name' => mb_substr(basename($u['name']), 0, 250) ?: 'file',
            'folder_id' => $folderId,
            'mime' => $mime,
            'source' => 'admin',
            'width' => $image['width'] ?? $video['width'] ?? null,
            'height' => $image['height'] ?? $video['height'] ?? null,
            'duration' => $video['duration'] ?? null,
        ]));
    }
    json_out($created, 201);
}

function admin_create_folder(): never
{
    $body = json_body();
    $name = trim((string) ($body['name'] ?? ''));
    $parentId = to_id($body['parentId'] ?? null);
    if ($name === '') json_out(['error' => 'Name is required'], 400);
    if ($parentId !== null && !get_folder($parentId)) json_out(['error' => 'Parent not found'], 404);
    q_exec('INSERT INTO folders (name, parent_id) VALUES (?, ?)', [mb_substr($name, 0, 250), $parentId]);
    json_out(get_folder((int) db()->lastInsertId()), 201);
}

function admin_rename_folder(string $id): never
{
    $name = trim((string) (json_body()['name'] ?? ''));
    if (!get_folder((int) $id)) json_out(['error' => 'Folder not found'], 404);
    if ($name === '') json_out(['error' => 'Name is required'], 400);
    q_exec('UPDATE folders SET name = ? WHERE id = ?', [mb_substr($name, 0, 250), (int) $id]);
    json_out(get_folder((int) $id));
}

function admin_delete_folder(string $id): never
{
    if (!get_folder((int) $id)) json_out(['error' => 'Folder not found'], 404);
    $used = q_one('SELECT (SELECT COUNT(*) FROM folders WHERE parent_id = ?) + (SELECT COUNT(*) FROM files WHERE folder_id = ?) AS n',
        [(int) $id, (int) $id]);
    if ((int) $used['n'] > 0) json_out(['error' => 'Folder is not empty'], 409);
    q_exec('DELETE FROM folders WHERE id = ?', [(int) $id]);
    http_response_code(204);
    exit;
}

// Rename, move, or add to / remove from the public gallery
function admin_update_file(string $id): never
{
    $file = find_file_or_404($id);
    $body = json_body();
    if (array_key_exists('name', $body)) {
        $name = trim((string) $body['name']);
        if ($name === '') json_out(['error' => 'Name is required'], 400);
        q_exec('UPDATE files SET name = ? WHERE id = ?', [mb_substr($name, 0, 250), $file['id']]);
    }
    if (array_key_exists('folderId', $body)) {
        $folderId = to_id($body['folderId']);
        if ($folderId !== null && !get_folder($folderId)) json_out(['error' => 'Folder not found'], 404);
        q_exec('UPDATE files SET folder_id = ? WHERE id = ?', [$folderId, $file['id']]);
    }
    if (array_key_exists('inGallery', $body)) {
        if ($body['inGallery'] && !is_media($file)) json_out(['error' => 'Only images and videos can be shown in the gallery'], 400);
        if ($body['inGallery'] && !$file['share_token']) q_exec('UPDATE files SET share_token = ? WHERE id = ?', [new_token(), $file['id']]);
        q_exec('UPDATE files SET in_gallery = ? WHERE id = ?', [$body['inGallery'] ? 1 : 0, $file['id']]);
    }
    json_out(with_links(get_file($file['id'])));
}

function admin_delete_file(string $id): never
{
    delete_file(find_file_or_404($id));
    http_response_code(204);
    exit;
}

function admin_file_content(string $id, string $what): never
{
    $file = find_file_or_404($id);
    try {
        if ($what === 'thumb') {
            // Small preview for gallery tiles; falls back to the original when none can be made
            send_thumbnail($file, 'grid', admin_base() . "api/files/{$file['id']}/raw");
        }
        send_file($file, $what === 'raw', 'private, max-age=86400');
    } catch (TelegramGone $e) {
        // Deleted in the Telegram app: remove the stale record so the drive stops listing it
        discard_file($file);
        if (headers_sent()) exit;
        header_remove();
        json_out(['error' => 'This file was deleted in Telegram, so its record was removed.'], 404);
    }
}

// Create (or return the existing) public link for a file
function admin_share(string $id): never
{
    $file = find_file_or_404($id);
    if (!$file['share_token']) q_exec('UPDATE files SET share_token = ? WHERE id = ?', [new_token(), $file['id']]);
    json_out(with_links(get_file($file['id'])));
}

// Turning the link off also takes the file out of the gallery
function admin_unshare(string $id): never
{
    $file = find_file_or_404($id);
    q_exec('UPDATE files SET share_token = NULL, in_gallery = 0 WHERE id = ?', [$file['id']]);
    json_out(with_links(get_file($file['id'])));
}
