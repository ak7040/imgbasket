// ---------- Helpers ----------

const $ = (sel) => document.querySelector(sel);

function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v == null || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else if (k === 'style') node.style.cssText = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (k in node) node[k] = v;
    else node.setAttribute(k, v);
  }
  node.append(...children.flat(Infinity).filter((c) => c != null && c !== false));
  return node;
}

function formatSize(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
  return `${+bytes.toFixed(i && bytes < 100 ? 1 : 0)} ${units[i]}`;
}

function formatDate(sqlDate, long = false) {
  const d = new Date(sqlDate.replace(' ', 'T') + 'Z');
  if (long) return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
  const sameYear = d.getFullYear() === new Date().getFullYear();
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: sameYear ? undefined : 'numeric' });
}

const extOf = (name) => (name.match(/\.([a-z0-9]{1,6})$/i)?.[1] || '').toLowerCase();

function kindOf(file) {
  const mime = file.mime || '';
  const ext = extOf(file.name);
  if (mime.startsWith('image/') || /^(jpe?g|png|gif|webp|avif|bmp|svg|heic)$/.test(ext)) return 'image';
  if (mime.startsWith('video/') || /^(mp4|webm|mov|mkv|m4v)$/.test(ext)) return 'video';
  if (mime.startsWith('audio/') || /^(mp3|wav|ogg|flac|m4a|aac)$/.test(ext)) return 'audio';
  return 'file';
}
const kindIcon = { image: 'image', video: 'video', audio: 'music', file: 'file' };

const rawUrl = (f) => `api/files/${f.id}/raw`;
const thumbUrl = (f) => `api/files/${f.id}/thumb`;
const downloadUrl = (f) => `api/files/${f.id}/download`;

function store(key, value) {
  try {
    if (value === undefined) return localStorage.getItem(key);
    localStorage.setItem(key, value);
  } catch {
    return null;
  }
}

async function api(url, options = {}) {
  const opts = { ...options };
  if (opts.json !== undefined) {
    opts.method ??= 'POST';
    opts.headers = { 'content-type': 'application/json' };
    opts.body = JSON.stringify(opts.json);
    delete opts.json;
  }
  const res = await fetch(url, opts);
  if (res.status === 401 && !url.startsWith('api/auth')) {
    startLogin();
    throw new Error('Not logged in');
  }
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.error || `Request failed (${res.status})`);
  }
  return res.status === 204 ? null : res.json();
}

function toast(message, { error = false } = {}) {
  const node = el('div', { class: `toast${error ? ' error' : ''}` }, icon(error ? 'alert' : 'check', 16), message);
  $('#toasts').append(node);
  setTimeout(() => node.remove(), 2800);
}

const fail = (e) => toast(e.message, { error: true });

async function copyText(text, message = 'Copied to clipboard') {
  try {
    await navigator.clipboard.writeText(text);
    toast(message);
  } catch {
    toast('Could not copy. Select the text and copy it manually.', { error: true });
  }
}

// ---------- Dialogs ----------

function openModal({ title, message, fields = [], confirmLabel = 'Confirm', danger = false }) {
  return new Promise((resolve) => {
    let result = null;
    const form = el('form', {},
      el('h3', { textContent: title }),
      message && el('p', { textContent: message }),
      ...fields,
      el('div', { class: 'actions' },
        el('button', { type: 'button', class: 'btn', textContent: 'Cancel', onclick: () => dlg.close() }),
        el('button', { type: 'submit', class: `btn ${danger ? 'solid-danger' : 'primary'}`, textContent: confirmLabel })),
    );
    const dlg = el('dialog', { class: 'modal' }, form);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      result = new FormData(form);
      dlg.close();
    });
    dlg.addEventListener('close', () => {
      dlg.remove();
      resolve(result);
    });
    document.body.append(dlg);
    dlg.showModal();
    const first = form.querySelector('input, select');
    if (first) {
      first.focus();
      first.select?.();
    }
  });
}

const confirmDialog = (title, message, confirmLabel, danger = true) =>
  openModal({ title, message, confirmLabel, danger }).then(Boolean);

async function promptDialog(title, label, value = '', confirmLabel = 'Save') {
  const data = await openModal({
    title,
    confirmLabel,
    fields: [el('div', { class: 'field' },
      el('label', { textContent: label, htmlFor: 'modal-input' }),
      el('input', { id: 'modal-input', name: 'value', class: 'input', value, required: true, autocomplete: 'off' }))],
  });
  const text = data?.get('value')?.trim();
  return text || null;
}

// ---------- State & routing ----------

const state = {
  route: { view: 'all' },
  files: [],
  folders: [],
  path: [],
  summary: null,
  layout: store('layout') === 'list' ? 'list' : 'grid',
  shareFormat: store('shareFormat') || 'link',
  viewerIndex: -1,
  loadId: 0,
};

function parseRoute() {
  const [, view, arg] = location.hash.match(/^#\/?([^/]*)\/?(.*)$/) || [];
  if (view === 'files') return { view: 'files', folderId: null };
  if (view === 'folder') return { view: 'files', folderId: Number(arg) || null };
  if (view === 'shared') return { view: 'shared' };
  if (view === 'public') return { view: 'public' };
  if (view === 'search' && arg) return { view: 'search', q: decodeURIComponent(arg) };
  return { view: 'all' };
}

const folderHref = (id) => (id == null ? '#/files' : `#/folder/${id}`);
const uploadTarget = () => (state.route.view === 'files' ? state.route.folderId : null);

function folderName(id) {
  if (id == null) return 'My files';
  return state.summary?.folders.find((f) => f.id === id)?.name ?? 'Folder';
}

function routeUrl(route) {
  if (route.view === 'files') return `api/list${route.folderId ? `?folder=${route.folderId}` : ''}`;
  if (route.view === 'shared') return 'api/files?scope=shared';
  if (route.view === 'public') return 'api/files?scope=public';
  if (route.view === 'search') return `api/files?q=${encodeURIComponent(route.q)}`;
  return 'api/files';
}

async function loadRoute() {
  state.route = parseRoute();
  // Keep the search box in sync with the URL, without fighting the user while they type
  if (document.activeElement !== $('#search')) $('#search').value = state.route.view === 'search' ? state.route.q : '';
  renderSidebarActive();
  renderSkeleton();
  await fetchRoute(true);
}

// Re-fetch the current view and the sidebar without the loading skeleton
async function refresh() {
  await Promise.all([loadSummary(), fetchRoute(false)]);
}

async function fetchRoute(redirectOnMissing) {
  const { route } = state;
  const loadId = ++state.loadId;
  try {
    const data = await api(routeUrl(route));
    if (loadId !== state.loadId) return;
    state.files = data.files;
    state.folders = data.folders ?? [];
    state.path = data.path ?? [];
    renderPage();
  } catch (e) {
    if (loadId !== state.loadId) return;
    if (redirectOnMissing && route.view === 'files' && route.folderId) {
      location.hash = '#/files';
      return;
    }
    fail(e);
  }
}

async function loadSummary() {
  try {
    state.summary = await api('api/summary');
    renderSidebar();
  } catch {
    // errors surface through the page load
  }
}

function replaceFile(updated) {
  const i = state.files.findIndex((f) => f.id === updated.id);
  if (i !== -1) state.files[i] = updated;
}

// ---------- Sidebar ----------

function renderSidebar() {
  const s = state.summary;
  if (!s) return;
  $('#count-all').textContent = s.stats.files || '';
  $('#count-shared').textContent = s.stats.shared || '';
  $('#count-public').textContent = s.stats.public_uploads || '';
  $('#storage-text').textContent = `${formatSize(s.stats.size)} used`;
  $('#site-link').href = s.publicSiteUrl;
  $('#site-url').textContent = s.publicSiteUrl.replace(/^https?:\/\//, '');

  const user = s.user || {};
  // Storage channel and the bot that writes to it
  $('#account-name').textContent = user.name || 'Telegram channel';
  $('#account-handle').textContent = user.username ? `@${user.username}` : '';
  $('#avatar').textContent = (user.name || '?').split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase();

  const children = new Map();
  for (const f of s.folders) {
    const key = f.parent_id ?? 'root';
    if (!children.has(key)) children.set(key, []);
    children.get(key).push(f);
  }
  const items = [];
  const walk = (parent, depth) => {
    for (const f of children.get(parent) ?? []) {
      const link = el('a', {
        class: 'nav-item sub',
        href: folderHref(f.id),
        style: `--depth:${depth}`,
        dataset: { folder: f.id },
      }, icon('folder', 16), el('span', { class: 'label', textContent: f.name }));
      makeDropTarget(link, f.id);
      items.push(link);
      walk(f.id, depth + 1);
    }
  };
  walk('root', 0);
  $('#folder-nav').replaceChildren(...(items.length ? items : [el('div', { class: 'nav-empty', textContent: 'No folders yet' })]));
  renderSidebarActive();
}

function renderSidebarActive() {
  const { route } = state;
  for (const a of document.querySelectorAll('.nav-item')) {
    const active = a.dataset.folder
      ? route.view === 'files' && route.folderId === Number(a.dataset.folder)
      : a.dataset.view === route.view && !(route.view === 'files' && route.folderId);
    a.classList.toggle('active', active);
  }
}

// ---------- Page ----------

function renderSkeleton() {
  $('#folders').replaceChildren();
  $('#empty').hidden = true;
  $('#page-sub').textContent = '';
  renderHead();
  $('#files').replaceChildren(el('div', { class: 'gallery' }, Array.from({ length: 8 }, () => el('div', { class: 'skeleton' }))));
}

function renderHead() {
  const { route } = state;
  const crumbs = $('#crumbs');
  if (route.view === 'files') {
    const parts = [];
    const trail = [{ id: null, name: 'My files' }, ...state.path];
    trail.forEach((p, i) => {
      if (i) parts.push(el('span', { class: 'sep' }, icon('right', 18)));
      parts.push(i === trail.length - 1 ? el('span', { textContent: p.name }) : el('a', { href: folderHref(p.id), textContent: p.name }));
    });
    crumbs.replaceChildren(...parts);
  } else {
    const titles = { all: 'All uploads', shared: 'Shared links', public: 'Public uploads', search: `Results for “${route.q}”` };
    crumbs.replaceChildren(el('span', { textContent: titles[route.view] }));
  }

  const actions = [];
  if (route.view === 'files') {
    actions.push(el('button', { class: 'btn', onclick: () => newFolder(route.folderId) }, icon('folderPlus', 16), 'New folder'));
  }
  if (route.view === 'public' && state.summary) {
    actions.push(el('a', { class: 'btn', href: state.summary.publicSiteUrl, target: '_blank', rel: 'noopener' }, icon('globe', 16), 'Open public site'));
  }
  // The sidebar has the main Upload button; this one is for phones, where the sidebar is hidden
  actions.push(el('button', { class: 'btn primary page-upload', onclick: pickFiles }, icon('upload', 16), 'Upload'));
  $('#page-actions').replaceChildren(...actions);
}

function renderPage() {
  renderHead();
  for (const b of document.querySelectorAll('#view-toggle button')) b.classList.toggle('active', b.dataset.layout === state.layout);
  const { files, folders, route } = state;

  const total = files.reduce((n, f) => n + f.size, 0);
  const counts = [];
  if (folders.length) counts.push(`${folders.length} folder${folders.length === 1 ? '' : 's'}`);
  counts.push(`${files.length} file${files.length === 1 ? '' : 's'}`);
  if (files.length) counts.push(formatSize(total));
  $('#page-sub').textContent = counts.join(' · ');

  $('#folders').replaceChildren(...folders.map(folderCard));

  if (!files.length) {
    $('#files').replaceChildren();
    const empty = $('#empty');
    empty.hidden = Boolean(folders.length);
    const cfg = {
      shared: ['link', 'Nothing shared yet', 'Open any file and create a public link to share it with anyone, even people without Telegram.'],
      public: ['users', 'No public uploads yet', 'Share your public site link. Images that visitors upload appear here, where you can review and delete them.'],
      search: ['search', 'No results', `Nothing matches “${route.q}”. Try a different name.`],
      files: ['upload', route.folderId ? 'This folder is empty' : 'No files here yet', 'Drag and drop files anywhere on the page, paste an image with Ctrl+V, or click Upload.'],
      all: ['upload', 'Upload your first file', 'Drag and drop files anywhere on the page, paste an image with Ctrl+V, or click Upload. Everything is stored on Telegram.'],
    }[route.view];
    const canUpload = route.view === 'files' || route.view === 'all';
    empty.replaceChildren(
      el('div', { class: 'empty-icon' }, icon(cfg[0], 26)),
      el('h3', { textContent: cfg[1] }),
      el('p', { textContent: cfg[2] }),
      canUpload ? el('button', { class: 'btn primary', onclick: pickFiles }, icon('upload', 16), 'Upload files') : '',
    );
    return;
  }

  $('#empty').hidden = true;
  $('#files').replaceChildren(state.layout === 'grid' ? galleryView(files) : listView(files));
}

function folderCard(f) {
  const card = el('div', { class: 'folder-card', onclick: () => (location.hash = folderHref(f.id)) },
    el('div', { class: 'folder-icon' }, icon('folder', 20)),
    el('div', { class: 'text' },
      el('div', { class: 'name', textContent: f.name }),
      el('div', { class: 'muted xs', textContent: `${f.file_count} file${f.file_count === 1 ? '' : 's'}` })),
    el('div', { class: 'tools' },
      el('button', { class: 'icon-btn sm', title: 'Rename', onclick: (e) => { e.stopPropagation(); renameFolder(f); } }, icon('edit', 15)),
      el('button', { class: 'icon-btn sm', title: 'Delete', onclick: (e) => { e.stopPropagation(); deleteFolder(f); } }, icon('trash', 15))),
  );
  makeDropTarget(card, f.id);
  return card;
}

function previewable(f) {
  return f.size <= (state.summary?.cacheMaxFile ?? 25 * 1024 * 1024);
}

// Images get a small server-made preview of any size; animated GIFs use the original to keep moving
const tileImage = (f) => kindOf(f) === 'image' && (f.mime !== 'image/gif' || previewable(f));
const tileSrc = (f) => (f.mime === 'image/gif' ? rawUrl(f) : thumbUrl(f));

function placeholder(f) {
  const kind = kindOf(f);
  return el('div', { class: 'placeholder' }, icon(kindIcon[kind], 34), el('span', { class: 'ext', textContent: (extOf(f.name) || 'file').toUpperCase() }));
}

function statusBadges(f) {
  const badges = [];
  if (f.in_gallery) badges.push(el('span', { class: 'badge public' }, icon('globe', 12), 'In gallery'));
  else if (f.share_token) badges.push(el('span', { class: 'badge' }, icon('link', 12), 'Shared'));
  if (f.source === 'public' && state.route.view !== 'public') badges.push(el('span', { class: 'badge' }, icon('users', 12), 'Visitor'));
  return badges.length ? el('div', { class: 'badges' }, badges) : null;
}

function galleryView(files) {
  return el('div', { class: 'gallery' }, files.map((f, i) => {
    const kind = kindOf(f);
    let media;
    if (tileImage(f)) {
      media = el('img', {
        src: tileSrc(f), alt: f.name, loading: 'lazy', decoding: 'async', draggable: false,
        onload: (e) => e.target.classList.add('loaded'),
        onerror: (e) => e.target.replaceWith(placeholder(f)),
      });
    } else if (kind === 'video' && previewable(f)) {
      media = el('video', { src: `${rawUrl(f)}#t=0.5`, preload: 'metadata', muted: true, playsInline: true });
    } else {
      media = placeholder(f);
    }

    const tool = (name, title, onclick, cls = '') =>
      el('button', { class: `tool ${cls}`, title, onclick: (e) => { e.stopPropagation(); onclick(); } }, icon(name, 15));

    return el('div', {
      class: `tile kind-${kind}`,
      tabIndex: 0,
      draggable: true,
      onclick: () => openViewer(i),
      onkeydown: (e) => { if (e.key === 'Enter') openViewer(i); },
      ondragstart: (e) => e.dataTransfer.setData('application/x-drive-file', String(f.id)),
    },
      media,
      kind === 'video' && el('div', { class: 'play-badge' }, icon('play', 18)),
      statusBadges(f),
      el('div', { class: 'shade' },
        el('div', { class: 'tools' },
          tool('link', 'Copy share link', () => quickShare(f)),
          tool('download', 'Download', () => (location.href = downloadUrl(f))),
          tool('trash', 'Delete', () => deleteFile(f), 'danger')),
        el('div', { class: 'caption' },
          el('div', { class: 'name', textContent: f.name }),
          el('div', { class: 'meta', textContent: `${formatSize(f.size)} · ${formatDate(f.created_at)}` }))),
    );
  }));
}

function listView(files) {
  const rows = files.map((f, i) => {
    const kind = kindOf(f);
    const thumb = el('div', { class: 'thumb' },
      tileImage(f)
        ? el('img', { src: tileSrc(f), alt: '', loading: 'lazy', draggable: false })
        : icon(kindIcon[kind], 18));
    const tool = (name, title, onclick) =>
      el('button', { class: 'icon-btn sm', title, onclick: (e) => { e.stopPropagation(); onclick(); } }, icon(name, 15));
    const sharing = f.in_gallery
      ? el('span', { class: 'pill public' }, icon('globe', 12), 'In gallery')
      : f.share_token ? el('span', { class: 'pill' }, icon('link', 12), 'Link') : el('span', { class: 'muted', textContent: '—' });
    return el('tr', {
      onclick: () => openViewer(i),
      draggable: true,
      ondragstart: (e) => e.dataTransfer.setData('application/x-drive-file', String(f.id)),
    },
      el('td', {}, el('div', { class: 'name-cell' }, thumb, el('span', { class: 'label', textContent: f.name }))),
      el('td', { class: 'hide-sm' }, sharing),
      el('td', { class: 'hide-sm muted', textContent: f.source === 'public' ? 'Visitor' : 'You' }),
      el('td', { class: 'num hide-sm muted', textContent: formatDate(f.created_at) }),
      el('td', { class: 'num muted', textContent: formatSize(f.size) }),
      el('td', {}, el('div', { class: 'row-tools' },
        tool('link', 'Copy share link', () => quickShare(f)),
        tool('download', 'Download', () => (location.href = downloadUrl(f))),
        tool('trash', 'Delete', () => deleteFile(f)))),
    );
  });
  return el('table', { class: 'table' },
    el('thead', {}, el('tr', {},
      el('th', { textContent: 'Name' }),
      el('th', { class: 'hide-sm', textContent: 'Sharing' }),
      el('th', { class: 'hide-sm', textContent: 'Uploaded by' }),
      el('th', { class: 'num hide-sm', textContent: 'Uploaded' }),
      el('th', { class: 'num', textContent: 'Size' }),
      el('th', {}))),
    el('tbody', {}, rows));
}

// Folders accept files dragged from the gallery and move them there.
// Files dragged in from the desktop fall through to the page-wide upload handler.
function makeDropTarget(node, folderId) {
  const isMove = (e) => e.dataTransfer.types.includes('application/x-drive-file');
  node.addEventListener('dragover', (e) => {
    if (!isMove(e)) return;
    e.preventDefault();
    node.classList.add('drop-hover');
  });
  node.addEventListener('dragleave', () => node.classList.remove('drop-hover'));
  node.addEventListener('drop', async (e) => {
    node.classList.remove('drop-hover');
    if (!isMove(e)) return;
    e.preventDefault();
    e.stopPropagation();
    const id = Number(e.dataTransfer.getData('application/x-drive-file'));
    try {
      await api(`api/files/${id}`, { method: 'PATCH', json: { folderId } });
      toast(`Moved to ${folderName(folderId)}`);
      refresh();
    } catch (err) {
      fail(err);
    }
  });
}

// ---------- Actions ----------

async function newFolder(parentId) {
  const name = await promptDialog('New folder', 'Folder name', '', 'Create');
  if (!name) return;
  try {
    await api('api/folders', { json: { name, parentId } });
    toast(`Created “${name}”`);
    refresh();
  } catch (e) {
    fail(e);
  }
}

async function renameFolder(f) {
  const name = await promptDialog('Rename folder', 'Folder name', f.name);
  if (!name || name === f.name) return;
  try {
    await api(`api/folders/${f.id}`, { method: 'PATCH', json: { name } });
    refresh();
  } catch (e) {
    fail(e);
  }
}

async function deleteFolder(f) {
  if (!(await confirmDialog(`Delete “${f.name}”?`, 'Only empty folders can be deleted.', 'Delete folder'))) return;
  try {
    await api(`api/folders/${f.id}`, { method: 'DELETE' });
    toast('Folder deleted');
    refresh();
  } catch (e) {
    fail(e);
  }
}

async function deleteFile(f) {
  const ok = await confirmDialog(
    `Delete “${f.name}”?`,
    'The file is removed from your drive, the public gallery, and Telegram. Its links stop working.',
    'Delete',
  );
  if (!ok) return false;
  try {
    await api(`api/files/${f.id}`, { method: 'DELETE' });
    state.files = state.files.filter((x) => x.id !== f.id);
    toast('File deleted');
    renderPage();
    loadSummary();
    return true;
  } catch (e) {
    fail(e);
    return false;
  }
}

async function renameFile(f) {
  const name = await promptDialog('Rename file', 'File name', f.name);
  if (!name || name === f.name) return;
  try {
    replaceFile(await api(`api/files/${f.id}`, { method: 'PATCH', json: { name } }));
    renderPage();
    renderViewer();
  } catch (e) {
    fail(e);
  }
}

async function moveFile(f) {
  const folders = state.summary?.folders ?? [];
  const depth = (id) => {
    let d = 0;
    for (let p = folders.find((x) => x.id === id); p?.parent_id != null; p = folders.find((x) => x.id === p.parent_id)) d++;
    return d;
  };
  const ordered = [];
  const walk = (parent) => {
    for (const x of folders.filter((x) => (x.parent_id ?? null) === parent)) {
      ordered.push(x);
      walk(x.id);
    }
  };
  walk(null);

  const select = el('select', { name: 'folder', class: 'input', id: 'move-select' },
    el('option', { value: '', textContent: 'My files', selected: f.folder_id == null }),
    ordered.map((x) => el('option', {
      value: String(x.id),
      textContent: `${'   '.repeat(depth(x.id) + 1)}${x.name}`,
      selected: x.id === f.folder_id,
    })));
  const data = await openModal({
    title: `Move “${f.name}”`,
    confirmLabel: 'Move',
    fields: [el('div', { class: 'field' }, el('label', { textContent: 'Destination', htmlFor: 'move-select' }), select)],
  });
  if (!data) return;
  const folderId = data.get('folder') ? Number(data.get('folder')) : null;
  try {
    const updated = await api(`api/files/${f.id}`, { method: 'PATCH', json: { folderId } });
    toast(`Moved to ${folderName(folderId)}`);
    if (state.route.view === 'files' && state.route.folderId !== folderId) {
      state.files = state.files.filter((x) => x.id !== f.id);
      viewer.close();
    } else {
      replaceFile(updated);
      renderViewer();
    }
    renderPage();
    loadSummary();
  } catch (e) {
    fail(e);
  }
}

async function ensureShared(f) {
  if (f.share_url) return f;
  const updated = await api(`api/files/${f.id}/share`, { method: 'POST' });
  replaceFile(updated);
  loadSummary();
  return updated;
}

async function quickShare(f) {
  try {
    const shared = await ensureShared(f);
    renderPage();
    copyText(kindOf(f) === 'image' ? shared.direct_url : shared.share_url, 'Share link copied');
  } catch (e) {
    fail(e);
  }
}

async function stopSharing(f) {
  const ok = await confirmDialog(
    'Stop sharing?',
    f.in_gallery
      ? 'The link stops working and the image is removed from the public gallery.'
      : 'Anyone with the link will no longer be able to open this file.',
    'Stop sharing',
  );
  if (!ok) return;
  try {
    replaceFile(await api(`api/files/${f.id}/share`, { method: 'DELETE' }));
    if (state.route.view === 'shared') {
      state.files = state.files.filter((x) => x.id !== f.id);
      viewer.close();
    } else {
      renderViewer();
    }
    renderPage();
    loadSummary();
    toast('Link turned off');
  } catch (e) {
    fail(e);
  }
}

async function setInGallery(f, inGallery) {
  try {
    replaceFile(await api(`api/files/${f.id}`, { method: 'PATCH', json: { inGallery } }));
    renderPage();
    renderViewer();
    loadSummary();
    toast(inGallery ? 'Shown in the public gallery' : 'Hidden from the public gallery');
  } catch (e) {
    fail(e);
  }
}

// ---------- Viewer ----------

const viewer = $('#viewer');

function openViewer(index) {
  state.viewerIndex = index;
  renderViewer();
  if (!viewer.open) viewer.showModal();
}

function shareFormats(f) {
  const image = kindOf(f) === 'image';
  const name = f.name.replace(/[\[\]<>"]/g, '');
  return {
    link: ['Link', f.share_url],
    direct: ['Direct', f.direct_url],
    markdown: ['Markdown', image ? `![${name}](${f.direct_url})` : `[${name}](${f.share_url})`],
    html: ['HTML', image ? `<img src="${f.direct_url}" alt="${name}">` : `<a href="${f.share_url}">${name}</a>`],
    bbcode: ['BBCode', image ? `[img]${f.direct_url}[/img]` : `[url=${f.share_url}]${name}[/url]`],
  };
}

function gallerySwitch(f) {
  const image = kindOf(f) === 'image';
  const on = Boolean(f.in_gallery);
  return el('div', {
    class: `toggle-row${image ? '' : ' disabled'}`,
    role: 'switch',
    tabIndex: image ? 0 : -1,
    'aria-checked': String(on),
    onclick: () => image && setInGallery(f, !on),
    onkeydown: (e) => { if (image && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); setInGallery(f, !on); } },
  },
    icon('globe', 18),
    el('div', { class: 'text' },
      el('strong', { textContent: 'Public gallery' }),
      el('span', { class: 'muted xs', textContent: image ? (on ? 'Visible on the public site' : 'Not listed on the public site') : 'Only images can be listed' })),
    el('span', { class: `switch${on ? ' on' : ''}` }));
}

function shareSection(f) {
  if (!f.share_url) {
    return el('div', { class: 'share-box' },
      el('p', { textContent: 'Create a public link that anyone can open in their browser, even without Telegram.' }),
      el('button', {
        class: 'btn primary',
        onclick: async () => {
          try {
            const shared = await ensureShared(f);
            renderPage();
            renderViewer();
            copyText(shared.share_url, 'Link created and copied');
          } catch (e) {
            fail(e);
          }
        },
      }, icon('link', 16), 'Create share link'));
  }

  const formats = shareFormats(f);
  if (!formats[state.shareFormat]) state.shareFormat = 'link';
  const input = el('input', { class: 'input', readOnly: true, value: formats[state.shareFormat][1], onfocus: (e) => e.target.select() });
  const tabs = el('div', { class: 'tabs' }, Object.entries(formats).map(([key, [label]]) =>
    el('button', {
      class: key === state.shareFormat ? 'active' : '',
      textContent: label,
      onclick: (e) => {
        state.shareFormat = key;
        store('shareFormat', key);
        input.value = formats[key][1];
        for (const b of tabs.children) b.classList.toggle('active', b === e.currentTarget);
      },
    })));

  return el('div', { class: 'share-box' },
    tabs,
    el('div', { class: 'copy-row' },
      input,
      el('button', { class: 'btn primary', title: 'Copy', onclick: () => copyText(input.value) }, icon('copy', 15))),
    el('div', { class: 'share-foot' },
      el('a', { class: 'muted sm', href: f.share_url, target: '_blank', rel: 'noopener' }, icon('external', 14), 'Open public page'),
      el('button', { class: 'link-btn', textContent: 'Stop sharing', onclick: () => stopSharing(f) })),
  );
}

function renderViewer() {
  const files = state.files;
  const i = state.viewerIndex;
  const f = files[i];
  if (!f) {
    if (viewer.open) viewer.close();
    return;
  }
  const kind = kindOf(f);

  let media;
  if (kind === 'image') media = el('img', { src: rawUrl(f), alt: f.name });
  else if (kind === 'video') media = el('video', { src: rawUrl(f), controls: true, autoplay: true, playsInline: true });
  else if (kind === 'audio') media = el('audio', { src: rawUrl(f), controls: true, autoplay: true });
  else {
    media = el('div', { class: 'no-preview' },
      el('div', { class: 'big-ext', textContent: (extOf(f.name) || 'file').toUpperCase() }),
      el('div', { textContent: 'No preview available for this file type' }),
      el('a', { class: 'btn primary', href: downloadUrl(f) }, icon('download', 16), 'Download'));
  }

  const stage = el('div', { class: 'stage', onclick: (e) => { if (e.target === stage) viewer.close(); } },
    el('button', { class: 'icon-btn stage-close', title: 'Close (Esc)', onclick: () => viewer.close() }, icon('x', 20)),
    files.length > 1 && el('div', { class: 'stage-counter', textContent: `${i + 1} / ${files.length}` }),
    el('button', { class: 'stage-btn prev', title: 'Previous (←)', disabled: i === 0, onclick: () => openViewer(i - 1) }, icon('left', 22)),
    media,
    el('button', { class: 'stage-btn next', title: 'Next (→)', disabled: i === files.length - 1, onclick: () => openViewer(i + 1) }, icon('right', 22)),
  );

  const details = [
    ['Size', formatSize(f.size)],
    f.width && ['Dimensions', `${f.width} × ${f.height}`],
    ['Type', f.mime || 'Unknown'],
    ['Uploaded', formatDate(f.created_at, true)],
    ['Uploaded by', f.source === 'public' ? 'Visitor (public site)' : 'You'],
    f.uploader_ip && ['Uploader IP', f.uploader_ip],
  ].filter(Boolean);

  const panel = el('aside', { class: 'panel' },
    el('div', { class: 'panel-title' },
      el('h2', { textContent: f.name }),
      el('button', { class: 'icon-btn sm', title: 'Rename', onclick: () => renameFile(f) }, icon('edit', 15))),
    f.source === 'public' && el('div', { class: 'notice' }, icon('users', 16),
      el('span', { textContent: 'Uploaded by a visitor on your public site. Delete it if it breaks your rules.' })),
    el('dl', { class: 'details' },
      details.map(([k, v]) => [el('dt', { textContent: k }), el('dd', { textContent: v })]),
      el('dt', { textContent: 'Folder' }), el('dd', {}, el('a', {
        href: folderHref(f.folder_id), textContent: folderName(f.folder_id), style: 'color:var(--accent)',
        onclick: () => viewer.close(),
      }))),
    el('div', { class: 'panel-actions' },
      el('a', { class: 'btn primary', href: downloadUrl(f) }, icon('download', 16), 'Download'),
      el('a', { class: 'btn', href: rawUrl(f), target: '_blank', rel: 'noopener' }, icon('external', 16), 'Original'),
      el('button', { class: 'btn', onclick: () => moveFile(f) }, icon('move', 16), 'Move'),
      el('button', {
        class: 'btn danger',
        onclick: async () => {
          if (await deleteFile(f)) {
            state.viewerIndex = Math.min(i, state.files.length - 1);
            renderViewer();
          }
        },
      }, icon('trash', 16), 'Delete')),
    el('div', {}, el('p', { class: 'section-title', textContent: 'Share' }), gallerySwitch(f), el('div', { style: 'height:10px' }), shareSection(f)),
  );

  viewer.replaceChildren(el('div', { class: 'viewer-inner' }, stage, panel));
}

viewer.addEventListener('keydown', (e) => {
  if (e.target.matches('input, textarea, select')) return;
  if (e.key === 'ArrowLeft' && state.viewerIndex > 0) openViewer(state.viewerIndex - 1);
  if (e.key === 'ArrowRight' && state.viewerIndex < state.files.length - 1) openViewer(state.viewerIndex + 1);
});
viewer.addEventListener('close', () => viewer.replaceChildren());

// ---------- Uploads ----------

const uploads = [];
let uploading = false;

function pickFiles() {
  $('#file-input').click();
}

function enqueue(fileList) {
  const files = [...fileList];
  if (!files.length) return;
  const folderId = uploadTarget();
  const max = state.summary?.maxFileSize ?? Infinity;

  for (const file of files) {
    const item = { file, folderId, status: 'queued' };
    const preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
    item.bar = el('div');
    item.statusText = el('div', { class: 'status', textContent: 'Waiting…' });
    item.stateIcon = el('span', { class: 'state-icon' });
    item.node = el('div', { class: 'upload-item' },
      el('div', { class: 'thumb' }, preview ? el('img', { src: preview, alt: '' }) : icon('file', 16)),
      el('div', { class: 'body' },
        el('div', { class: 'name', textContent: file.name }),
        item.statusText,
        el('div', { class: 'bar' }, item.bar)),
      item.stateIcon);
    uploads.push(item);
    $('#upload-list').append(item.node);
    if (file.size > max) setUpload(item, 'failed', `Too large (max ${formatSize(max)})`);
  }
  $('#upload-panel').hidden = false;
  updateUploadTitle();
  processQueue();
}

function setUpload(item, status, text, progress) {
  item.status = status;
  item.node.className = `upload-item ${status}`;
  item.statusText.textContent = text;
  const bar = item.bar.parentElement;
  bar.classList.toggle('indeterminate', status === 'sending');
  bar.hidden = status === 'done' || status === 'failed';
  if (progress != null) item.bar.style.width = `${progress}%`;
  item.stateIcon.replaceChildren(status === 'done' ? icon('check', 18) : status === 'failed' ? icon('alert', 18) : '');
  updateUploadTitle();
}

function updateUploadTitle() {
  const active = uploads.filter((u) => ['queued', 'uploading', 'sending'].includes(u.status)).length;
  const done = uploads.filter((u) => u.status === 'done').length;
  const failed = uploads.filter((u) => u.status === 'failed').length;
  $('#upload-title').textContent = active
    ? `Uploading ${uploads.length - active + 1} of ${uploads.length}`
    : [done && `${done} upload${done === 1 ? '' : 's'} complete`, failed && `${failed} failed`].filter(Boolean).join(' · ');
}

async function processQueue() {
  if (uploading) return;
  uploading = true;
  let next;
  while ((next = uploads.find((u) => u.status === 'queued'))) {
    await uploadOne(next);
    refresh();
  }
  uploading = false;
}

function uploadOne(item) {
  return new Promise((resolve) => {
    const form = new FormData();
    form.append('folderId', item.folderId ?? '');
    form.append('files', item.file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/files');
    setUpload(item, 'uploading', 'Uploading… 0%', 0);
    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const pct = Math.round((e.loaded / e.total) * 100);
      if (pct < 100) setUpload(item, 'uploading', `Uploading… ${pct}% · ${formatSize(e.loaded)} of ${formatSize(e.total)}`, pct);
      else setUpload(item, 'sending', 'Saving to Telegram…', 100);
    };
    xhr.onload = () => {
      if (xhr.status < 300) {
        setUpload(item, 'done', `Uploaded · ${formatSize(item.file.size)}`, 100);
      } else {
        let msg = `Failed (${xhr.status})`;
        try { msg = JSON.parse(xhr.responseText).error || msg; } catch {}
        setUpload(item, 'failed', msg);
      }
      resolve();
    };
    xhr.onerror = () => {
      setUpload(item, 'failed', 'Network error');
      resolve();
    };
    xhr.send(form);
  });
}

$('#upload-close').onclick = () => {
  for (let i = uploads.length - 1; i >= 0; i--) {
    if (uploads[i].status === 'done' || uploads[i].status === 'failed') {
      uploads[i].node.remove();
      uploads.splice(i, 1);
    }
  }
  if (!uploads.length) $('#upload-panel').hidden = true;
  else updateUploadTitle();
};

$('#file-input').onchange = (e) => {
  enqueue(e.target.files);
  e.target.value = '';
};

// Drag files from the desktop anywhere onto the page
let dragDepth = 0;
const hasFiles = (e) => e.dataTransfer?.types.includes('Files');
window.addEventListener('dragenter', (e) => {
  if (!hasFiles(e) || $('#app').hidden) return;
  dragDepth++;
  $('#drop-target').textContent = `Files will be added to ${folderName(uploadTarget())}`;
  $('#drop-overlay').hidden = false;
});
window.addEventListener('dragleave', (e) => {
  if (!hasFiles(e)) return;
  if (--dragDepth <= 0) {
    dragDepth = 0;
    $('#drop-overlay').hidden = true;
  }
});
window.addEventListener('dragover', (e) => {
  if (hasFiles(e)) e.preventDefault();
});
window.addEventListener('drop', (e) => {
  if (!hasFiles(e)) return;
  e.preventDefault();
  dragDepth = 0;
  $('#drop-overlay').hidden = true;
  if (!$('#app').hidden) enqueue(e.dataTransfer.files);
});

// Paste images from the clipboard
document.addEventListener('paste', (e) => {
  if ($('#app').hidden || !e.clipboardData?.files.length) return;
  e.preventDefault();
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  enqueue([...e.clipboardData.files].map((f, i) =>
    f.name === 'image.png' ? new File([f], `pasted-${stamp}${i ? `-${i}` : ''}.png`, { type: f.type }) : f));
});

// ---------- Top bar ----------

let searchTimer;
$('#search').addEventListener('input', (e) => {
  clearTimeout(searchTimer);
  const value = e.target.value.trim();
  searchTimer = setTimeout(() => {
    if (value) location.hash = `#/search/${encodeURIComponent(value)}`;
    else if (state.route.view === 'search') location.hash = '#/';
  }, 250);
});

document.addEventListener('keydown', (e) => {
  if (e.key === '/' && !e.target.matches('input, textarea, select') && !$('#app').hidden && !document.querySelector('dialog[open]')) {
    e.preventDefault();
    $('#search').focus();
  }
});

for (const b of document.querySelectorAll('#view-toggle button')) {
  b.onclick = () => {
    state.layout = b.dataset.layout;
    store('layout', state.layout);
    renderPage();
  };
}

$('#upload-btn').onclick = pickFiles;
$('#sidebar-new-folder').onclick = () => newFolder(uploadTarget());
$('#menu-btn').onclick = () => $('#app').classList.toggle('nav-open');
$('#scrim').onclick = () => $('#app').classList.remove('nav-open');
$('#sidebar').addEventListener('click', (e) => {
  if (e.target.closest('a')) $('#app').classList.remove('nav-open');
});
// "My files" in the sidebar also accepts files dragged onto it
makeDropTarget(document.querySelector('[data-view="files"]'), null);

window.addEventListener('hashchange', () => {
  if (!$('#app').hidden) loadRoute();
});

// ---------- Login ----------

let pollTimer;
let lastLoginKey;

// Polls the login state; applyAuth decides whether to show the login screen or the app
function startLogin() {
  if (viewer.open) viewer.close();
  clearInterval(pollTimer);
  pollTimer = setInterval(pollAuth, 2000);
  pollAuth();
}

async function pollAuth() {
  try {
    applyAuth(await api('api/auth/status'));
  } catch {
    // server unreachable; keep polling
  }
}

function applyAuth(status) {
  if (status.status === 'ready') {
    clearInterval(pollTimer);
    lastLoginKey = null;
    if ($('#app').hidden) startApp();
    return;
  }
  $('#app').hidden = true;
  $('#login-screen').hidden = false;
  // Only re-render when something changed, so typing in a form isn't interrupted. A poll that
  // returns the same screen without an error must not wipe an error the user just got.
  const key = JSON.stringify(status);
  const prev = JSON.parse(lastLoginKey || '{}');
  if (key === lastLoginKey) return;
  if (!status.error && prev.error && JSON.stringify({ ...prev, error: undefined }) === key) return;
  lastLoginKey = key;
  $('#login-body').replaceChildren(...loginView(status));
  $('#login-body').querySelector('input')?.focus();
}

async function authPost(url, body) {
  try {
    applyAuth(await api(url, { json: body ?? {} }));
  } catch (e) {
    const prev = JSON.parse(lastLoginKey || '{}');
    lastLoginKey = null;
    applyAuth({ ...prev, error: e.message });
  }
}

function errorBox(status) {
  return status.error ? el('div', { class: 'form-error' }, icon('alert', 16), el('span', { textContent: status.error })) : '';
}

function field(label, input) {
  return el('div', { class: 'field' }, el('label', { textContent: label, htmlFor: input.id }), input);
}

function loginView(status) {
  switch (status.status) {
    case 'admin': {
      const input = el('input', { id: 'admin-password', class: 'input', type: 'password', placeholder: 'Admin password', required: true, autocomplete: 'current-password' });
      const form = el('form', {
        onsubmit: (e) => {
          e.preventDefault();
          authPost('api/admin/login', { password: input.value });
        },
      }, field('Password', input), el('button', { class: 'btn primary btn-block', textContent: 'Sign in' }));
      return [
        el('h2', { textContent: 'Admin sign in' }),
        el('p', { class: 'lead', textContent: 'Enter the admin password set on your server.' }),
        errorBox(status),
        form,
      ];
    }
    // Bot or channel not set up correctly; the message says what to fix in app/config.php
    case 'error':
      return [
        el('h2', { textContent: 'Telegram storage isn’t ready' }),
        el('p', { class: 'lead', textContent: 'Fix the problem below, then try again.' }),
        errorBox(status),
        el('button', { class: 'btn primary btn-block', textContent: 'Try again', onclick: () => authPost('api/auth/retry') }),
      ];
    default:
      return [
        el('div', { class: 'spinner' }),
        el('p', { class: 'lead', textContent: 'Connecting to Telegram…' }),
      ];
  }
}

// Signs out of the admin; the public site keeps working
$('#logout').onclick = async () => {
  await authPost('api/admin/logout');
  startLogin();
};

// ---------- Boot ----------

async function startApp() {
  $('#login-screen').hidden = true;
  $('#app').hidden = false;
  await loadSummary();
  loadRoute();
}

hydrateIcons();
startLogin();
