(() => {
  if (!('serviceWorker' in navigator)) return;

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./sw.js', { scope: './' })
      .then(registration => registration.update())
      .catch(() => {});
  });

  let installPrompt;
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    const button = document.createElement('button');
    button.className = 'install-app-button';
    button.type = 'button';
    button.textContent = '＋ Install Staybook';
    button.setAttribute('aria-label', 'Install Staybook app');
    button.addEventListener('click', async () => {
      if (!installPrompt) return;
      await installPrompt.prompt();
      installPrompt = null;
      button.remove();
    }, { once: true });
    document.body.append(button);
  });

  window.addEventListener('appinstalled', () => {
    document.querySelector('.install-app-button')?.remove();
  });
})();
