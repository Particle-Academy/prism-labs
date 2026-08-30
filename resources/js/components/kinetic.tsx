import { usePage } from '@inertiajs/react';
import { cn } from '@particle-academy/react-fancy';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { LabTopbar } from './lab-shell';

/** Rises into place when scrolled into view (Kinetic reveal). */
export function Reveal({ children, delay = 0, className }: { children: ReactNode; delay?: number; className?: string }) {
    const ref = useRef<HTMLDivElement>(null);
    const [inView, setInView] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const io = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setInView(true);
                    io.disconnect();
                }
            },
            { rootMargin: '0px 0px -10% 0px' },
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);

    return (
        <div ref={ref} className={cn('k-reveal', inView && 'is-in', className)} style={{ transitionDelay: `${delay}ms` }}>
            {children}
        </div>
    );
}

/** Edge-to-edge marquee strip of mono items with magenta separators. */
export function Marquee({ items, reverse = false }: { items: string[]; reverse?: boolean }) {
    const doubled = [...items, ...items];

    return (
        <div className={cn('k-marquee py-4', reverse && 'k-marquee--reverse')} aria-hidden>
            <div className="k-marquee__track">
                {doubled.map((item, i) => (
                    <span key={i} className="k-mono flex items-center gap-10 whitespace-nowrap text-sm">
                        {item} <span style={{ color: 'var(--k-mag)' }}>✸</span>
                    </span>
                ))}
            </div>
        </div>
    );
}

export function GradGlyph({ className }: { className?: string }) {
    return (
        <span
            className={cn('grid size-7 place-items-center rounded-md text-sm font-extrabold text-white', className)}
            style={{ background: 'var(--k-grad)' }}
        >
            P
        </span>
    );
}

/**
 * Top navigation, shared by every Lab page.
 *
 * The docs site's version of this carried Docs, Leaderboard and a ⌘K search
 * over the documentation index. None of those exist here, and a nav link to a
 * route that 404s is worse than no link.
 *
 * `version` is the installed Prism version rather than a documentation
 * version — in a testbed, the thing you need to see at a glance is what you
 * are testing against.
 */
export function KineticNav({ version }: { version: string }) {
    void version;
    const path = usePage().url.split('?')[0];
    return <LabTopbar current={path} />;
}

export function KineticFooter() {
    return null;
}
