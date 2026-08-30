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
        href: '/lab',
        label: 'Cockpit',
        blurb: 'The workflow-first operations view: active work, review gates, evidence, and cost.',
    },
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
        href: '/lab/human-plus-fixture',
        label: 'Human+ fixture',
        blurb: 'A live Fancy-owned browser surface with controlled JSON state, stable handles, presence, and staged writes for end-to-end agent testing.',
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
        href: '/lab/telemetry',
        label: 'Telemetry',
        blurb: 'A sidecar ledger over chat, research, tools, consensus, and benchmark lanes. It shows daily and monthly token burn without storing prompt or response content.',
    },
    {
        href: '/lab/benchmarks',
        label: 'Benchmarks',
        blurb: 'Latency and token cost across providers and models, tracked over time.',
    },
    {
        href: '/lab/diagnostics',
        label: 'Diagnostics',
        blurb: 'Provider probes, raw telemetry, stored threads, and fixtures that support the Lab workflows.',
    },
];

export function LabNav({ current }: { current: string }) {
    void current;
    return null;
}

export function labBlurb(href: string): string {
    return LAB_SECTIONS.find(section => section.href === href)?.blurb ?? '';
}
