import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import { RunDiagnosticsView } from '../../resources/js/components/run-diagnostics';
import { CacheEvidence, FetchResultView } from '../../resources/js/components/provider-probe-results';

const html = renderToStaticMarkup(<RunDiagnosticsView diagnostics={{
    code: 'run_incomplete', run_id: 'run_fixture', incomplete_reason: 'max_output_tokens',
    output: [{ text: '<script>partial-secret</script>' }],
    citations: [{ url: 'https://example.test/source' }],
    usage: { prompt_tokens: 12, completion_tokens: 64, cache_read_input_tokens: null, cache_write_input_tokens: null, thought_tokens: null, cost: 0.01 },
}} />);
for (const text of ['run_incomplete', 'run_fixture', 'max_output_tokens', 'partial-secret', 'https://example.test/source', 'completion_tokens', '64']) {
    assert.ok(html.includes(text), `diagnostics display lost ${text}`);
}
assert.ok(!html.includes('<script>'), 'partial content must be text, never executable markup');
assert.equal(renderToStaticMarkup(<RunDiagnosticsView diagnostics={null} />), '');
console.log('Provider diagnostics UI assertions passed.');

const refused = renderToStaticMarkup(<FetchResultView result={{ ok: false, code: 'private_address_refused', reason: 'Private host refused' }} />);
assert.ok(refused.includes('private_address_refused'));
assert.ok(refused.includes('Refused'));
const fetched = renderToStaticMarkup(<FetchResultView result={{ ok: true, bytes: 12, preview: '<script>x</script>', mime_type: 'text/plain', truncated: false }} />);
assert.ok(fetched.includes('12'));
assert.ok(!fetched.includes('<script>'));
const cache = renderToStaticMarkup(<CacheEvidence result={{ ok: true, runs: [
    { answer: 'First', usage: { cache_write_input_tokens: 2048, cache_read_input_tokens: 0 } },
    { answer: 'Second', usage: { cache_write_input_tokens: 0, cache_read_input_tokens: 2048 } },
] }} />);
assert.ok(cache.includes('Cache read tokens'));
assert.ok(cache.includes('Cache write tokens'));
assert.equal((cache.match(/<td>2048<\/td>/g) ?? []).length, 2);
const unknown = renderToStaticMarkup(<CacheEvidence result={{ ok: true, runs: [{ answer: '', usage: { cache_write_input_tokens: null, cache_read_input_tokens: null } }] }} />);
assert.ok(unknown.includes('Not reported'));
const miss = renderToStaticMarkup(<CacheEvidence result={{ ok: true, runs: [{ answer: '', usage: { cache_write_input_tokens: 0, cache_read_input_tokens: 0 } }] }} />);
assert.ok(miss.includes('No cache activity reported'));
console.log('Guarded fetch and cache evidence UI assertions passed.');
