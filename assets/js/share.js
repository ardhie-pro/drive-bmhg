(() => {
  const app = document.getElementById('sharedApp');
  if (!app) return;
  const permission = app.dataset.permission;
  const rootName = app.dataset.root;
  const grid = document.getElementById('sharedGrid');
  const message = document.getElementById('sharedMessage');
  const crumbs = document.getElementById('sharedBreadcrumbs');
  const input = document.getElementById('sharedUploadInput');
  const dropZone = document.getElementById('sharedDropZone');
  let currentPath = '';

  const api = async (action, params = {}, method = 'GET') => {
    const url = new URL('share-api.php', location.href);
    url.searchParams.set('action', action);
    let options = { method };
    if (method === 'GET') Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    else options.body = params;
    const response = await fetch(url, options);
    if (response.status === 401) { location.href = 'login.php'; throw new Error('Silakan login kembali.'); }
    const result = await response.json();
    if (!response.ok || result.success === false) throw new Error(result.message || `HTTP ${response.status}`);
    return result;
  };

  const fileUrl = (action, path) => {
    const url = new URL('share-api.php', location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('path', path);
    return url.toString();
  };

  function renderBreadcrumbs() {
    crumbs.replaceChildren();
    const root = document.createElement('button');
    root.textContent = rootName;
    root.addEventListener('click', () => navigate(''));
    crumbs.append(root);
    let accumulated = '';
    for (const part of currentPath.split('/').filter(Boolean)) {
      const separator = document.createElement('span');
      separator.textContent = '›';
      crumbs.append(separator);
      accumulated = accumulated ? `${accumulated}/${part}` : part;
      const button = document.createElement('button');
      button.textContent = part;
      button.dataset.path = accumulated;
      button.addEventListener('click', () => navigate(button.dataset.path));
      crumbs.append(button);
    }
  }

  function icon(isDir, isImage) {
    if (isImage) return '<svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
    return isDir ? '<svg viewBox="0 0 24 24" width="42" height="42" fill="#f59e0b"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>' : '<svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/></svg>';
  }

  function renderItems(items) {
    grid.replaceChildren();
    for (const item of items) {
      const card = document.createElement('article');
      card.className = 'shared-card';
      const thumb = document.createElement('div');
      thumb.className = 'shared-thumb';
      if (item.mimeType?.startsWith('image/')) {
        const image = document.createElement('img');
        image.loading = 'lazy';
        image.alt = item.name;
        const thumbnailUrl = fileUrl('thumb', item.path);
        let thumbnailRetries = 0;
        image.onerror = () => {
          if (thumbnailRetries < 3 && image.isConnected) {
            thumbnailRetries++;
            setTimeout(() => { image.src = `${thumbnailUrl}&retry=${thumbnailRetries}`; }, 500 * (2 ** thumbnailRetries));
          } else {
            thumb.innerHTML = icon(false, true);
          }
        };
        image.src = thumbnailUrl;
        thumb.append(image);
      } else thumb.innerHTML = icon(item.isDir, false);
      const main = document.createElement('div');
      main.className = 'shared-card-main';
      const name = document.createElement('div');
      name.className = 'shared-name';
      name.textContent = item.name;
      name.title = item.name;
      const meta = document.createElement('div');
      meta.className = 'shared-meta';
      meta.textContent = item.isDir ? 'Folder' : `${(item.size / 1024 / 1024).toFixed(item.size > 1024 * 1024 ? 1 : 2)} MB · ${item.modified}${item.uploadedBy ? ` · Oleh ${item.uploadedBy}` : ''}`;
      main.append(name, meta);
      card.append(thumb, main);
      const actions = document.createElement('div');
      actions.className = 'shared-card-actions';
      if (item.isDir) {
        const open = document.createElement('button');
        open.className = 'btn btn-secondary';
        open.textContent = 'Buka folder';
        open.addEventListener('click', () => navigate(item.path));
        actions.append(open);
      } else {
        const preview = document.createElement('a');
        preview.className = 'btn btn-secondary';
        preview.textContent = 'Lihat';
        preview.href = fileUrl('preview', item.path);
        preview.target = '_blank';
        preview.rel = 'noopener';
        const download = document.createElement('a');
        download.className = 'btn btn-primary';
        download.textContent = 'Unduh';
        download.href = fileUrl('download', item.path);
        actions.append(preview, download);
      }
      if (permission === 'editor') {
        const rename = document.createElement('button');
        rename.className = 'btn btn-secondary';
        rename.textContent = 'Ubah nama';
        rename.addEventListener('click', async () => {
          const newName = prompt('Nama baru:', item.name);
          if (!newName || newName === item.name) return;
          const data = new FormData(); data.set('path', item.path); data.set('newName', newName);
          try { await api('rename', data, 'POST'); await load(); } catch (error) { message.textContent = error.message; }
        });
        const remove = document.createElement('button');
        remove.className = 'btn btn-secondary';
        remove.textContent = 'Hapus';
        remove.addEventListener('click', async () => {
          if (!confirm(`Hapus “${item.name}” dari folder ini?`)) return;
          const data = new FormData(); data.set('path', item.path);
          try { await api('delete', data, 'POST'); await load(); } catch (error) { message.textContent = error.message; }
        });
        actions.append(rename, remove);
      }
      card.append(actions);
      if (item.isDir) card.querySelector('.shared-thumb').addEventListener('dblclick', () => navigate(item.path));
      grid.append(card);
    }
    if (!items.length) {
      const empty = document.createElement('p');
      empty.textContent = 'Folder ini belum memiliki file.';
      grid.append(empty);
    }
  }

  async function load() {
    renderBreadcrumbs();
    message.textContent = 'Memuat isi folder…';
    try {
      const result = await api('list', { path: currentPath });
      renderItems(result.items);
      message.textContent = `${result.items.length} item`;
    } catch (error) {
      message.textContent = error.message;
      grid.replaceChildren();
    }
  }

  function navigate(path) { currentPath = path; load(); }
  document.getElementById('sharedNewFolder')?.addEventListener('click', async () => {
    const name = prompt('Nama folder baru:');
    if (!name) return;
    const data = new FormData(); data.set('path', currentPath); data.set('name', name);
    try { await api('create_folder', data, 'POST'); await load(); } catch (error) { message.textContent = error.message; }
  });
  document.getElementById('sharedUploadButton')?.addEventListener('click', () => input?.click());
  input?.addEventListener('change', async () => { await uploadFiles(Array.from(input.files || [])); input.value = ''; });

  async function uploadFiles(files) {
    for (const file of files) {
      const chunkSize = 2 * 1024 * 1024;
      const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
      const uploadId = crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}_${Math.random().toString(36).slice(2)}`;
      for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        const data = new FormData();
        data.set('path', currentPath);
        data.set('fileName', file.name);
        data.set('uploadId', uploadId);
        data.set('chunkIndex', String(chunkIndex));
        data.set('totalChunks', String(totalChunks));
        data.set('chunk', file.slice(chunkIndex * chunkSize, Math.min(file.size, (chunkIndex + 1) * chunkSize)), file.name);
        message.textContent = `Mengunggah ${file.name} (${chunkIndex + 1}/${totalChunks})…`;
        try { await api('upload_chunk', data, 'POST'); }
        catch (error) { message.textContent = `Upload ${file.name} gagal: ${error.message}`; return; }
      }
    }
    message.textContent = 'Upload selesai.';
    await load();
  }

  if (dropZone) {
    dropZone.addEventListener('dragover', event => { event.preventDefault(); dropZone.classList.add('active'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('active'));
    dropZone.addEventListener('drop', event => { event.preventDefault(); dropZone.classList.remove('active'); uploadFiles(Array.from(event.dataTransfer?.files || [])); });
  }
  load();
})();
