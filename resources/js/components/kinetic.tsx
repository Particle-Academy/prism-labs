import { Link } from '@inertiajs/react';
import { cn } from '@particle-academy/react-fancy';
import { useEffect, useRef, useState, type ReactNode } from 'react';

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
    return (
        <header className="sticky top-0 z-40 border-b backdrop-blur-md" style={{ borderColor: 'var(--k-hairline)', background: 'rgba(7,7,11,0.85)' }}>
            <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-3.5">
                <Link href="/lab/chat" className="flex items-center gap-2.5 font-bold tracking-tight" style={{ color: 'var(--k-ink)' }}>
                    <GradGlyph />
                    Prism Lab
                    <span className="k-mono mt-0.5 hidden sm:inline">{version}</span>
                </Link>
                <nav className="flex items-center gap-1.5 text-sm">
                    <a
                        href="https://github.com/Particle-Academy/prism"
                        className="rounded-lg px-3 py-1.5 transition-colors hover:text-[var(--k-cyan)]"
                        style={{ color: 'var(--k-ink-2)' }}
                    >
                        GitHub
                    </a>
                    <a
                        href="https://packagist.org/packages/particle-academy/prism"
                        className="hidden rounded-lg px-3 py-1.5 transition-colors hover:text-[var(--k-cyan)] sm:block"
                        style={{ color: 'var(--k-ink-2)' }}
                    >
                        Packagist
                    </a>
                </nav>
            </div>
        </header>
    );
}

export function KineticFooter() {
    return (
        <footer className="k-hairline-top mt-24">
            <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-6 py-10 sm:flex-row">
                <p className="k-mono">MIT License · created by TJ Miller &amp; the open-source community</p>
                <p className="k-mono">
                    maintained by{' '}
                    <a href="https://github.com/Particle-Academy" className="k-grad-text normal-case tracking-normal">
                        Particle Academy
                    </a>
                </p>
            </div>
        </footer>
    );
}
