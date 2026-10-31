// The page: a map of houses on the left, the chat with the agent on the right.
// The browser never talks to Claude. It only talks to server.php, which runs the agent.

const $ = (id) => document.getElementById(id);
const CITY = 'Los Angeles, CA 90045';
let chats = [];        // [{id, title}] of this user
let chatId = null;     // the chat on screen
let busy = false;      // true while the agent works on a message


// ---------- the map ----------

const map = L.map('map', { zoomControl: false });
L.control.zoom({ position: 'topright' }).addTo(map);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
}).addTo(map);

const houses = await (await fetch('/api/houses')).json();
const area = L.latLngBounds(houses.map((h) => [h.lat, h.lon])).pad(0.1);
map.fitBounds(area);
map.setMinZoom(map.getZoom());          // the whole neighborhood: the farthest you can zoom out
map.zoomIn(1, { animate: false });      // start one step closer, where the dots stand apart
map.setMaxBounds(area);
map.options.maxBoundsViscosity = 1;     // the map cannot be dragged away from the neighborhood

for (const house of houses) {
    L.circleMarker([house.lat, house.lon], { className: 'house', radius: 5, color: '#fff', weight: 1, fillColor: '#d32f2f', fillOpacity: 1 })
        .bindPopup(() => popup(house))
        .addTo(map);
}

function popup(house) {   // built from elements, never from HTML text
    const box = document.createElement('div');
    box.className = 'house-popup';
    box.append(Object.assign(document.createElement('strong'), { textContent: house.address }));
    const ask = box.appendChild(Object.assign(document.createElement('button'), { textContent: 'Ask agent about this address' }));
    ask.onclick = () => { map.closePopup(); send(`Tell me about ${house.address}, ${CITY}`); };
    return box;
}

$('help-button').onclick = () => $('help').showModal();


// ---------- the chat ----------

async function api(path, options) {
    const response = await fetch(path, options);
    if (response.status === 401) location.href = '/';   // logged out: back to the login page
    return response.json();
}

async function openChat(id) {
    const chat = await api(`/api/chats/${id}`);
    chatId = id;
    $('messages').replaceChildren();
    chat.messages.forEach(drawMessage);
    drawCost(chat.cost_usd, chat.model);
    drawChatList();
}

function drawChatList() {
    $('chats').replaceChildren(...chats.map((chat) => {
        const tab = Object.assign(document.createElement('button'), { textContent: chat.title });
        tab.classList.toggle('active', chat.id === chatId);
        tab.onclick = () => busy || openChat(chat.id);
        return tab;
    }));
}

$('new-chat').onclick = async () => {
    if (busy) return;
    chats.push(await api('/api/chats', { method: 'POST' }));
    await openChat(chats.at(-1).id);
};

// Draws one message of the conversation: exactly the messages that are sent to Claude.
// A typed message is plain text; everything else is a list of blocks, each drawn by its type.
function drawMessage(message) {
    if (typeof message.content === 'string') return addRow('user', message.content);
    for (const block of message.content) drawBlock[block.type](block);
}

const toolRows = new Map();   // tool call id -> its row, so the result can be put under its call
const drawBlock = {
    text: (block) => {
        const row = addRow('agent', '');
        row.innerHTML = DOMPurify.sanitize(marked.parse(block.text));   // Claude writes markdown
    },
    tool_use: (block) => {
        const row = document.createElement('details');
        row.className = 'row tool';
        row.append(Object.assign(document.createElement('summary'), { textContent: `Used tool: ${block.name} ${JSON.stringify(block.input)}` }));
        toolRows.set(block.id, row);
        $('messages').append(row);
        scrollDown();
    },
    tool_result: (block) => {
        const row = toolRows.get(block.tool_use_id);
        row.classList.toggle('failed', block.is_error === true);
        const text = block.is_error ? block.content : JSON.stringify(JSON.parse(block.content), null, 2);
        row.append(Object.assign(document.createElement('pre'), { textContent: text }));
    },
    thinking: () => {},            // Claude's private reasoning is kept for Claude, not shown
    redacted_thinking: () => {},
};

function addRow(kind, text) {   // kind: user | agent | error
    const row = Object.assign(document.createElement('div'), { className: `row ${kind}`, textContent: text });
    $('messages').append(row);
    scrollDown();
    return row;
}

function scrollDown() { $('messages').scrollTop = $('messages').scrollHeight; }

function drawCost(cost, model) { $('cost').textContent = `Using ${model} · $${cost.toFixed(4)} so far`; }

// Sends a message and draws the agent's work as it streams in: one JSON object per line.
async function send(text) {
    text = text.trim();
    if (!text || busy) return;
    setBusy(true);
    try {
        const response = await fetch(`/api/chats/${chatId}/send`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text }),
        });
        if (!response.ok) return addRow('error', (await response.json()).error);
        const lines = response.body.pipeThrough(new TextDecoderStream()).getReader();
        let buffer = '';
        for (;;) {
            const { value, done } = await lines.read();
            if (done) break;
            buffer += value;
            const complete = buffer.split('\n');
            buffer = complete.pop();
            for (const line of complete.map((text) => JSON.parse(text))) drawLine[line.type](line);
        }
    } finally {
        setBusy(false);
    }
}

const drawLine = {
    message: (line) => drawMessage(line.message),
    cost: (line) => drawCost(line.cost, line.model),
    error: (line) => addRow('error', line.error),
};

function setBusy(on) {
    busy = on;
    document.body.classList.toggle('busy', on);
    $('text').disabled = on;
    $('send').disabled = on;
    if (on) $('messages').append(Object.assign(document.createElement('div'), { className: 'row working', textContent: 'Agent is working' }));
    else document.querySelector('.working')?.remove();
    scrollDown();
}

$('ask').onsubmit = (event) => {
    event.preventDefault();
    send($('text').value);
    $('text').value = '';
};
$('text').onkeydown = (event) => {   // Enter sends, Shift+Enter makes a new line
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        $('ask').requestSubmit();
    }
};


// ---------- start ----------
chats = await api('/api/chats');
if (chats.length === 0) chats.push(await api('/api/chats', { method: 'POST' }));
await openChat(chats.at(-1).id);
