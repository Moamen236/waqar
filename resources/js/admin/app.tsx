import '../../css/admin.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { installRoute } from '../lib/ziggy';

// route() as a global, for every admin page's Link/router call instead of
// hand-written URL strings. Shared with the storefront entry (lib/ziggy).
installRoute();

createInertiaApp({
    title: (title) => (title ? `${title} — WAQAR Admin` : 'WAQAR Admin'),
    // Lazy, not eager: every page becomes its own Rollup chunk, so a
    // visit downloads the shell plus the one page it needs instead of
    // every page in the area up front. Inertia accepts the promise
    // resolve() returns and handles the wait itself.
    resolve: (name) => {
        const pages = import.meta.glob<{ default: React.ComponentType }>('./Pages/**/*.tsx');
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        // Unwrapped to the component itself: Inertia's ComponentResolver
        // type accepts a Promise<Component>, not a Promise<Module>.
        return page().then((module) => module.default);
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
