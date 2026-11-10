(() => {
  const carousel = document.querySelector('[data-banner-carousel]');
  if (!carousel) return;

  const track = carousel.querySelector('[data-banner-track]');
  const controls = carousel.querySelector('[data-banner-controls]');
  const dots = carousel.querySelector('[data-banner-dots]');
  const previousButton = carousel.querySelector('[data-banner-prev]');
  const nextButton = carousel.querySelector('[data-banner-next]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let slides = [];
  let currentIndex = 0;
  let timer = null;
  let activeAnimations = [];
  let transitionId = 0;

  function stopTransition() {
    transitionId++;
    activeAnimations.forEach(animation => animation.cancel());
    activeAnimations = [];
  }

  function showSlide(index, direction = 1, animate = true) {
    if (slides.length === 0) return;
    stopTransition();
    const previousIndex = currentIndex;
    const nextIndex = (index + slides.length) % slides.length;
    const outgoingSlide = slides[previousIndex];
    currentIndex = nextIndex;
    slides.forEach((slide, slideIndex) => {
      const selected = slideIndex === currentIndex;
      if (slideIndex !== previousIndex && slideIndex !== currentIndex) {
        slide.hidden = true;
      }
      slide.setAttribute('aria-hidden', String(!selected));
      dots.children[slideIndex].setAttribute('aria-current', selected ? 'true' : 'false');
    });

    if (previousIndex === nextIndex) {
      slides.forEach((slide, slideIndex) => { slide.hidden = slideIndex !== currentIndex; });
      return;
    }

    const incomingSlide = slides[currentIndex];
    incomingSlide.hidden = false;
    if (!animate || reducedMotion.matches || typeof incomingSlide.animate !== 'function') {
      slides.forEach((slide, slideIndex) => { slide.hidden = slideIndex !== currentIndex; });
      return;
    }

    const outgoingAnimation = outgoingSlide.animate([
      { transform: 'translateX(0)', opacity: 1 },
      { transform: `translateX(${-direction * 16}%)`, opacity: .92 }
    ], {
      duration: 760,
      easing: 'cubic-bezier(.22, 1, .36, 1)',
      fill: 'forwards'
    });
    const incomingAnimation = incomingSlide.animate([
      { transform: `translateX(${direction * 100}%)`, opacity: .94 },
      { transform: 'translateX(0)', opacity: 1 }
    ], {
      duration: 760,
      easing: 'cubic-bezier(.22, 1, .36, 1)',
      fill: 'forwards'
    });
    activeAnimations = [outgoingAnimation, incomingAnimation];
    const thisTransition = transitionId;
    Promise.all(activeAnimations.map(animation => animation.finished.catch(() => {}))).then(() => {
      if (thisTransition !== transitionId) return;
      stopTransition();
      slides.forEach((slide, slideIndex) => { slide.hidden = slideIndex !== currentIndex; });
    });
  }

  function stopRotation() {
    if (timer !== null) {
      window.clearInterval(timer);
      timer = null;
    }
  }

  function startRotation() {
    stopRotation();
    if (slides.length < 2 || reducedMotion.matches
      || document.hidden || carousel.matches(':hover')
      || carousel.contains(document.activeElement)) return;
    timer = window.setInterval(() => showSlide(currentIndex + 1, 1), 4000);
  }

  function validBanner(banner) {
    return banner && typeof banner.image === 'string'
      && typeof banner.text === 'string' && typeof banner.link === 'string'
      && (banner.demo === undefined || typeof banner.demo === 'boolean');
  }

  async function loadBanners() {
    try {
      const response = await fetch('api/banners.php', {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      if (!response.ok) throw new Error(`El servidor respondió con estado ${response.status}.`);
      const data = await response.json();
      if (!Array.isArray(data.banners)) throw new Error('La respuesta de banners tiene un formato inválido.');

      const banners = data.banners.filter(validBanner);
      if (banners.length === 0) return;

      banners.forEach((banner, index) => {
        const slide = document.createElement('article');
        slide.className = 'hero-slide';
        slide.hidden = true;
        slide.setAttribute('aria-hidden', 'true');
        const linkUrl = banner.link.trim();
        let content = document.createElement('div');
        if (linkUrl) {
          const parsedLink = new URL(linkUrl, window.location.href);
          if (!['http:', 'https:'].includes(parsedLink.protocol)) {
            throw new Error('Uno de los enlaces de banner no es seguro.');
          }
          const anchor = document.createElement('a');
          anchor.className = 'hero-slide-link';
          anchor.href = parsedLink.href;
          content = anchor;
        }

        const image = document.createElement('img');
        image.src = banner.image;
        image.alt = banner.text || 'Novedad de ARAS';
        image.loading = index === 0 ? 'eager' : 'lazy';
        image.fetchPriority = index === 0 ? 'high' : 'auto';
        content.append(image);
        if (banner.text) {
          const caption = document.createElement('span');
          caption.className = 'hero-slide-caption';
          caption.textContent = banner.text;
          content.append(caption);
        }
        slide.append(content);
        track.append(slide);
        slides.push(slide);

        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'hero-carousel-dot';
        dot.setAttribute('aria-label', `Mostrar banner ${index + 1}`);
        dot.addEventListener('click', () => {
          showSlide(index, index > currentIndex ? 1 : -1);
          startRotation();
        });
        dots.append(dot);
      });

      if (slides.length === 0) return;
      carousel.hidden = false;
      controls.hidden = slides.length < 2;
      showSlide(0, 1, false);
      previousButton.addEventListener('click', () => {
        showSlide(currentIndex - 1, -1);
        startRotation();
      });
      nextButton.addEventListener('click', () => {
        showSlide(currentIndex + 1, 1);
        startRotation();
      });
      carousel.addEventListener('pointerenter', stopRotation);
      carousel.addEventListener('pointerleave', startRotation);
      carousel.addEventListener('focusin', stopRotation);
      carousel.addEventListener('focusout', event => {
        if (!carousel.contains(event.relatedTarget)) startRotation();
      });
      document.addEventListener('visibilitychange', startRotation);
      reducedMotion.addEventListener?.('change', startRotation);
      startRotation();
    } catch (error) {
      console.error('No se pudieron cargar los banners de ARAS:', error);
    }
  }

  loadBanners();
})();
