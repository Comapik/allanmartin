/* =========================================================
   main.js — Allan Martin Photography
   ========================================================= */

'use strict';

// ── Header scroll state ────────────────────────────────────
(function initScrollHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;

  const onScroll = () => {
    if (window.scrollY > 40) {
      header.classList.add('scrolled');
    } else {
      header.classList.remove('scrolled');
    }
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();

// ── Mobile nav toggle ──────────────────────────────────────
(function initMobileNav() {
  const toggle = document.querySelector('.nav-toggle');
  const nav    = document.querySelector('.site-nav');
  if (!toggle || !nav) return;

  toggle.addEventListener('click', () => {
    const isOpen = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(isOpen));
    document.body.style.overflow = isOpen ? 'hidden' : '';
  });

  // Close on nav link click
  nav.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      nav.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    });
  });
})();

// ── Footer year ────────────────────────────────────────────
(function initYear() {
  const el = document.getElementById('year');
  if (el) el.textContent = new Date().getFullYear();
})();

// ── Lightbox & Serie gallery have been removed ────────────────

// ── Intersection Observer — fade-in on scroll ──────────────
(function initFadeIn() {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

  // Elements that receive fade-in programmatically
  document.querySelectorAll('.series-item, .serie-gallery-item, .page-title, .page-body').forEach(el => {
    el.classList.add('fade-in');
    observer.observe(el);
  });

  // Elements already marked with fade-in in HTML (e.g. press page sections)
  document.querySelectorAll('.fade-in').forEach(el => {
    observer.observe(el);
  });
})();

// ── Horizontal scroll hint / buttons (Home page) ───────────
(function initHorizontalScroll() {
  const grid = document.querySelector('.series-grid');
  const prevBtn = document.querySelector('.carousel-btn.prev');
  const nextBtn = document.querySelector('.carousel-btn.next');
  if (!grid) return;

  if (prevBtn && nextBtn) {
    const item = grid.querySelector('.series-item');
    prevBtn.addEventListener('click', () => {
      if(item) {
        grid.scrollBy({ left: -(item.offsetWidth + parseInt(window.getComputedStyle(grid).gap || 0)), behavior: 'smooth' });
      }
    });
    nextBtn.addEventListener('click', () => {
      if(item) {
        grid.scrollBy({ left: (item.offsetWidth + parseInt(window.getComputedStyle(grid).gap || 0)), behavior: 'smooth' });
      }
    });
  }
})();

// ── Serie Fullscreen Navigation ──────────────────────────────
(function initSerieFullscreen() {
  const carousel = document.getElementById('serie-carousel');
  const prevBtn = document.getElementById('serie-prev');
  const nextBtn = document.getElementById('serie-next');
  const slideCountLabel = document.getElementById('slide-number');

  if (!carousel) return;

  const images = Array.from(carousel.querySelectorAll('img'));
  const total = images.length;

  const isFadeCarousel = document.querySelector('.serie-fullscreen') !== null;
  const dynamicTitle = document.getElementById('dynamic-photo-title');
  const dynamicEditions = document.getElementById('dynamic-photo-editions');
  const dynamicDimensions = document.getElementById('dynamic-photo-dimensions');

  const updatePhotoMeta = (img) => {
    if (!img) return;

    if (dynamicTitle) {
      dynamicTitle.textContent = img.alt || 'Sans titre';
    }

    if (dynamicEditions) {
      dynamicEditions.textContent = img.dataset.editions || 'Éditions non renseignées';
    }

    if (dynamicDimensions) {
      dynamicDimensions.textContent = img.dataset.dimensions || 'Dimensions non renseignées';
    }
  };

  if (prevBtn && nextBtn) {
    if (isFadeCarousel) {
      let currentIndex = 0;
      
      const updateFadeCarousel = () => {
        images.forEach((img, idx) => {
          if (idx === currentIndex) {
            img.classList.add('active');
            updatePhotoMeta(img);
          } else {
            img.classList.remove('active');
          }
        });
      };
      
      updateFadeCarousel(); // Init first slide

      prevBtn.addEventListener('click', () => {
        currentIndex = (currentIndex === 0) ? images.length - 1 : currentIndex - 1;
        updateFadeCarousel();
      });
      nextBtn.addEventListener('click', () => {
        currentIndex = (currentIndex === images.length - 1) ? 0 : currentIndex + 1;
        updateFadeCarousel();
      });
    } else {
      // HOMEPAGE scroll carousel
      prevBtn.addEventListener('click', () => {
        const item = images[0];
        if(item) carousel.scrollBy({ left: -(item.offsetWidth + 120), behavior: 'smooth' });
      });
      nextBtn.addEventListener('click', () => {
        const item = images[0];
        if(item) carousel.scrollBy({ left: (item.offsetWidth + 120), behavior: 'smooth' });
      });
    }
  }

  // --- ABOUT MODAL (Serie page) ---
  const aboutBtn = document.getElementById('serie-about-btn');
  const aboutModal = document.getElementById('about-modal');
  const aboutClose = document.getElementById('about-modal-close');

  if (aboutBtn && aboutModal) {
    aboutBtn.addEventListener('click', (e) => {
      e.preventDefault();
      aboutModal.removeAttribute('hidden');
      void aboutModal.offsetWidth; // Trigger reflow
      aboutModal.classList.add('active');
    });

    if (aboutClose) {
      aboutClose.addEventListener('click', () => {
        aboutModal.classList.remove('active');
      });
    }

    // Modal close on clicking backdrop
    aboutModal.addEventListener('click', (e) => {
      if (e.target === aboutModal) {
        aboutModal.classList.remove('active');
      }
    });
  }

  // Track currently visible image to update slide index
  if (images.length > 0 && slideCountLabel) {
    const observer = new IntersectionObserver((entries) => {
      let maxRatio = 0;
      let targetImg = null;
      entries.forEach(entry => {
        if (entry.intersectionRatio > 0.5) {
          const index = images.indexOf(entry.target) + 1;
          slideCountLabel.textContent = `${index} / ${total}`;
        }
      });
    }, {
      root: carousel,
      threshold: 0.5
    });

    images.forEach(img => observer.observe(img));
  }
})();

// ── Formulaire de contact (AJAX) ──────────────────────
(function initContactForm() {
  const form = document.getElementById('contact-form');
  if (!form) return;

  // Timing anti-bot : horodatage au chargement de la page
  const timeField = document.getElementById('_time');
  if (timeField) timeField.value = Date.now();

  const feedback  = document.getElementById('form-feedback');
  const submitBtn = form.querySelector('.btn-submit');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // Validation côté client (avant envoi)
    const name    = form.elements['name'].value.trim();
    const email   = form.elements['email'].value.trim();
    const message = form.elements['message'].value.trim();

    if (!name || !email || !message) {
      showFeedback('Veuillez remplir tous les champs obligatoires.', false);
      return;
    }

    submitBtn.disabled    = true;
    submitBtn.textContent = 'Envoi en cours…';
    clearFeedback();

    try {
      const res  = await fetch('send.php', { method: 'POST', body: new FormData(form) });
      const data = await res.json();
      showFeedback(data.message, data.success);
      if (data.success) form.reset();
    } catch {
      showFeedback('Une erreur réseau est survenue. Veuillez réessayer.', false);
    } finally {
      submitBtn.disabled    = false;
      submitBtn.textContent = 'Envoyer';
    }
  });

  function showFeedback(msg, success) {
    feedback.textContent = msg;
    feedback.className   = 'form-feedback ' + (success ? 'form-feedback--ok' : 'form-feedback--err');
  }

  function clearFeedback() {
    feedback.textContent = '';
    feedback.className   = 'form-feedback';
  }
})();
