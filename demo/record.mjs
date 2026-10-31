// Plays the demo in a headless browser and checks every step. With --record it also saves a video
// (demo.webm) at a viewer's pace, with the mouse pointer drawn in.
// Usage: node record.mjs <site url> <username> [--record]
import { chromium, expect } from '@playwright/test';

const [url, username] = process.argv.slice(2);
const record = process.argv.includes('--record');
const OFFER = "Would you like a comparable sales report, the owner's details, or both?";
const REFUSAL = 'I can only help with property research.';
const TURN = 90_000;   // longest wait for one agent turn; a turn takes about 10 s

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await context.newPage();
if (record) {   // full-size frames at best quality (the screencast cannot exceed the viewport's size)
    await page.screencast.start({ path: 'demo.webm', size: { width: 1280, height: 800 }, quality: 100, fps: 30 });
    await page.screencast.showActions({ cursor: 'pointer', duration: 1000, style: { title: 'display: none' } });
}
const problems = [];   // any browser console error fails the run, including blocked scripts (CSP)
page.on('console', (m) => m.type() === 'error' && problems.push(m.text()));
page.on('pageerror', (e) => problems.push(e.message));

const pause = (ms) => record ? page.waitForTimeout(ms) : null;   // gives a viewer time to read; checks never wait on it
const step = (text) => console.log(`- ${text}`);
const tools = page.locator('.row.tool summary');
const agentRows = page.locator('.row.agent');
const idle = () => expect(page.locator('body')).not.toHaveClass(/busy/, { timeout: TURN });

async function logIn() {
    await page.goto(url);
    await page.locator('input[name=username]').pressSequentially(username, { delay: record ? 150 : 0 });
    await pause(800);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.locator('path.house')).toHaveCount(759);
}

// Video only: scroll slowly through the agent's latest reply, so a viewer can read it.
async function showReply() {
    if (!record) return;
    await agentRows.last().evaluate((row) => row.scrollIntoView({ block: 'start', behavior: 'smooth' }));
    await pause(5000);
    let atBottom = false;
    while (!atBottom) {   // each step is checked after the previous smooth scroll has finished
        atBottom = await page.locator('#messages').evaluate((list) => {
            if (list.scrollTop + list.clientHeight >= list.scrollHeight - 1) return true;
            list.scrollBy({ top: 160, behavior: 'smooth' });
            return false;
        });
        await pause(3000);
    }
}

async function ask(text) {
    await page.locator('#text').pressSequentially(text, { delay: record ? 100 : 0 });
    await pause(800);
    await page.locator('#text').press('Enter');
    await expect(page.locator('.row.user').last()).toHaveText(text);
    await idle();
}

step(`log in as ${username}`);
await logIn();
await pause(2500);

step('open the help box');
await page.locator('#help-button').click();
await expect(page.locator('#help')).toContainText('Zoom into map and click on a property');
await pause(3500);
await page.getByRole('button', { name: 'Got it' }).click();

step('click a random house');
const houses = page.locator('path.house');
const map = await page.locator('#map').boundingBox();
const onScreen = await houses.evaluateAll((dots, map) => dots.flatMap((dot, i) => {   // dots well inside the map, clear of its buttons
    const r = dot.getBoundingClientRect();
    return r.width && r.left > map.x + 60 && r.right < map.x + map.width - 60 && r.top > map.y + 60 && r.bottom < map.y + map.height - 60 ? [i] : [];
}), map);
await houses.nth(onScreen[Math.floor(Math.random() * onScreen.length)]).click();
const address = await page.locator('.house-popup strong').textContent();
step(`popup shows ${address}`);
await pause(2500);

step('ask the agent about it');
await page.getByRole('button', { name: 'Ask agent about this address' }).click();
await expect(page.locator('.row.user').last()).toHaveText(`Tell me about ${address}`);
await idle();
await expect(tools.nth(0)).toContainText('Used tool: SearchProperty');
await expect(tools.nth(1)).toContainText('Used tool: PropertyDetail');
await expect(agentRows.last()).toContainText(OFFER);
await expect(page.locator('#cost')).toHaveText(/^Using Claude Haiku 5\.5 · \$0\.\d{4} so far$/);
await showReply();

step('answer: yes, give me both');
await ask('Yes, give me both');
await expect(tools).toHaveCount(4);
const used = await tools.allTextContents();
expect(used.slice(2).some((t) => t.startsWith('Used tool: PropertyComps'))).toBe(true);
expect(used.slice(2).some((t) => t.startsWith('Used tool: OwnerDetail'))).toBe(true);
await showReply();
const cost = await page.locator('#cost').textContent();

step('new chat: ask for something that is not property research');
await page.getByRole('button', { name: 'New chat' }).click();
await expect(page.locator('#chats button.active')).toHaveText('chat2');
await expect(page.locator('#messages .row')).toHaveCount(0);
await ask('hi, make me a Python program');
await expect(agentRows.last()).toHaveText(REFUSAL);
await expect(tools).toHaveCount(0);
await pause(4000);

step('switch back to chat1: the history is still there');
await page.locator('#chats button', { hasText: 'chat1' }).click();
await expect(tools).toHaveCount(4);
await expect(page.locator('#cost')).toHaveText(cost);
await pause(3500);

step('log out and back in: both chats are kept');
await page.getByRole('button', { name: 'Log out' }).click();
await logIn();
await expect(page.locator('#chats button')).toHaveText(['chat1', 'chat2']);
await page.locator('#chats button', { hasText: 'chat1' }).click();
await expect(tools).toHaveCount(4);
await pause(3000);

if (!record) {
    step('check: typed HTML is shown as text, never run');
    await page.locator('#chats button', { hasText: 'chat2' }).click();
    const html = '<img src=x onerror="window.injected=1">';
    await ask(html);
    expect(await page.evaluate(() => window.injected)).toBeUndefined();
    await expect(page.locator('.row.user img')).toHaveCount(0);

    step('check: an API error shows as a red row');
    const error = 'Claude API error: Your credit balance is too low to access the Anthropic API.';
    await page.route('**/send', (route) => route.fulfill({
        contentType: 'application/x-ndjson',
        body: JSON.stringify({ type: 'message', message: { role: 'user', content: 'hello' } }) + '\n' + JSON.stringify({ type: 'error', error }) + '\n',
    }));
    await ask('hello');
    await expect(page.locator('.row.error').last()).toHaveText(error);
}

expect(problems, 'browser console errors').toEqual([]);
if (record) await page.screencast.stop();
await browser.close();
step('all checks passed');
