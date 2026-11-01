import { createRoot } from 'react-dom/client';
import { Button, PromptInput } from '@particle-academy/react-fancy';

// Browser-only composition controls. This file is never imported by the application.
Object.assign(window, {
    mountFancyFormControl(kind: 'button' | 'prompt', formIndex: number) {
        const form = document.querySelectorAll('form')[formIndex];
        if (!form) throw new Error('The real form is missing.');
        const host = document.createElement('div');
        host.dataset.formControl = kind;
        form.appendChild(host);
        const root = createRoot(host);
        root.render(kind === 'button'
            ? <Button onClick={() => { window.dispatchEvent(new Event('fixture:non-submit')); }}>Fixture non-submit action</Button>
            : <PromptInput defaultValue="Owned prompt" onSubmit={() => { window.dispatchEvent(new Event('fixture:prompt-send')); }} />);
    },
});
