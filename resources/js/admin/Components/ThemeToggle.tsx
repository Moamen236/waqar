import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from '../lib/useTranslation';

type ColorMode = 'light' | 'dark';

/** Bootstrap 5.3's own colour-mode attribute, on <html>. */
const THEME_ATTR = 'data-bs-theme';
const THEME_KEY = 'waqar.admin.theme';

/** Larkon's `defaultConfig.theme`. */
const DEFAULT_THEME: ColorMode = 'light';

/**
 * The topbar light/dark switch — Larkon's own `#light-dark-mode` button,
 * which does exactly one thing: flip `data-bs-theme` on <html> between
 * "light" and "dark" (app.js's `changeThemeMode`). Everything visual
 * follows from that attribute, because app.min.css is compiled twice over
 * — once under `:root,[data-bs-theme=light]` and once under
 * `[data-bs-theme=dark]` — so no component here needs a dark variant of
 * its own. admin.css adds the brand palette's dark half for the same
 * reason (see the `html[data-bs-theme='dark']` block there).
 *
 * Two deliberate departures from the template's app.js:
 *
 * - It persists to **localStorage**, not the `sessionStorage`
 *   `__LARKON_CONFIG__` blob the template uses, matching how the sidebar
 *   size is already remembered (`waqar.admin.menuSize`). sessionStorage
 *   would forget the choice every time the tab is closed, which for a
 *   preference this visible reads as a bug.
 * - The *initial* value is applied by a blocking script in
 *   admin.blade.php, not here. React mounts after first paint, so
 *   restoring it in this component would flash the light theme for one
 *   frame on every load for anyone who picked dark.
 *
 * Like the template, nothing consults `prefers-color-scheme`: an
 * untouched admin opens light, and only an explicit choice is stored.
 */
export default function ThemeToggle() {
    const { t } = useTranslation();
    // Read back what the pre-paint script already put on <html> rather
    // than re-reading storage, so this cannot disagree with what is on
    // screen.
    const [theme, setTheme] = useState<ColorMode>(() =>
        document.documentElement.getAttribute(THEME_ATTR) === 'dark' ? 'dark' : DEFAULT_THEME,
    );

    useEffect(() => {
        document.documentElement.setAttribute(THEME_ATTR, theme);

        try {
            window.localStorage.setItem(THEME_KEY, theme);
        } catch {
            // A browser refusing site data just means the choice does not
            // survive the next full page load.
        }
    }, [theme]);

    const toggle = useCallback(() => setTheme((mode) => (mode === 'light' ? 'dark' : 'light')), []);

    const next = theme === 'light' ? t('admin.switchToDarkMode') : t('admin.switchToLightMode');

    return (
        <div className="topbar-item">
            <button type="button" className="topbar-button" onClick={toggle} aria-label={next} title={next}>
                <i className={`bx ${theme === 'light' ? 'bx-moon' : 'bx-sun'} fs-24 align-middle`} />
            </button>
        </div>
    );
}
