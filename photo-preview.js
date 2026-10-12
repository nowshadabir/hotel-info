(() => {
  const dialog = document.querySelector('.photo-preview');
  if (!dialog) return;

  const preview = dialog.querySelector('.photo-preview-image');
  const caption = dialog.querySelector('.photo-preview-caption');
  const closeButton = dialog.querySelector('.photo-preview-close');

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-preview-image]');
    if (!trigger) return;
    event.preventDefault();
    if (preview) {
      preview.src = trigger.dataset.previewImage || '';
      preview.alt = trigger.dataset.previewAlt || '';
    }
    if (caption) {
      caption.textContent = trigger.dataset.previewAlt || '';
    }
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    }
  });

  if (closeButton) {
    closeButton.addEventListener('click', () => dialog.close());
  }
  dialog.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });
  window.addEventListener('keydown', event => {
    if (event.key === 'Escape' && dialog.open) {
      dialog.close();
    }
  });
})();

