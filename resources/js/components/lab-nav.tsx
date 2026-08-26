/**
 * The Lab's sections, in one place.
 *
 * This was three hardcoded copies of the same tab strip — one per page — so
 * adding a section meant editing every existing page and remembering which
 * link should be highlighted on each. That friction is why the Lab drifted
 * into being about telemetry: telemetry was what already had a tab.
 *
 * The Lab exists to exercise the Prism ecosystem's features. Adding a section
 * should cost one line here.
 */
export type LabSection = {
    href: string;
    label: string;
    /** What this section is for, shown on the section's own page. */
    blurb: string;
};

export const LAB_SECTIONS: LabSection[] = [
    {
        href: '/lab/team',
        label: 'Team',
        blurb: 'The agent team: Prism.php and one agent per language port. Watch what they are doing, read what they have learned, and talk to Prism directly.',
    },
    {
        href: '/lab/chat',
        label: 'Chat console',
        blurb: 'Talk to any configured provider. Conversations persist through the harness, so the model remembers across requests and page reloads.',
    },
    {
        href: '/lab/threads',
        label: 'Threads',
        blurb: 'What the harness actually stored. Every message, in order, rebuilt from the database as the value objects a provider would receive.',
    },
    {
        href: '/lab/tests',
        label: 'Provider matrix',
        blurb: 'Exercise every provider against every capability it claims to support, with real generations.',
    },
    {
        href: '/lab/benchmarks',
        label: 'Benchmarks',
        blurb: 'Latency and token cost across providers and models, tracked over time.',
    },
];

export function LabNav({ current }: { current: string }) {
    return (
        <div className="mb-7 flex flex-wrap gap-2">
            {LAB_SECTIONS.map(section => (
                <a
                    key={section.href}
                    className={`k-btn ${section.href === current ? 'k-btn--grad' : 'k-btn--ghost'}`}
                    href={section.href}
                >
                    {section.label}
                </a>
            ))}
        </div>
    );
}

export function labBlurb(href: string): string {
    return LAB_SECTIONS.find(section => section.href === href)?.blurb ?? '';
}
