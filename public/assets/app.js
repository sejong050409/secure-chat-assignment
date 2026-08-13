'use strict';

const csrf = document.querySelector('meta[name="csrf-token"]').content;
const myId = Number(document.body.dataset.userId);
const wsUrl = document.body.dataset.wsUrl;
const friendsEl = document.getElementById('friends');
const messagesEl = document.getElementById('messages');
const statusEl = document.getElementById('status');
const titleEl = document.getElementById('chat-title');
let activeFriend = null;
let ws = null;

async function api(url, options = {}) {
  options.headers = { ...(options.headers || {}), 'X-CSRF-Token': csrf };
  const res = await fetch(url, options);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

async function connectWs() {
  const { token } = await api('/api/ws-token.php', { method: 'POST' });
  ws = new WebSocket(`${wsUrl}/?token=${encodeURIComponent(token)}`);
  ws.onopen = () => { statusEl.textContent = 'Connected'; };
  ws.onclose = () => { statusEl.textContent = 'Disconnected - reconnecting'; setTimeout(connectWs, 1500); };
  ws.onerror = () => { statusEl.textContent = 'WebSocket error'; };
  ws.onmessage = (event) => {
    const packet = JSON.parse(event.data);
    if (packet.type === 'new_message') {
      const m = packet.message;
      if (activeFriend && ((Number(m.sender_id) === activeFriend.id && Number(m.receiver_id) === myId) || (Number(m.sender_id) === myId && Number(m.receiver_id) === activeFriend.id))) {
        appendMessage(m);
      }
    } else if (packet.type === 'error') {
      statusEl.textContent = packet.error;
    }
  };
}

async function loadFriends() {
  const data = await api('/api/friends.php');
  friendsEl.replaceChildren();
  for (const friend of data.friends) {
    const button = document.createElement('button');
    button.className = 'friend';
    button.textContent = friend.username;
    button.addEventListener('click', () => chooseFriend({ id: Number(friend.id), username: friend.username }));
    friendsEl.appendChild(button);
  }
}

async function chooseFriend(friend) {
  activeFriend = friend;
  titleEl.textContent = `Chat with ${friend.username}`;
  messagesEl.replaceChildren();
  const data = await api(`/api/history.php?friend_id=${encodeURIComponent(friend.id)}`);
  data.messages.forEach(appendMessage);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function appendMessage(m) {
  if (document.querySelector(`[data-message-id="${m.id}"]`)) return;
  const wrap = document.createElement('article');
  wrap.className = Number(m.sender_id) === myId ? 'msg mine' : 'msg';
  wrap.dataset.messageId = m.id;
  const meta = document.createElement('small');
  meta.textContent = Number(m.sender_id) === myId ? 'Me' : (activeFriend?.username || 'Friend');
  wrap.appendChild(meta);

  if (m.type === 'text') {
    const p = document.createElement('p');
    p.textContent = m.body || '';
    wrap.appendChild(p);
  } else if (m.type === 'image' && m.attachment_id) {
    const img = document.createElement('img');
    img.src = `/api/download.php?id=${encodeURIComponent(m.attachment_id)}&inline=1`;
    img.alt = m.original_name || 'image';
    img.loading = 'lazy';
    wrap.appendChild(img);
  } else if (m.type === 'file' && m.attachment_id) {
    const a = document.createElement('a');
    a.href = `/api/download.php?id=${encodeURIComponent(m.attachment_id)}`;
    a.textContent = `Download: ${m.original_name || 'file'}`;
    wrap.appendChild(a);
  } else if (m.type === 'url') {
    let preview = {};
    try { preview = JSON.parse(m.body || '{}'); } catch (_) {}
    const card = document.createElement('div');
    card.className = 'url-card';
    const a = document.createElement('a');
    a.href = preview.url || '#';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.textContent = preview.title || preview.url || 'Link';
    card.appendChild(a);
    if (preview.description) {
      const p = document.createElement('p'); p.textContent = preview.description; card.appendChild(p);
    }
    wrap.appendChild(card);
  }
  messagesEl.appendChild(wrap);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function announce(messageId) {
  if (ws?.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'announce', message_id: messageId }));
}

document.getElementById('friend-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const input = document.getElementById('friend-name');
  const error = document.getElementById('friend-error');
  error.textContent = '';
  try {
    await api('/api/friends.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ username: input.value }) });
    input.value = '';
    await loadFriends();
  } catch (err) { error.textContent = err.message; }
});

document.getElementById('send-btn').addEventListener('click', () => {
  const input = document.getElementById('message-input');
  const body = input.value.trim();
  if (!activeFriend || !body || ws?.readyState !== WebSocket.OPEN) return;
  ws.send(JSON.stringify({ type: 'send_text', receiver_id: activeFriend.id, body }));
  input.value = '';
});

document.getElementById('message-input').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.getElementById('send-btn').click(); }
});

document.getElementById('file-input').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file || !activeFriend) return;
  const form = new FormData();
  form.append('recipient_id', activeFriend.id);
  form.append('file', file);
  try {
    const data = await api('/api/upload.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: form });
    announce(data.message.id);
  } catch (err) { statusEl.textContent = err.message; }
  e.target.value = '';
});

document.getElementById('url-btn').addEventListener('click', async () => {
  if (!activeFriend) return;
  const url = prompt('URL to attach');
  if (!url) return;
  try {
    statusEl.textContent = 'Fetching URL preview...';
    const data = await api('/api/url-preview.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ recipient_id: activeFriend.id, url }) });
    announce(data.message.id);
    statusEl.textContent = 'Connected';
  } catch (err) { statusEl.textContent = err.message; }
});

loadFriends().then(connectWs).catch(err => { statusEl.textContent = err.message; });
