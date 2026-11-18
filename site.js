// Public homepage: upload images and browse the gallery
const $ = (sel) => document.querySelector(sel);

function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v == null || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'style') node.style.cssText = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (k in node) node[k] = v;
    else node.setAttribute(k, v);
  }
  node.append(...children.flat(Infinity).filter((c) => c != null && c !== false));
  return node;
}

function formatSize(bytes) {
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
  return `${+bytes.toFixed(i && bytes < 100 ? 1 : 0)} ${units[i]}`;
}

function timeAgo(sqlDate) {
  const s = (Date.now() - new Date(sqlDate.replace(' ', 'T') + 'Z')) / 1000;
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  if (s < 86400 * 30) return `${Math.floor(s / 86400)}d ago`;
  return new Date(sqlDate.replace(' ', 'T') + 'Z').toLocaleDateString();
}

function toast(message, { error = false } = {}) {
  const node = el('div', { class: `toast${error ? ' error' : ''}` }, icon(error ? 'alert' : 'check', 16), message);
  $('#toasts').append(node);
  setTimeout(() => node.remove(), 2800);
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast('Copied to clipboard');
  } catch {
    toast('Could not copy. Select the text and copy it manually.', { error: true });
  }
}

// Link formats offered after an upload
function shareBox(img) {
  const name = img.name.replace(/[\[\]<>"]/g, '');
  const formats = [
    ['Link', img.page_url],
    ['Direct', img.direct_url],
    ['Markdown', `![${name}](${img.direct_url})`],
    ['HTML', `<img src="${img.direct_url}" alt="${name}">`],
    ['BBCode', `[img]${img.direct_url}[/img]`],
  ];
  const input = el('input', { readOnly: true, value: formats[0][1], onfocus: (e) => e.target.select() });
  const tabs = el('div', { class: 'tabs' }, formats.map(([label, value], i) =>
    el('button', {
      class: i ? '' : 'active',
      textContent: label,
      onclick: (e) => {
        input.value = value;
        for (const b of tabs.children) b.classList.toggle('active', b === e.currentTarget);
      },
    })));
  return el('div', { class: 'share-box' },
    tabs,
    el('div', { class: 'copy-row' },
      input,
      el('button', { class: 'btn primary', title: 'Copy', onclick: () => copyText(input.value) }, icon('copy', 16), 'Copy'),
      el('a', { class: 'btn', href: img.page_url, target: '_blank', rel: 'noopener', title: 'Open' }, icon('external', 16))));
}

// ---------- Uploads ----------

let config = { maxUpload: 20 * 1024 * 1024 };
const queue = [];
let uploading = false;

function enqueue(fileList) {
  const files = [...fileList];
  for (const file of files) {
    // Captured now, so changing the switch later doesn't affect images already queued
    const item = { file, isPublic: $('#public-toggle').checked };
    const preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
    item.status = el('span', { class: 'status', textContent: 'Waiting…' });
    item.bar = el('div');
    item.body = el('div', { class: 'body' },
      el('div', { class: 'top' }, el('span', { class: 'name', textContent: file.name }), item.status),
      el('div', { class: 'bar' }, item.bar));
    item.node = el('div', { class: 'result' },
      el('div', { class: 'thumb' }, preview && el('img', { src: preview, alt: '' })),
      item.body);
    $('#results').prepend(item.node);

    if (!file.type.startsWith('image/')) fail(item, 'Only images can be uploaded');
    else if (file.size > config.maxUpload) fail(item, `Too large (max ${formatSize(config.maxUpload)})`);
    else queue.push(item);
  }
  processQueue();
}

function fail(item, message) {
  item.node.classList.add('failed');
  item.status.textContent = message;
  item.bar.parentElement.hidden = true;
}

async function processQueue() {
  if (uploading) return;
  uploading = true;
  while (queue.length) await upload(queue.shift());
  uploading = false;
}

function upload(item) {
  return new Promise((resolve) => {
    const form = new FormData();
    // Fields must come before the file so the server sees them when the upload arrives
    form.append('public', item.isPublic ? '1' : '0');
    form.append('image', item.file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/upload');
    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      const pct = Math.round((e.loaded / e.total) * 100);
      item.bar.style.width = `${pct}%`;
      if (pct < 100) {
        item.status.textContent = `Uploading… ${pct}%`;
      } else {
        item.status.textContent = 'Publishing…';
        item.bar.parentElement.classList.add('indeterminate');
      }
    };
    xhr.onload = () => {
      let body = {};
      try { body = JSON.parse(xhr.responseText); } catch {}
      if (xhr.status >= 300) {
        fail(item, body.error || `Upload failed (${xhr.status})`);
      } else {
        item.node.classList.add('done');
        item.status.textContent = body.in_gallery ? 'Published' : 'Uploaded · Unlisted';
        item.bar.parentElement.remove();
        item.body.append(shareBox(body));
        if (body.in_gallery) gallery.prepend(body);
      }
      resolve();
    };
    xhr.onerror = () => {
      fail(item, 'Network error. Check your connection.');
      resolve();
    };
    xhr.send(form);
  });
}

$('#file-input').onchange = (e) => {
  enqueue(e.target.files);
  e.target.value = '';
};
const pick = () => $('#file-input').click();
$('#hero').onclick = (e) => {
  if (!e.target.closest('#visibility')) pick();
};
$('#hero').onkeydown = (e) => {
  if (e.target.closest('#visibility')) return;
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
};
$('#header-upload').onclick = pick;

// Drag files anywhere on the page
let dragDepth = 0;
const hasFiles = (e) => e.dataTransfer?.types.includes('Files');
window.addEventListener('dragenter', (e) => {
  if (!hasFiles(e)) return;
  dragDepth++;
  $('#drop-overlay').hidden = false;
});
window.addEventListener('dragleave', (e) => {
  if (hasFiles(e) && --dragDepth <= 0) {
    dragDepth = 0;
    $('#drop-overlay').hidden = true;
  }
});
window.addEventListener('dragover', (e) => { if (hasFiles(e)) e.preventDefault(); });
window.addEventListener('drop', (e) => {
  if (!hasFiles(e)) return;
  e.preventDefault();
  dragDepth = 0;
  $('#drop-overlay').hidden = true;
  enqueue(e.dataTransfer.files);
});

// Paste screenshots straight from the clipboard
document.addEventListener('paste', (e) => {
  const files = [...(e.clipboardData?.files ?? [])];
  if (!files.length) return;
  e.preventDefault();
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
  enqueue(files.map((f, i) => (f.name === 'image.png' ? new File([f], `pasted-${stamp}${i ? `-${i}` : ''}.png`, { type: f.type }) : f)));
});

// ---------- Gallery (masonry with infinite scroll) ----------

const gallery = {
  items: [],
  cursor: null,
  done: false,
  loading: false,
  columns: 0,

  columnCount() {
    const width = $('#gallery').clientWidth;
    if (width < 520) return 2;
    return Math.min(5, Math.max(2, Math.floor((width + 14) / (250 + 14))));
  },

  // Places each card in the currently shortest column
  layout() {
    const count = this.columnCount();
    this.columns = count;
    const cols = Array.from({ length: count }, () => el('div', { class: 'col' }));
    const heights = new Array(count).fill(0);
    for (const item of this.items) {
      const i = heights.indexOf(Math.min(...heights));
      cols[i].append(item.node ??= this.card(item));
      heights[i] += item.height && item.width ? item.height / item.width : 1;
    }
    $('#gallery').replaceChildren(...cols);
    $('#gallery-empty').hidden = this.items.length > 0 || !this.done;
  },

  card(item) {
    const ratio = item.width && item.height ? `${item.width} / ${item.height}` : '1 / 1';
    return el('a', { class: 'card', href: item.page_url, style: `aspect-ratio:${ratio}` },
      el('img', {
        src: item.thumb || item.src,
        alt: item.name,
        loading: 'lazy',
        decoding: 'async',
        onload: (e) => {
          e.target.classList.add('loaded');
          // Browsers apply photo rotation, so trust the rendered size over the stored one
          const { naturalWidth: w, naturalHeight: h } = e.target;
          if (w && h) e.target.parentElement.style.aspectRatio = `${w} / ${h}`;
        },
      }),
      el('div', { class: 'shade' },
        el('div', { class: 'name', textContent: item.name }),
        el('div', { class: 'meta', textContent: `${formatSize(item.size)} · ${timeAgo(item.created_at)}` })));
  },

  async loadMore() {
    if (this.loading || this.done) return;
    this.loading = true;
    $('#loading').hidden = false;
    try {
      const res = await fetch(`/api/gallery${this.cursor ? `?before=${this.cursor}` : ''}`);
      const data = await res.json();
      this.items.push(...data.items);
      this.cursor = data.next;
      this.done = !data.next;
      this.layout();
    } catch {
      toast('Could not load the gallery', { error: true });
    } finally {
      this.loading = false;
      $('#loading').hidden = true;
    }
  },

  prepend(item) {
    this.items.unshift(item);
    this.layout();
  },
};

new IntersectionObserver((entries) => {
  if (entries[0].isIntersecting) gallery.loadMore();
}, { rootMargin: '800px' }).observe($('#sentinel'));

let resizeTimer;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
    if (gallery.columnCount() !== gallery.columns) gallery.layout();
  }, 150);
});

// ---------- Visibility switch ----------

function renderVisibility() {
  const on = $('#public-toggle').checked;
  $('#visibility-hint').textContent = on
    ? 'Anyone visiting this site can see it'
    : 'Unlisted: only people you send the link to can see it';
  $('#drop-hint').textContent = on ? 'Images will be published to the gallery' : 'Images will be unlisted (link only)';
}

$('#public-toggle').onchange = (e) => {
  try { localStorage.setItem('showInGallery', e.target.checked ? '1' : '0'); } catch {}
  renderVisibility();
};
try {
  if (localStorage.getItem('showInGallery') === '0') $('#public-toggle').checked = false;
} catch {}
renderVisibility();

// ---------- Boot ----------

hydrateIcons();
fetch('/api/config')
  .then((r) => r.json())
  .then((c) => {
    config = c;
    $('#limits').replaceChildren(
      `JPG, PNG, GIF, WebP, AVIF · up to ${formatSize(c.maxUpload)} · `,
      el('kbd', { textContent: 'Ctrl' }), ' + ', el('kbd', { textContent: 'V' }), ' to paste');
  })
  .catch(() => {});
gallery.loadMore();
