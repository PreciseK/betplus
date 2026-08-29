const body = document.body;
const navToggle = document.querySelector('[data-nav-toggle]');
const nav = document.querySelector('[data-nav]');
const year = document.querySelector('[data-year]');
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
const staggerBlock = document.querySelector('.t-stagger');

function showText() {
  if (!staggerBlock) return;
  staggerBlock.classList.remove('is-hiding');
  staggerBlock.classList.remove('is-shown');
  void staggerBlock.offsetHeight;
  staggerBlock.classList.add('is-shown');
}

requestAnimationFrame(() => {
  body.classList.add('is-ready');
  showText();
});

if (year) {
  year.textContent = new Date().getFullYear();
}

const dropdownCloseMs = parseFloat(
  getComputedStyle(document.documentElement).getPropertyValue('--dropdown-close-dur')
) || 150;

function openDropdown() {
  if (!nav) return;
  nav.classList.remove('is-closing');
  nav.classList.add('is-open');
}

function closeDropdown() {
  if (!nav) return;
  nav.classList.remove('is-open');
  nav.classList.add('is-closing');
  setTimeout(() => nav.classList.remove('is-closing'), dropdownCloseMs);
}

function closeMenu({ returnFocus = false } = {}) {
  if (!navToggle || !nav) return;
  navToggle.setAttribute('aria-expanded', 'false');
  navToggle.querySelector('.sr-only').textContent = 'Open menu';
  closeDropdown();
  body.classList.remove('nav-open');
  if (returnFocus) navToggle.focus();
}

if (navToggle && nav) {
  navToggle.addEventListener('click', () => {
    const willOpen = navToggle.getAttribute('aria-expanded') !== 'true';
    navToggle.setAttribute('aria-expanded', String(willOpen));
    navToggle.querySelector('.sr-only').textContent = willOpen ? 'Close menu' : 'Open menu';
    if (willOpen) openDropdown();
    else closeDropdown();
    body.classList.toggle('nav-open', willOpen);
  });

  nav.addEventListener('click', event => {
    if (event.target.closest('a')) closeMenu();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && nav.classList.contains('is-open')) {
      closeMenu({ returnFocus: true });
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 1100) closeMenu();
  });
}

document.querySelectorAll('.t-tilt').forEach(tilt => {
  const card = tilt.querySelector('.t-tilt-card');
  if (!card) return;

  const MAX = 7;

  function resetTilt() {
    tilt.classList.remove('is-hover');
    card.classList.remove('is-tilting');
    card.style.setProperty('--tilt-rx', '0deg');
    card.style.setProperty('--tilt-ry', '0deg');
  }

  function trackTilt(event) {
    if (reduceMotion.matches || !finePointer.matches) return;
    const rect = tilt.getBoundingClientRect();
    const px = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    const py = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
    tilt.classList.add('is-hover');
    card.classList.add('is-tilting');
    card.style.setProperty('--tilt-ry', `${((px - 0.5) * MAX).toFixed(2)}deg`);
    card.style.setProperty('--tilt-rx', `${((0.5 - py) * MAX).toFixed(2)}deg`);
    card.style.setProperty('--tilt-gx', `${(px * 100).toFixed(1)}%`);
    card.style.setProperty('--tilt-gy', `${(py * 100).toFixed(1)}%`);
  }

  tilt.addEventListener('pointerdown', event => {
    if (!finePointer.matches || event.pointerType === 'mouse') return;
    try { tilt.setPointerCapture(event.pointerId); } catch (_) {}
  });
  tilt.addEventListener('pointermove', trackTilt);
  tilt.addEventListener('pointerup', resetTilt);
  tilt.addEventListener('pointercancel', resetTilt);
  tilt.addEventListener('pointerleave', event => {
    if (event.pointerType === 'mouse') resetTilt();
  });
});

const revealItems = document.querySelectorAll('.reveal-on-scroll');

if ('IntersectionObserver' in window && !reduceMotion.matches) {
  const revealObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: 0.14, rootMargin: '0px 0px -6% 0px' });

  revealItems.forEach(item => revealObserver.observe(item));
} else {
  revealItems.forEach(item => item.classList.add('is-visible'));
}

let scrollFrame = null;

function updateGlow() {
  const maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
  const progress = Math.min(1, Math.max(0, window.scrollY / maxScroll));
  document.documentElement.style.setProperty('--scroll-progress', progress.toFixed(4));

  if (!reduceMotion.matches) {
    const shift = Math.min(window.scrollY * 0.055, 90);
    document.documentElement.style.setProperty('--glow-shift', `${shift}px`);
  }
  scrollFrame = null;
}

window.addEventListener('scroll', () => {
  if (scrollFrame) return;
  scrollFrame = requestAnimationFrame(updateGlow);
}, { passive: true });

updateGlow();
