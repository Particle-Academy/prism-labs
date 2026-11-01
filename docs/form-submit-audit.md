# Fancy 6 form compatibility

react-fancy6 makes Button/Action default to type=button and stops its internal PromptInput controls from submitting a host form. The application imports neither Fancy Button nor Action; every current form uses a native HTML submit button. Seven implicit native submit intents are now explicit. Native forms, callbacks, endpoints and their validation remain the same.

| Form | Submit control | Intent |
|---|---|---|
| resources/js/pages/Lab/Chat.tsx:90 | Run generation | submit |
| resources/js/pages/Lab/Consensus.tsx:53 | Start collection (line57) | submit |
| resources/js/pages/Lab/Consensus.tsx:84 | Mark reviewed (line88) | submit |
| resources/js/pages/Lab/Consensus.tsx:93 | Abandon without review (line104) | submit, existing confirmation retained |
| resources/js/pages/Lab/ProviderProbes.tsx:55 | Run research (line57) | submit |
| resources/js/pages/Lab/ProviderProbes.tsx:65 | Fetch through public URL guard (line67) | submit |
| resources/js/pages/Lab/ProviderProbes.tsx:75 | Compare cache usage (line80) | submit |
| resources/js/pages/Lab/Team.tsx:879 | Ask (line891) | already explicitly submit |

The only PromptInput is resources/js/components/overseer.tsx:246, outside any form. Its onSubmit callback handles sends; it does not rely on a host form's native submit. Other action controls and Link-as-button POST actions are outside these forms. No external form-ID Button was found in the full JSX/import audit.

`npm run test:forms` builds the real app entry and Inertia pages, then intercepts every browser request on an owned fixture origin. Each form is submitted once by click and once by Enter on its actual submit button. A real untyped Fancy Button composed inside each form must not submit on click or Enter; ordinary textarea Enter remains multiline. A real PromptInput composed inside a host form sends once by click and Ctrl+Enter without submitting that form. Native validations and existing abandonment confirmation remain active. No application server, database, provider keys or model calls are used.

The existing frontend CI installs its pinned Chromium before this test, runs it in both locked/current lanes and uploads prism-labs-forms-ci.json. The same controls fail on the previous5.x graph because an untyped Fancy action submits the host; those migration-baseline failures are retained in the envelope evidence. A browser pass is a UI/interaction measurement, not live provider readiness.
