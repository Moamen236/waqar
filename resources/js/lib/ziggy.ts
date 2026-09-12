import { route } from 'ziggy-js';
import type { Config as ZiggyConfig } from 'ziggy-js';

/**
 * Deliberately a simplified signature rather than ziggy-js's own heavily
 * overloaded `route` type — every call site in this app just needs
 * "name, optional params, optional absolute flag" -> string.
 */
export type RouteFn = (name: string, params?: unknown, absolute?: boolean) => string;

declare global {
    interface Window {
        route: RouteFn;
    }
    var route: RouteFn;
    const Ziggy: ZiggyConfig;
}

/**
 * The blade views' @routes directive emits a global `const Ziggy = {...}`
 * script tag ahead of the entry module; wiring route() onto window here
 * is Ziggy's own documented Vite/Inertia integration pattern. Shared by
 * both entry points so the global is declared exactly once.
 */
export function installRoute(): void {
    window.route = (name, params, absolute) =>
        String(route(name as Parameters<typeof route>[0], params as Parameters<typeof route>[1], absolute, Ziggy));
}
