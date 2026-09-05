// Theme and topbar icon behavior shared across pages.
(function () {
  const THEME_KEY = 'theme';

  const ICON_THEME_MOON = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a7 7 0 1 0 9 9 9 9 0 1 1-9-9z"></path></svg>';
  const ICON_THEME_SUN = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path></svg>';
  const ICON_FULLSCREEN_MAXIMIZE = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M21 8V5a2 2 0 0 0-2-2h-3"></path><path d="M3 16v3a2 2 0 0 0 2 2h3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg>';
  const ICON_FULLSCREEN_MINIMIZE = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3v3a2 2 0 0 1-2 2H3"></path><path d="M21 8h-3a2 2 0 0 1-2-2V3"></path><path d="M3 16h3a2 2 0 0 1 2 2v3"></path><path d="M16 21v-3a2 2 0 0 1 2-2h3"></path></svg>';

  function getSavedTheme() {
    try {
      return localStorage.getItem(THEME_KEY) || 'light';
    } catch (err) {
      return 'light';
    }
  }

  function animateIconButton(button, className) {
    if (!button) {
      return;
    }
    button.classList.remove(className);
    void button.offsetWidth;
    button.classList.add(className);
    window.setTimeout(() => button.classList.remove(className), 380);
  }

  function updateThemeButton(theme) {
    const visibleButton = document.getElementById('theme-switch-btn');
    if (!visibleButton) {
      return;
    }
    const isDark = theme === 'dark';
    visibleButton.innerHTML = isDark ? ICON_THEME_SUN : ICON_THEME_MOON;
    visibleButton.setAttribute('title', isDark ? 'Tema chiaro' : 'Tema scuro');
    visibleButton.setAttribute('aria-label', isDark ? 'Passa al tema chiaro' : 'Passa al tema scuro');
  }

  function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.documentElement.classList.toggle('dark-mode', isDark);
    if (document.body) {
      document.body.classList.toggle('dark-mode', isDark);
    }
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    updateThemeButton(isDark ? 'dark' : 'light');
  }

  function toggleTheme() {
    const isDark = document.documentElement.classList.contains('dark-mode');
    const nextTheme = isDark ? 'light' : 'dark';
    applyTheme(nextTheme);
    try {
      localStorage.setItem(THEME_KEY, nextTheme);
    } catch (err) {
      // Ignore storage write failures in locked/private contexts.
    }
    animateIconButton(document.getElementById('theme-switch-btn'), 'is-rotating');
  }

  function updateFullscreenButtonIcon() {
    const button = document.getElementById('fullscreen-toggle-btn');
    if (!button) {
      return;
    }

    const isFullscreen = Boolean(document.fullscreenElement);
    button.innerHTML = isFullscreen ? ICON_FULLSCREEN_MINIMIZE : ICON_FULLSCREEN_MAXIMIZE;
    button.setAttribute('title', isFullscreen ? 'Riduci schermata' : 'Schermo intero');
    button.setAttribute('aria-label', isFullscreen ? 'Riduci schermata' : 'Schermo intero');
  }

  function setupThemeToggle() {
    const visibleButton = document.getElementById('theme-switch-btn');
    if (!visibleButton || visibleButton.dataset.themeBound === '1') {
      return;
    }

    visibleButton.dataset.themeBound = '1';
    visibleButton.addEventListener('click', toggleTheme);
  }

  function setupFullscreenIconSync() {
    const fullscreenButton = document.getElementById('fullscreen-toggle-btn');
    if (!fullscreenButton || fullscreenButton.dataset.fullscreenBound === '1') {
      updateFullscreenButtonIcon();
      return;
    }

    fullscreenButton.dataset.fullscreenBound = '1';
    fullscreenButton.addEventListener('click', () => {
      animateIconButton(fullscreenButton, 'is-bouncing');
    });

    document.addEventListener('fullscreenchange', () => {
      updateFullscreenButtonIcon();
      animateIconButton(fullscreenButton, 'is-soft-pop');
    });

    updateFullscreenButtonIcon();
  }

  // Apply as early as possible to avoid delayed theme transitions.
  applyTheme(getSavedTheme());

  function syncAfterDomReady() {
    // Ensure body inherits theme class as soon as available.
    applyTheme(getSavedTheme());
    setupThemeToggle();
    setupFullscreenIconSync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncAfterDomReady, { once: true });
  } else {
    syncAfterDomReady();
  }
})();
