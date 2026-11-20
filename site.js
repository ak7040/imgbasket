// Public homepage: upload images
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
  const formats = img.kind === 'video'
    ? [
        ['Link', img.page_url],
        ['Direct', img.direct_url],
        ['Markdown', `[${name}](${img.direct_url})`],
        ['HTML', `<video src="${img.direct_url}" controls></video>`],
        ['BBCode', `[url=${img.page_url}]${name}[/url]`],
      ]
    : [
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
      el('a', { class: 'btn', href: img.page_url, target: '_blank', rel: 'noopener', title: 'Open' }, icon('external', 16))),
    qrBox(img));
}

// Draws a QR code for text onto a canvas (black on white, so every scanner can read it)
function qrCanvas(text, scale = 8, margin = 4) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const count = qr.getModuleCount();
  const size = (count + margin * 2) * scale;
  const canvas = el('canvas', { width: size, height: size });
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, size, size);
  ctx.fillStyle = '#000';
  for (let r = 0; r < count; r++) {
    for (let c = 0; c < count; c++) {
      if (qr.isDark(r, c)) ctx.fillRect((c + margin) * scale, (r + margin) * scale, scale, scale);
    }
  }
  return canvas;
}

// QR code that downloads the image when scanned with a phone
function qrBox(img) {
  if (typeof qrcode !== 'function' || !img.direct_url) return null;
  const url = `${img.direct_url}?download=1`;
  let canvas;
  try { canvas = qrCanvas(url); } catch { return null; }
  canvas.className = 'qr-code';
  canvas.setAttribute('role', 'img');
  canvas.setAttribute('aria-label', `QR code to download ${img.name}`);
  const base = img.name.replace(/\.[^.]+$/, '').replace(/[^\w.-]+/g, '-') || 'file';
  return el('div', { class: 'qr-box' },
    canvas,
    el('div', { class: 'qr-text' },
      el('strong', { textContent: 'Scan to download' }),
      el('span', { textContent: 'Point your phone camera at the code to save this file.' }),
      el('a', { class: 'btn', href: canvas.toDataURL('image/png'), download: `${base}-qr.png` }, icon('download', 16), 'Save QR')));
}

// ---------- Uploads ----------

let config = { maxUpload: 20 * 1024 * 1024 };
const queue = [];
let uploading = false;

const IMAGE_EXT = /^(jpe?g|png|gif|webp|avif|bmp)$/;
const VIDEO_EXT = /^(mp4|webm|mov|m4v|ogv|ogg|mkv|avi|mpg|mpeg|3gp|flv|wmv)$/;

// Image or video, from the browser's type or (when it has none) the file name
function mediaKind(file) {
  if (file.type.startsWith('image/')) return 'image';
  if (file.type.startsWith('video/')) return 'video';
  if (file.type === '' || file.type === 'application/octet-stream') {
    const ext = (file.name.match(/\.([a-z0-9]+)$/i)?.[1] || '').toLowerCase();
    if (IMAGE_EXT.test(ext)) return 'image';
    if (VIDEO_EXT.test(ext)) return 'video';
  }
  return null;
}

function enqueue(fileList) {
  const files = [...fileList];
  for (const file of files) {
    const item = { file };
    const kind = mediaKind(file);
    const previewUrl = kind ? URL.createObjectURL(file) : null;
    const preview = previewUrl && (kind === 'image'
      ? el('img', { src: previewUrl, alt: '' })
      : el('video', {
          src: previewUrl, muted: true, playsInline: true, preload: 'metadata', 'aria-hidden': 'true',
          onloadedmetadata: (e) => { try { e.target.currentTime = Math.min(0.1, e.target.duration || 0.1); } catch {} },
        }));
    item.status = el('span', { class: 'status', textContent: 'Waiting…' });
    item.bar = el('div');
    item.body = el('div', { class: 'body' },
      el('div', { class: 'top' }, el('span', { class: 'name', textContent: file.name }), item.status),
      el('div', { class: 'bar' }, item.bar));
    item.node = el('div', { class: 'result' },
      el('div', { class: 'thumb' }, preview, kind === 'video' && el('span', { class: 'thumb-play' }, icon('play', 18))),
      item.body);
    $('#results').prepend(item.node);

    if (!kind) fail(item, 'Only images and videos can be uploaded');
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
    form.append('file', item.file);
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
        item.status.textContent = 'Uploaded';
        item.bar.parentElement.remove();
        item.body.append(shareBox(body));
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
$('#hero').onclick = pick;
$('#hero').onkeydown = (e) => {
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

// ---------- Boot ----------

hydrateIcons();
fetch('/api/config')
  .then((r) => r.json())
  .then((c) => {
    config = c;
    $('#limits').replaceChildren(
      `JPG, PNG, GIF, WebP, AVIF, BMP · MP4, WebM, MOV · up to ${formatSize(c.maxUpload)} · `,
      el('kbd', { textContent: 'Ctrl' }), ' + ', el('kbd', { textContent: 'V' }), ' to paste');
  })
  .catch(() => {});
