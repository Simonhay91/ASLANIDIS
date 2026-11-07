'use strict';
// Առանց կախվածությունների։ Node 18+ (ներկառուցված fetch)։
const http = require('http');
const fs = require('fs');
const path = require('path');

// .env ֆայլի պարզ ընթերցում
try {
  for (const line of fs.readFileSync(path.join(__dirname, '.env'), 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
} catch (_) { /* .env չկա, օգտագործվում են միջավայրի փոփոխականները */ }

const { BOT_TOKEN, CHAT_ID, PORT = 3000, TELEGRAM_API = 'https://api.telegram.org', TRUST_PROXY } = process.env;
if (!BOT_TOKEN || !CHAT_ID) {
  console.error('Պետք են BOT_TOKEN և CHAT_ID (տես .env.example)');
  process.exit(1);
}

const INDEX = path.join(__dirname, 'index.html');

async function tg(method, body) {
  const r = await fetch(`${TELEGRAM_API}/bot${BOT_TOKEN}/${method}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok || !j.ok) throw new Error(`Telegram ${method}: ${r.status} ${j.description || ''}`);
  return j.result;
}

const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// Սահմանափակում՝ IP-ի համար 5 հայտ 10 րոպեում
const hits = new Map();
function limited(ip) {
  const now = Date.now();
  const arr = (hits.get(ip) || []).filter(t => now - t < 10 * 60 * 1000);
  arr.push(now);
  hits.set(ip, arr);
  return arr.length > 5;
}

function readJson(req, max = 10 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0; const chunks = [];
    req.on('data', c => { size += c.length; if (size > max) { reject(new Error('too large')); req.destroy(); } else chunks.push(c); });
    req.on('end', () => { try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))); } catch (e) { reject(e); } });
    req.on('error', reject);
  });
}

function send(res, code, obj) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(obj));
}

function validate(d) {
  if (!d || typeof d !== 'object') return null;
  const s = (v, max) => (typeof v === 'string' ? v.trim().slice(0, max) : '');
  const out = {
    name: s(d.name, 80), phone: s(d.phone, 30), tour: s(d.tour, 80),
    date: s(d.date, 40), comment: s(d.comment, 600), people: Number(d.people)
  };
  if (out.name.length < 2) return null;
  if (!/^[+\d][\d\s()\-]{6,24}$/.test(out.phone)) return null;
  if (!Number.isInteger(out.people) || out.people < 1 || out.people > 60) return null;
  return out;
}

const server = http.createServer(async (req, res) => {
  try {
    const url = req.url.split('?')[0];

    if (req.method === 'GET' && (url === '/' || url === '/index.html')) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return fs.createReadStream(INDEX).pipe(res);
    }

    if (req.method === 'POST' && url === '/api/request') {
      const ip = (TRUST_PROXY && req.headers['x-forwarded-for'] ? String(req.headers['x-forwarded-for']).split(',')[0].trim() : req.socket.remoteAddress) || 'x';
      if (limited(ip)) return send(res, 429, { ok: false });

      let body;
      try { body = await readJson(req); } catch (_) { return send(res, 400, { ok: false }); }

      if (body && body.website) return send(res, 200, { ok: true }); // թակարդ ռոբոտների համար

      const d = validate(body);
      if (!d) return send(res, 400, { ok: false });

      const id = Date.now().toString(36).toUpperCase();
      const text =
        `🚌 <b>Նոր հայտ #${id}</b>\n\n` +
        `<b>Անուն՝</b> ${esc(d.name)}\n` +
        `<b>Հեռախոս՝</b> ${esc(d.phone)}\n` +
        `<b>Տուր՝</b> ${esc(d.tour || '—')}\n` +
        `<b>Ուղևորներ՝</b> ${d.people}\n` +
        `<b>Ամսաթիվ՝</b> ${esc(d.date || '—')}\n` +
        (d.comment ? `<b>Մեկնաբանություն՝</b> ${esc(d.comment)}\n` : '');

      try {
        await tg('sendMessage', {
          chat_id: CHAT_ID,
          text,
          parse_mode: 'HTML',
          reply_markup: { inline_keyboard: [[
            { text: '✅ Ընդունել', callback_data: `ok:${id}` },
            { text: '❌ Մերժել', callback_data: `no:${id}` }
          ]] }
        });
      } catch (e) {
        console.error(e.message);
        return send(res, 502, { ok: false });
      }
      return send(res, 200, { ok: true });
    }

    res.writeHead(404); res.end('Not found');
  } catch (e) {
    console.error(e);
    if (!res.headersSent) send(res, 500, { ok: false });
  }
});

// Կոճակների սեղմումները (Ընդունել / Մերժել)՝ long polling
let offset = 0;
async function poll() {
  for (;;) {
    try {
      const updates = await tg('getUpdates', { offset, timeout: 30, allowed_updates: ['callback_query'] });
      for (const u of updates) {
        offset = u.update_id + 1;
        const cq = u.callback_query;
        if (!cq || !cq.message || String(cq.message.chat.id) !== String(CHAT_ID)) continue;
        const [action] = String(cq.data || '').split(':');
        if (action !== 'ok' && action !== 'no') continue;
        const label = action === 'ok' ? '✅ Ընդունված է' : '❌ Մերժված է';
        await tg('answerCallbackQuery', { callback_query_id: cq.id, text: label }).catch(() => {});
        await tg('editMessageReplyMarkup', {
          chat_id: cq.message.chat.id,
          message_id: cq.message.message_id,
          reply_markup: { inline_keyboard: [[{ text: label, callback_data: 'done' }]] }
        }).catch(e => console.error(e.message));
      }
    } catch (e) {
      console.error('poll:', e.message);
      await new Promise(r => setTimeout(r, 5000));
    }
  }
}

server.listen(PORT, () => {
  console.log(`Սերվերը աշխատում է՝ http://localhost:${PORT}`);
  poll();
});
