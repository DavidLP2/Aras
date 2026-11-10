(() => {
  const announcementContainer = document.getElementById('collection-announcement');
  if (!announcementContainer) return;
  const closeButton = announcementContainer.querySelector('.announcement-close');
  const image = document.getElementById('announcement-image');
  const title = document.getElementById('announcement-title');
  const message = document.getElementById('announcement-message');

  closeButton.addEventListener('click', () => announcementContainer.close());
  announcementContainer.addEventListener('click', event => {
    if (event.target === announcementContainer) {
      const bounds = announcementContainer.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right
        || event.clientY < bounds.top || event.clientY > bounds.bottom) {
        announcementContainer.close();
      }
    }
  });

  fetch('api/anuncio.php', { headers: { Accept: 'application/json' }, cache: 'no-store' })
    .then(response => {
      if (!response.ok) {
        throw new Error(`El servidor respondió con estado ${response.status}.`);
      }
      return response.json();
    })
    .then(data => {
      const announcement = data.announcement;
      if (!announcement || !announcement.image) return;

      image.src = announcement.image;
      image.alt = announcement.title || 'Anuncio de la nueva colección de ARAS';
      title.textContent = announcement.title || '';
      title.hidden = !announcement.title;
      message.textContent = announcement.message || '';
      message.hidden = !announcement.message;
      announcementContainer.showModal();
    })
    .catch(error => {
      console.error('No se pudo cargar el anuncio de ARAS:', error);
    });
})();
