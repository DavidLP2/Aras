(() => {
  const notice = document.querySelector('[data-cookie-notice]');
  const acceptButton = notice?.querySelector('[data-cookie-accept]');
  if (!notice || !acceptButton) return;

  const storageKey = 'aras-cookie-consent-v2';
  try {
    if (window.localStorage.getItem(storageKey) === 'accepted') return;
  } catch (error) {
    console.warn('No se pudo leer la preferencia de cookies del navegador:', error);
  }
  notice.hidden = false;

  acceptButton.addEventListener('click', () => {
    try {
      window.localStorage.setItem(storageKey, 'accepted');
    } catch (error) {
      console.warn('No se pudo guardar la preferencia de cookies del navegador:', error);
    }
    notice.hidden = true;
  });
})();
