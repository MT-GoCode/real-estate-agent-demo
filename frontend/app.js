// The page: a map of houses on the left, the chat with the agent on the right.
// The browser never talks to Claude. It only talks to server.php, which runs the agent.

const $ = (id) => document.getElementById(id);
const make = (tag, properties) => Object.assign(document.createElement(tag), properties);   // a new page element
let chats = [];        // [{id, title}] of this user
let chatId = null;     // the chat on screen

async function api(path, options) {
    const response = await fetch(path, options);
    if (response.status === 401) {               // logged out: go back to the login page
        location.href = '/';
        return new Promise(() => {});            // ...and stop here
    }
    return response.json();
}


// ---------- the map ----------

const map = L.map('map', { zoomControl: false });
L.control.zoom({ position: 'topright' }).addTo(map);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
}).addTo(map);

const houses = await api('/api/houses');
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
    const box = make('div', { className: 'house-popup' });
    const ask = make('button', { textContent: 'Ask agent about this address' });
    ask.onclick = () => { map.closePopup(); send(`Tell me about ${house.address}`); };
    box.append(make('strong', { textContent: house.address }), ask);
    return box;
}

$('help-button').onclick = () => $('help').showModal();


// ---------- the chat ----------

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
        const tab = make('button', { textContent: chat.title });
        if (chat.id === chatId) tab.classList.add('active');
        tab.onclick = () => openChat(chat.id);
        return tab;
    }));
}

$('new-chat').onclick = async () => {
    $('new-chat').disabled = true;   // a double click must not make two chats
    chats.push(await api('/api/chats', { method: 'POST' }));
    await openChat(chats.at(-1).id);
    $('new-chat').disabled = false;
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
        const row = make('details', { className: 'row tool' });
        row.append(make('summary', { textContent: `Used tool: ${block.name} ${JSON.stringify(block.input)}` }));
        toolRows.set(block.id, row);
        $('messages').append(row);
        scrollDown();
    },
    tool_result: (block) => {
        const row = toolRows.get(block.tool_use_id);
        if (block.is_error) row.classList.add('failed');
        const text = block.is_error ? block.content : JSON.stringify(JSON.parse(block.content), null, 2);
        row.append(make('pre', { textContent: text }));
    },
    thinking: () => {},            // Claude's private reasoning is kept for Claude, not shown
    redacted_thinking: () => {},
};

function addRow(kind, text) {   // kind: user | agent | error
    const row = make('div', { className: `row ${kind}`, textContent: text });
    $('messages').append(row);
    scrollDown();
    return row;
}

function scrollDown() { $('messages').scrollTop = $('messages').scrollHeight; }

function drawCost(cost, model) { $('cost').textContent = `Using ${model} · $${cost.toFixed(4)} so far`; }

// Sends a message and draws the agent's work as it streams in: one JSON object per line.
async function send(text) {
    text = text.trim();
    if (!text || $('send').disabled) return;
    setBusy(true);
    try {
        const response = await fetch(`/api/chats/${chatId}/send`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text }),
        });
        if (!response.ok) return addRow('error', (await response.json()).error);
        const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
        let unfinished = '';                         // a line can arrive in pieces
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            const lines = (unfinished + value).split('\n');
            unfinished = lines.pop();                // the last piece has no newline yet
            for (const line of lines) {
                const event = JSON.parse(line);
                drawEvent[event.type](event);
            }
        }
    } catch (error) {
        addRow('error', `Lost the connection to the server: ${error.message}`);
    } finally {
        setBusy(false);
    }
}

const drawEvent = {
    message: (event) => drawMessage(event.message),
    cost: (event) => drawCost(event.cost, event.model),
    error: (event) => addRow('error', event.error),
};

function setBusy(on) {   // while the agent works: show "Agent is working…" and lock the chat controls
    document.body.classList.toggle('busy', on);
    for (const control of $('chat').querySelectorAll('button, textarea')) control.disabled = on;
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


// ---------- start: open the user's latest chat (a new user gets chat1) ----------

chats = await api('/api/chats');
if (chats.length === 0) chats.push(await api('/api/chats', { method: 'POST' }));
await openChat(chats.at(-1).id);
setBusy(false);   // the chat starts locked in app.html until it has loaded
