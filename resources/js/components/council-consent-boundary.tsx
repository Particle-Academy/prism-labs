import { useId, useLayoutEffect } from 'react';
import type { ComponentType, ReactNode } from 'react';

type ConsentRoot = {
    owners: Set<symbol>;
    previous: string | null;
    previousMarker: string | null;
    markerToken: string;
};

// Page transitions briefly mount both Council pages. Capture the original
// document fence once, and restore it only after the last page unmounts.
const roots = new WeakMap<HTMLElement, ConsentRoot>();

/** Keep client and SSR resolution on the same unchanged package pages. */
export function withCouncilConsent(name: string, Page: ComponentType) {
    if (!name.startsWith('Council/')) return Page;

    return function HumanCouncilPage(props: Record<string, unknown>) {
        return <CouncilConsentBoundary><Page {...props} /></CouncilConsentBoundary>;
    };
}

/** Covers document portals as well as the unchanged package page subtree. */
export function CouncilConsentBoundary({ children }: { children: ReactNode }) {
    const token = useId();

    useLayoutEffect(() => {
        const root = document.documentElement;
        const attribute = 'data-agent-off-limits';
        const marker = 'data-lab-council-consent';
        const owner = Symbol('council-page');
        let state = roots.get(root);
        if (!state) {
            state = {
                owners: new Set(),
                previous: root.getAttribute(attribute),
                previousMarker: root.getAttribute(marker),
                markerToken: token,
            };
            roots.set(root, state);
            root.setAttribute(attribute, 'consent');
            root.setAttribute(marker, token);
        }
        state.owners.add(owner);

        return () => {
            state.owners.delete(owner);
            if (state.owners.size !== 0) return;
            roots.delete(root);
            if (root.getAttribute(marker) !== state.markerToken) return;
            if (root.getAttribute(attribute) === 'consent') {
                if (state.previous === null) root.removeAttribute(attribute);
                else root.setAttribute(attribute, state.previous);
            }
            if (state.previousMarker === null) root.removeAttribute(marker);
            else root.setAttribute(marker, state.previousMarker);
        };
    }, [token]);

    return <section className="min-h-screen bg-white text-zinc-950 dark:bg-zinc-950 dark:text-zinc-100" data-agent-off-limits="consent" aria-label="Human council decisions">{children}</section>;
}
