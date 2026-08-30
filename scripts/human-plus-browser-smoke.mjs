import { chromium } from 'playwright';
import { spawn } from 'node:child_process';

function runPhpSmoke() {
  return new Promise((resolve, reject) => {
    const child = spawn(process.env.PHP_BINARY ?? 'php', ['artisan', 'lab:human-plus:smoke'], {
      cwd: process.cwd(),
      env: process.env,
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true,
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; });
    child.on('error', reject);
    child.on('close', code => {
      if (code !== 0) {
        reject(new Error(`PHP Human+ smoke failed (${code}).\n${stdout}\n${stderr}`));
        return;
      }
      let proof;
      try {
        proof = JSON.parse(stdout);
      } catch (error) {
        reject(new Error(`PHP Human+ smoke returned invalid JSON.\n${stdout}\n${stderr}`, { cause: error }));
        return;
      }
      if (proof.ok !== true || proof.form_describe_received !== true) {
        reject(new Error(`PHP Human+ smoke returned an incomplete proof.\n${stdout}`));
        return;
      }
      resolve(proof);
    });
  });
}

const browser = await chromium.launch({ headless: true, args: ['--allow-running-insecure-content'] });
try {
  const page = await browser.newPage({ ignoreHTTPSErrors: true });
  page.on('console', message => process.stderr.write(`browser console: ${message.text()}\n`));
  page.on('pageerror', error => process.stderr.write(`browser error: ${error.message}\n`));
  await page.goto(process.env.PRISM_HUMAN_PLUS_FIXTURE_URL ?? 'https://plabs.gen/lab/human-plus-fixture', { waitUntil: 'networkidle' });
  try {
    await page.getByText(/relay open/).waitFor({ timeout: 15_000 });
  } catch (error) {
    process.stderr.write(`fixture text: ${(await page.locator('body').innerText()).slice(0, 1000)}\n`);
    throw error;
  }
  process.stdout.write('Human+ browser surface is active\n');
  const proof = await runPhpSmoke();
  process.stdout.write(`${JSON.stringify(proof, null, 2)}\n`);
} finally {
  await browser.close();
}
