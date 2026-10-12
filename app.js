document.addEventListener('click', event => {
  const edit = event.target.closest('[data-edit-room]');
  if (edit) {
    const form = document.getElementById('room-' + edit.dataset.editRoom);
    form.classList.toggle('hidden');
    if (!form.classList.contains('hidden')) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  if (event.target.closest('[data-new-room]')) {
    const form = document.getElementById('new-room');
    form.classList.remove('hidden');
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    form.querySelector('input[name="room_name"]').focus();
  }
  if (event.target.closest('[data-cancel-room]')) document.getElementById('new-room').classList.add('hidden');

  const addDate = event.target.closest('[data-add-date]');
  if (addDate) {
    const control = addDate.closest('.date-selection');
    const picker = control.querySelector('[data-date-input]');
    const hidden = control.querySelector('[data-selected-dates]');
    const dates = parseDates(hidden.value);
    if (picker.value && !dates.includes(picker.value)) dates.push(picker.value);
    dates.sort();
    hidden.value = JSON.stringify(dates);
    picker.value = '';
    renderDates(control, dates);
  }

  const removeDate = event.target.closest('[data-remove-date]');
  if (removeDate) {
    const control = removeDate.closest('.date-selection');
    const hidden = control.querySelector('[data-selected-dates]');
    const dates = parseDates(hidden.value).filter(date => date !== removeDate.dataset.removeDate);
    hidden.value = JSON.stringify(dates);
    renderDates(control, dates);
  }
});

document.querySelectorAll('.date-selection').forEach(control => {
  const hidden = control.querySelector('[data-selected-dates]');
  const dates = parseDates(hidden.value);
  hidden.value = JSON.stringify(dates);
  renderDates(control, dates);
});

function parseDates(value) {
  try {
    const dates = JSON.parse(value || '[]');
    return Array.isArray(dates) ? [...new Set(dates.filter(date => /^\d{4}-\d{2}-\d{2}$/.test(date)))].sort() : [];
  } catch { return []; }
}

function renderDates(control, dates) {
  const list = control.querySelector('[data-date-list]');
  list.replaceChildren();
  dates.forEach(date => {
    const chip = document.createElement('span');
    chip.className = 'date-chip';
    const label = document.createElement('span');
    label.textContent = new Date(date + 'T12:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.setAttribute('aria-label', 'Remove ' + label.textContent);
    remove.dataset.removeDate = date;
    chip.append(label, remove);
    list.append(chip);
  });
}

// ==========================================
// Modern Smart Image Uploader & Gallery Management
// ==========================================

function formatFileSize(bytes) {
  if (!bytes || bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / (1024 ** i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function truncateText(str, max = 22) {
  if (!str) return '';
  if (str.length <= max) return str;
  const ext = str.lastIndexOf('.') > 0 ? str.slice(str.lastIndexOf('.')) : '';
  const name = str.slice(0, str.length - ext.length);
  return name.slice(0, Math.max(4, max - ext.length - 3)) + '…' + ext;
}

// Client-Side Canvas Image Compression & Auto-Rotation
async function compressImage(file, maxDimension = 2000, quality = 0.84) {
  if (!file.type.startsWith('image/') || file.type === 'image/svg+xml') {
    return file;
  }
  return new Promise(resolve => {
    const img = new Image();
    const objectUrl = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(objectUrl);
      let { width, height } = img;
      if (!width || !height) {
        resolve(file);
        return;
      }
      let needsResize = false;
      if (width > maxDimension || height > maxDimension) {
        needsResize = true;
        if (width > height) {
          height = Math.round((height * maxDimension) / width);
          width = maxDimension;
        } else {
          width = Math.round((width * maxDimension) / height);
          height = maxDimension;
        }
      } else if (file.size < 350 * 1024 && file.type === 'image/webp') {
        resolve(file);
        return;
      }

      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      if (!ctx) { resolve(file); return; }

      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(img, 0, 0, width, height);

      canvas.toBlob(blob => {
        if (!blob || (blob.size >= file.size && !needsResize)) {
          resolve(file);
        } else {
          const baseName = file.name.replace(/\.[^.]+$/, '');
          const compressed = new File([blob], `${baseName}.webp`, { type: 'image/webp' });
          resolve(compressed);
        }
      }, 'image/webp', quality);
    };
    img.onerror = () => {
      URL.revokeObjectURL(objectUrl);
      resolve(file);
    };
    img.src = objectUrl;
  });
}

// Initialize each Upload Zone Container
document.querySelectorAll('.upload-zone-container').forEach(container => {
  const form = container.closest('form');
  const roomId = container.dataset.roomId || '';
  const dropzone = container.querySelector('.upload-dropzone');
  const fileInput = container.querySelector('.upload-file-input');
  const browseBtn = container.querySelector('.upload-browse-trigger');

  const stagingTray = container.querySelector('[data-staging-tray]');
  const stagingGrid = container.querySelector('[data-staging-grid]');
  const stagingCounter = container.querySelector('[data-staging-counter]');
  const stagingClearBtn = container.querySelector('[data-staging-clear]');
  const stageUploadBtn = container.querySelector('[data-stage-upload]');

  const progressPanel = container.querySelector('[data-progress-panel]');
  const progressStatus = container.querySelector('[data-progress-status]');
  const progressPct = container.querySelector('[data-progress-pct]');
  const progressFill = container.querySelector('[data-progress-fill]');
  const progressSummary = container.querySelector('[data-progress-summary]');
  const progressCancelBtn = container.querySelector('[data-progress-cancel]');
  const alertBox = container.querySelector('[data-upload-alert]');

  let stagedFiles = [];
  let nextStageId = 1;
  let activeRequest = null;

  function showAlert(msg, isError = true) {
    if (!alertBox) return;
    alertBox.className = `upload-toast-alert ${isError ? 'is-error' : 'is-success'}`;
    alertBox.textContent = msg;
    alertBox.classList.remove('hidden');
    if (!isError) {
      setTimeout(() => alertBox.classList.add('hidden'), 4000);
    }
  }

  function hideAlert() {
    if (alertBox) alertBox.classList.add('hidden');
  }

  function renderStaging() {
    if (!stagingTray || !stagingGrid) return;
    if (stagedFiles.length === 0) {
      stagingTray.classList.add('hidden');
      if (fileInput) fileInput.value = '';
      return;
    }

    stagingTray.classList.remove('hidden');
    if (stagingCounter) stagingCounter.textContent = String(stagedFiles.length);

    stagingGrid.replaceChildren();
    stagedFiles.forEach(item => {
      const card = document.createElement('div');
      card.className = 'staging-item';
      card.dataset.stageId = String(item.id);

      const thumb = document.createElement('div');
      thumb.className = 'staging-thumb';

      const img = document.createElement('img');
      img.src = item.previewUrl;
      img.alt = item.originalFile.name;

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'staging-item-remove';
      removeBtn.title = 'Remove this photo';
      removeBtn.setAttribute('aria-label', 'Remove photo ' + item.originalFile.name);
      removeBtn.textContent = '×';
      removeBtn.addEventListener('click', e => {
        e.stopPropagation();
        removeStagedItem(item.id);
      });

      thumb.append(img, removeBtn);

      const meta = document.createElement('div');
      meta.className = 'staging-meta';

      const name = document.createElement('span');
      name.className = 'staging-name';
      name.title = item.originalFile.name;
      name.textContent = truncateText(item.originalFile.name, 16);

      const size = document.createElement('span');
      size.className = 'staging-size';
      if (item.isOptimizing) {
        size.textContent = 'Optimizing…';
      } else {
        const origSize = formatFileSize(item.originalFile.size);
        const optSize = formatFileSize(item.optimizedFile.size);
        size.textContent = item.optimizedFile.size < item.originalFile.size ? `${origSize} → ${optSize}` : origSize;
      }

      meta.append(name, size);
      card.append(thumb, meta);
      stagingGrid.append(card);
    });

    if (stageUploadBtn) {
      stageUploadBtn.textContent = `Upload ${stagedFiles.length} photo${stagedFiles.length === 1 ? '' : 's'} now ⚡`;
    }
  }

  async function addFiles(files) {
    hideAlert();
    const fileArray = [...files].filter(f => f.type.startsWith('image/'));
    if (!fileArray.length) {
      showAlert('Please select JPG, PNG, or WebP images.');
      return;
    }
    if (stagedFiles.length + fileArray.length > 20) {
      showAlert('You can upload up to 20 photos per batch. Some photos were skipped.');
    }

    const availableSlots = 20 - stagedFiles.length;
    const toProcess = fileArray.slice(0, availableSlots);

    for (const file of toProcess) {
      const stageItem = {
        id: nextStageId++,
        originalFile: file,
        optimizedFile: file,
        previewUrl: URL.createObjectURL(file),
        isOptimizing: true
      };
      stagedFiles.push(stageItem);
    }
    renderStaging();

    // Optimize images asynchronously in background
    for (const item of stagedFiles) {
      if (item.isOptimizing) {
        try {
          item.optimizedFile = await compressImage(item.originalFile);
        } catch {}
        item.isOptimizing = false;
        renderStaging();
      }
    }
  }

  function removeStagedItem(id) {
    const idx = stagedFiles.findIndex(f => f.id === id);
    if (idx !== -1) {
      URL.revokeObjectURL(stagedFiles[idx].previewUrl);
      stagedFiles.splice(idx, 1);
      renderStaging();
    }
  }

  function clearStaged() {
    stagedFiles.forEach(f => URL.revokeObjectURL(f.previewUrl));
    stagedFiles = [];
    if (fileInput) fileInput.value = '';
    renderStaging();
  }

  if (stagingClearBtn) {
    stagingClearBtn.addEventListener('click', () => clearStaged());
  }

  // Dropzone interactions
  if (dropzone) {
    ['dragenter', 'dragover'].forEach(name => {
      dropzone.addEventListener(name, e => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('is-dragover');
      });
    });

    ['dragleave', 'dragend'].forEach(name => {
      dropzone.addEventListener(name, e => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('is-dragover');
      });
    });

    dropzone.addEventListener('drop', e => {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.remove('is-dragover');
      if (e.dataTransfer && e.dataTransfer.files) {
        addFiles(e.dataTransfer.files);
      }
    });

    dropzone.addEventListener('click', e => {
      if (e.target.closest('button') === browseBtn || e.target === dropzone || dropzone.contains(e.target)) {
        if (fileInput) fileInput.click();
      }
    });

    dropzone.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (fileInput) fileInput.click();
      }
    });
  }

  if (browseBtn && fileInput) {
    browseBtn.addEventListener('click', e => {
      e.stopPropagation();
      fileInput.click();
    });
  }

  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files.length) {
        addFiles(fileInput.files);
      }
    });
  }

  // Progress Panel Controls
  function showProgress(title = 'Uploading photos…') {
    if (!progressPanel) return;
    progressPanel.classList.remove('hidden', 'is-error', 'is-processing');
    progressStatus.textContent = title;
    progressPct.textContent = '0%';
    progressFill.style.width = '0%';
    progressSummary.textContent = 'Preparing files…';
    if (progressCancelBtn) {
      progressCancelBtn.disabled = false;
      progressCancelBtn.textContent = 'Cancel';
    }
  }

  function updateProgress(loaded, total) {
    if (!progressPanel) return;
    const pct = total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0;
    progressFill.style.width = `${pct}%`;
    progressPct.textContent = `${pct}%`;
    progressSummary.textContent = `${formatFileSize(loaded)} of ${formatFileSize(total)}`;
  }

  function hideProgress() {
    if (progressPanel) progressPanel.classList.add('hidden');
    activeRequest = null;
  }

  if (progressCancelBtn) {
    progressCancelBtn.addEventListener('click', () => {
      if (activeRequest) {
        activeRequest.abort();
        activeRequest = null;
        showAlert('Upload was cancelled.');
      }
      hideProgress();
    });
  }

  // Direct Upload for Existing Room Categories
  if (stageUploadBtn && roomId) {
    stageUploadBtn.addEventListener('click', async e => {
      e.preventDefault();
      e.stopPropagation();
      if (!stagedFiles.length || activeRequest) return;

      hideAlert();
      showProgress('Optimizing & uploading…');

      // Ensure all files are optimized
      for (const item of stagedFiles) {
        if (item.isOptimizing) {
          item.optimizedFile = await compressImage(item.originalFile);
          item.isOptimizing = false;
        }
      }

      const hotelId = form ? (form.querySelector('input[name="id"]')?.value || '') : '';
      const csrf = form ? (form.querySelector('input[name="csrf"]')?.value || '') : '';

      const payload = new FormData();
      payload.append('action', 'upload_room_photos');
      payload.append('csrf', csrf);
      payload.append('id', hotelId);
      payload.append('room_id', roomId);

      stagedFiles.forEach(item => {
        payload.append('images[]', item.optimizedFile, item.optimizedFile.name);
      });

      const xhr = new XMLHttpRequest();
      activeRequest = xhr;

      xhr.open('POST', form?.action || window.location.href, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', pe => {
        if (pe.lengthComputable) {
          updateProgress(pe.loaded, pe.total);
          if (pe.loaded >= pe.total) {
            progressStatus.textContent = 'Saving images to server…';
          }
        }
      });

      xhr.addEventListener('load', () => {
        activeRequest = null;
        let data = null;
        try { data = JSON.parse(xhr.responseText); } catch {}

        if (xhr.status >= 200 && xhr.status < 300 && data?.ok) {
          hideProgress();
          // Inject newly uploaded photos directly into room's photo grid
          const photoGrid = document.querySelector(`.photo-grid[data-room-photo-grid="${roomId}"]`);
          if (photoGrid && Array.isArray(data.uploaded)) {
            photoGrid.classList.remove('hidden');
            data.uploaded.forEach(im => {
              const photoEl = createPhotoElement(im.id, im.path, im.room_name || 'Room', hotelId, csrf);
              photoGrid.append(photoEl);
            });
          }
          clearStaged();
          showAlert(data.message || 'Photos uploaded successfully!', false);
          return;
        }

        hideProgress();
        const errMsg = Array.isArray(data?.errors) && data.errors.length ? data.errors.join(' ') : (xhr.status === 413 ? 'Upload size exceeded server limits.' : 'Upload failed. Please try again.');
        showAlert(errMsg, true);
      });

      xhr.addEventListener('error', () => {
        hideProgress();
        showAlert('Network error. Check connection and retry.');
      });

      xhr.send(payload);
    });
  }

  // Handle Full Form Submit (Save Room Category)
  if (form) {
    form.addEventListener('submit', async e => {
      // If there are staged photos to upload with the form
      if (stagedFiles.length > 0) {
        e.preventDefault();
        if (activeRequest) return;

        hideAlert();
        showProgress('Saving room & photos…');

        // Ensure all files are optimized
        for (const item of stagedFiles) {
          if (item.isOptimizing) {
            item.optimizedFile = await compressImage(item.originalFile);
            item.isOptimizing = false;
          }
        }

        const payload = new FormData(form);
        // Clear standard input file list and attach optimized files
        payload.delete('images[]');
        stagedFiles.forEach(item => {
          payload.append('images[]', item.optimizedFile, item.optimizedFile.name);
        });

        const xhr = new XMLHttpRequest();
        activeRequest = xhr;

        xhr.open(form.method || 'POST', form.action || window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', pe => {
          if (pe.lengthComputable) {
            updateProgress(pe.loaded, pe.total);
            if (pe.loaded >= pe.total) {
              progressStatus.textContent = 'Optimizing and saving room details…';
            }
          }
        });

        xhr.addEventListener('load', () => {
          activeRequest = null;
          let data = null;
          try { data = JSON.parse(xhr.responseText); } catch {}

          if (xhr.status >= 200 && xhr.status < 300 && data?.ok) {
            window.location.assign(data.redirect || window.location.href);
            return;
          }

          hideProgress();
          const errMsg = Array.isArray(data?.errors) && data.errors.length ? data.errors.join(' ') : 'Could not save room category. Please review fields.';
          showAlert(errMsg, true);
        });

        xhr.addEventListener('error', () => {
          hideProgress();
          showAlert('Network error occurred during save.');
        });

        xhr.send(payload);
      }
    });
  }
});

// Helper to construct dynamic photo thumbnail DOM
function createPhotoElement(imageId, imagePath, roomName, hotelId, csrf) {
  const div = document.createElement('div');
  div.className = 'photo is-new';
  div.dataset.imageId = String(imageId);

  const thumbBtn = document.createElement('button');
  thumbBtn.type = 'button';
  thumbBtn.className = 'photo-thumb-btn';
  thumbBtn.dataset.previewImage = imagePath;
  thumbBtn.dataset.previewAlt = `${roomName} photo`;
  thumbBtn.title = 'Click to view full photo';

  const img = document.createElement('img');
  img.src = imagePath;
  img.alt = `${roomName} photo`;
  img.loading = 'lazy';
  thumbBtn.append(img);

  const deleteForm = document.createElement('form');
  deleteForm.method = 'post';
  deleteForm.className = 'delete-photo-form';
  deleteForm.dataset.deleteImageForm = 'true';
  deleteForm.dataset.imageId = String(imageId);
  deleteForm.innerHTML = `
    <input type="hidden" name="csrf" value="${csrf}">
    <input type="hidden" name="action" value="delete_image">
    <input type="hidden" name="id" value="${hotelId}">
    <input type="hidden" name="image_id" value="${imageId}">
    <button type="submit" title="Remove photo" aria-label="Remove photo">×</button>
  `;

  div.append(thumbBtn, deleteForm);
  return div;
}

// In-Place Ajax Photo Deletion
document.addEventListener('submit', async event => {
  const form = event.target.closest('[data-delete-image-form]');
  if (!form) return;

  event.preventDefault();
  if (!confirm('Remove this photo?')) return;

  const photoCard = form.closest('.photo');
  if (photoCard) photoCard.classList.add('is-deleting');

  const formData = new FormData(form);
  try {
    const res = await fetch(form.action || window.location.href, {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json().catch(() => null);

    if (res.ok && data?.ok) {
      if (photoCard) {
        photoCard.style.transition = 'all .25s ease';
        photoCard.style.transform = 'scale(0.8)';
        photoCard.style.opacity = '0';
        setTimeout(() => {
          const parentGrid = photoCard.closest('.photo-grid');
          photoCard.remove();
          if (parentGrid && !parentGrid.querySelector('.photo')) {
            parentGrid.classList.add('hidden');
          }
        }, 250);
      }
    } else {
      if (photoCard) photoCard.classList.remove('is-deleting');
      alert(data?.errors?.[0] || 'Could not delete photo. Try again.');
    }
  } catch {
    if (photoCard) photoCard.classList.remove('is-deleting');
    alert('Network error while deleting photo.');
  }
});

