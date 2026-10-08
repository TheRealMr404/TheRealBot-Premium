'use strict';
/**
 * سرویس امضای TON برای فروش خودکار از فرگمنت.
 *
 * کلید ولت (عبارت ۲۴ کلمه‌ای) فقط اینجا می‌ماند و هرگز در دیتابیس فروشگاه (PHP) ذخیره نمی‌شود. دو راه تنظیم:
 *   ۱) متغیر محیطی TON_MNEMONIC (فایل .env)؛
 *   ۲) وارد کردن از پنل فروشگاه (POST /config): با AES-256-GCM و کلیدی که از توکن سرویس ساخته می‌شود رمز شده و
 *      در فایل signer-config.json کنار همین سرویس می‌ماند؛ بدون توکن فایل بی‌فایده است.
 * فروشگاه فقط از طریق HTTP با توکن محرمانه این سرویس را صدا می‌زند:
 *   GET  /config  · POST /config · DELETE /config/wallet   وضعیت و تنظیم ولت، آدرس toncenter و سقف‌ها (از پنل)
 *   GET  /health                  بدون توکن؛ فقط «زنده‌بودن»
 *   GET  /info                    آدرس، موجودی، حساب TON Connect (account/device)
 *   POST /ton-proof               امضای ورود به فرگمنت (TON Connect ton_proof)
 *   POST /send                    امضا و ارسال تراکنش (سقف هر تراکنش و روزانه، کلید یکتا ضد ارسال دوباره)
 *   GET  /status?hash=…           آیا تراکنش روی شبکه نشسته؟ (با seqno ولت)
 */
const http = require('node:http');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { mnemonicToPrivateKey, mnemonicValidate, sign } = require('@ton/crypto');
const { WalletContractV4, WalletContractV5R1, JettonMaster, JettonWallet, TonClient, internal, external, storeMessage, storeStateInit, beginCell, Address, Cell, SendMode, fromNano } = require('@ton/ton');

const NANO = 1_000_000_000n;
const USDT_MASTER = 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';   // USDT روی شبکه‌ی TON
const VERSIONS = { v4r2: 'v4R2', v5r1: 'v5R1' };
const makeWallet = (version, publicKey) => (version === 'v5r1' ? WalletContractV5R1.create({ publicKey, workchain: 0 }) : WalletContractV4.create({ publicKey, workchain: 0 }));
const sha256 = (b) => crypto.createHash('sha256').update(b).digest();

/** رمزکردن یک متن محرمانه با کلید برگرفته از توکن (AES-256-GCM + scrypt) */
function seal(text, token) {
  const salt = crypto.randomBytes(16), iv = crypto.randomBytes(12);
  const key = crypto.scryptSync(String(token), salt, 32);
  const c = crypto.createCipheriv('aes-256-gcm', key, iv);
  const data = Buffer.concat([c.update(String(text), 'utf8'), c.final()]);
  return { v: 1, salt: salt.toString('base64'), iv: iv.toString('base64'), tag: c.getAuthTag().toString('base64'), data: data.toString('base64') };
}
function unseal(box, token) {
  const key = crypto.scryptSync(String(token), Buffer.from(box.salt, 'base64'), 32);
  const d = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(box.iv, 'base64'));
  d.setAuthTag(Buffer.from(box.tag, 'base64'));
  return Buffer.concat([d.update(Buffer.from(box.data, 'base64')), d.final()]).toString('utf8');
}

class HttpFail extends Error {
  constructor(status, message) { super(message); this.status = status; }
}

/** وضعیت HTTP یک خطای کتابخانه‌ی شبکه (axios) */
const statusOf = (e) => Number(e?.response?.status || e?.status || 0);

/** خطای شبکه‌ی TON را به پیام قابل‌فهم تبدیل می‌کند (به‌جای «خطای داخلی»؛ بدون نشت کلید یا آدرس) */
function explain(e) {
  if (e instanceof HttpFail) return e;
  const st = statusOf(e);
  const code = String(e?.code || '');
  const msg = String(e?.message || e || '').replace(/(api[_-]?key|token)=[^&\s]+/gi, '$1=***').slice(0, 200);
  if (st === 429) return new HttpFail(503, 'toncenter درخواست‌ها را محدود کرد (HTTP 429). در پنل ← راه‌اندازی ← «کلید API toncenter» یک کلید رایگان بگذارید (از t.me/tonapibot) و دوباره تلاش کنید.');
  if (st === 401 || st === 403) return new HttpFail(503, `toncenter کلید API را نپذیرفت (HTTP ${st}). کلید را بررسی کنید.`);
  if (st >= 500) return new HttpFail(503, `toncenter خطا داد (HTTP ${st}). کمی بعد دوباره تلاش کنید.`);
  if (st) return new HttpFail(503, `toncenter پاسخ نامعتبر داد (HTTP ${st}): ${msg}`);
  if (/ENOTFOUND|EAI_AGAIN|ECONNREFUSED|ECONNRESET|ETIMEDOUT|EHOSTUNREACH|ENETUNREACH|timeout|socket hang up|fetch failed/i.test(code + ' ' + msg)) {
    return new HttpFail(503, `اتصال سرویس امضا به toncenter برقرار نشد (${code || msg}). دسترسی اینترنت سرور یا فیلتربودن toncenter را بررسی کنید؛ در پنل می‌توانید آدرس endpoint دیگری بدهید.`);
  }
  return new HttpFail(500, `خطای شبکه‌ی TON: ${msg}`);
}

/** زنجیره‌ی واقعی TON از طریق toncenter (با فاصله‌ی ایمن بین درخواست‌ها و تکرار در خطای ۴۲۹) */
function tonChain(endpoint, apiKey, wallet) {
  const client = new TonClient({ endpoint, apiKey: apiKey || undefined });
  const contract = client.open(wallet);
  // toncenter بدون کلید فقط ۱ درخواست در ثانیه می‌پذیرد؛ با کلید ۱۰ تا
  const gap = apiKey ? 150 : 1150;
  let last = 0;
  let lane = Promise.resolve();
  const run = (fn) => {
    const job = lane.then(async () => {
      for (let attempt = 0; ; attempt++) {
        const wait = last + gap - Date.now();
        if (wait > 0) await new Promise((r) => setTimeout(r, wait));
        last = Date.now();
        try { return await fn(); }
        catch (e) {
          if (statusOf(e) === 429 && attempt < 4) { await new Promise((r) => setTimeout(r, 1200 * (attempt + 1))); continue; }
          console.error('ton chain error:', String(e?.message || e).replace(/(api[_-]?key|token)=[^&\s]+/gi, '$1=***').slice(0, 200));
          throw explain(e);
        }
      }
    });
    lane = job.catch(() => {});
    return job;
  };
  // ولتی که هنوز فعال نشده seqno صفر دارد؛ هر خطای دیگری (شبکه، ۴۲۹) نباید به‌عنوان «صفر» خوانده شود
  const notDeployed = (e) => /exit[_ ]code:?\s*-13|not (deployed|found)|uninit|Account (state )?is not active/i.test(String(e?.message || ''));
  return {
    name: 'ton',
    seqno: () => run(async () => { try { return await contract.getSeqno(); } catch (e) { if (notDeployed(e)) return 0; throw e; } }),
    balance: () => run(() => client.getBalance(wallet.address)),
    send: (boc) => run(() => client.sendFile(boc)),
    usdt: (owner) => run(async () => {
      try {
        const master = client.open(JettonMaster.create(Address.parse(USDT_MASTER)));
        const jw = await master.getWalletAddress(owner);
        return await client.open(JettonWallet.create(jw)).getBalance();
      } catch (e) { if (statusOf(e) === 429) throw e; return 0n; }   // کیف jetton هنوز ساخته نشده یعنی موجودی صفر
    }),
  };
}

/** زنجیره‌ی درون‌حافظه‌ای فقط برای تست خودکار (CHAIN=memory) */
function memoryChain(opts = {}) {
  const st = { seqno: opts.seqno ?? 1, balance: opts.balance ?? 50n * NANO, usdt: opts.usdt ?? 0n, sent: [], hold: !!opts.hold };
  return { name: 'memory', state: st, seqno: async () => st.seqno, balance: async () => st.balance, usdt: async () => st.usdt, send: async (boc) => { st.sent.push(boc); if (!st.hold) st.seqno += 1; } };
}

async function createApp(cfg) {
  const now = cfg.now || (() => Math.floor(Date.now() / 1000));
  const limits = { perTx: 0n, perDay: 0n };
  if (!cfg.token || String(cfg.token).length < 16) throw new Error('SIGNER_TOKEN باید حداقل ۱۶ نویسه باشد.');

  /* ---------- تنظیمات ماندگار پنل (ولت رمزشده، آدرس toncenter، سقف‌ها) ---------- */
  let saved = { mnemonic: null, endpoint: null, apiKey: null, maxTonPerTx: null, maxTonPerDay: null, walletVersion: null };
  if (cfg.configFile && fs.existsSync(cfg.configFile)) { try { saved = { ...saved, ...JSON.parse(fs.readFileSync(cfg.configFile, 'utf8')) }; } catch { /* فایل خراب: از صفر */ } }
  const saveConfig = () => {
    if (!cfg.configFile) return;
    fs.writeFileSync(cfg.configFile + '.tmp', JSON.stringify(saved), { mode: 0o600 });
    fs.renameSync(cfg.configFile + '.tmp', cfg.configFile);
  };
  const applyLimits = () => {
    limits.perTx = BigInt(Math.round(Number(saved.maxTonPerTx ?? cfg.maxTonPerTx ?? 20) * 1e9));
    limits.perDay = BigInt(Math.round(Number(saved.maxTonPerDay ?? cfg.maxTonPerDay ?? 100) * 1e9));
  };
  applyLimits();
  const endpointOf = () => saved.endpoint || cfg.endpoint || 'https://toncenter.com/api/v2/jsonRPC';
  let apiKeyOf = () => { try { return saved.apiKey ? unseal(saved.apiKey, cfg.token) : (cfg.apiKey || ''); } catch { return cfg.apiKey || ''; } };

  let keyPair = null, wallet = null, chain = null, source = null, walletError = '';
  const buildChain = () => { chain = cfg.chain || tonChain(endpointOf(), apiKeyOf(), wallet); };
  const parseWords = (m) => {
    const words = String(m || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (words.length !== 24) throw new HttpFail(400, 'عبارت بازیابی باید دقیقاً ۲۴ کلمه باشد.');
    if (!words.every((w) => /^[a-z]{3,10}$/.test(w))) throw new HttpFail(400, 'عبارت بازیابی فقط کلمه‌های انگلیسی دارد.');
    return words;
  };
  const versionOf = () => (saved.walletVersion || cfg.walletVersion || 'v4r2').toLowerCase();
  let curWords = null;   // فقط در حافظه (برای عوض‌کردن نسخه‌ی ولت بدون وارد کردن دوباره‌ی عبارت)
  async function applyWallet(words, from) {
    const kp = await mnemonicToPrivateKey(words);
    keyPair = kp; curWords = words;
    wallet = makeWallet(versionOf(), kp.publicKey);
    source = from; walletError = '';
    buildChain();
  }
  if (cfg.mnemonic) {
    const words = String(cfg.mnemonic).trim().split(/\s+/);
    if (words.length !== 24) throw new Error('TON_MNEMONIC باید دقیقاً ۲۴ کلمه باشد.');
    await applyWallet(words, 'env');
  } else if (saved.mnemonic) {
    try { await applyWallet(unseal(saved.mnemonic, cfg.token).split(' '), 'panel'); }
    catch { walletError = 'کلید ولت ذخیره‌شده با این توکن باز نشد (توکن عوض شده؟). دوباره از پنل وارد کنید.'; }
  }
  const needWallet = () => { if (!wallet) throw new HttpFail(409, walletError || 'ولت هنوز تنظیم نشده است؛ عبارت ۲۴ کلمه‌ای را از پنل فروشگاه (فروش خودکار فرگمنت ← تنظیمات) وارد کنید.'); };

  /* ---------- وضعیت ماندگار (کلید یکتا، سقف روزانه، آخرین seqno) ---------- */
  let state = { idem: {}, byHash: {}, daily: { date: '', nano: '0' }, last: null };
  if (cfg.stateFile && fs.existsSync(cfg.stateFile)) { try { state = { ...state, ...JSON.parse(fs.readFileSync(cfg.stateFile, 'utf8')) }; } catch { /* فایل خراب: از صفر */ } }
  const persist = () => {
    const keys = Object.keys(state.byHash);
    if (keys.length > 500) for (const k of keys.slice(0, keys.length - 500)) delete state.byHash[k];
    if (!cfg.stateFile) return;
    fs.writeFileSync(cfg.stateFile + '.tmp', JSON.stringify(state), { mode: 0o600 });
    fs.renameSync(cfg.stateFile + '.tmp', cfg.stateFile);
  };

  /* ---------- حساب TON Connect ---------- */
  const account = () => (needWallet(), {
    address: wallet.address.toRawString(), chain: '-239', publicKey: keyPair.publicKey.toString('hex'),
    walletStateInit: beginCell().store(storeStateInit(wallet.init)).endCell().toBoc().toString('base64'),
  });
  // مشخصات دستگاه همان‌طور که کلاینت متن‌باز pyfragment (آزموده‌شده با فرگمنت واقعی) می‌فرستد
  const device = () => ({ platform: 'iphone', appName: 'Tonkeeper', appVersion: '26.07.1', maxProtocolVersion: 2, features: ['SendTransaction', { name: 'SendTransaction', maxMessages: 255 }, { name: 'SignData', types: ['text', 'binary', 'cell'] }] });

  async function info() {
    needWallet();
    const bal = await chain.balance();
    return {
      address: wallet.address.toString({ urlSafe: true, bounceable: false }), rawAddress: wallet.address.toRawString(), publicKey: keyPair.publicKey.toString('hex'),
      balance: fromNano(bal), network: 'mainnet', version: VERSIONS[versionOf()] || 'v4R2', chain: chain.name, account: account(), device: device(),
      limits: { perTx: fromNano(limits.perTx), perDay: fromNano(limits.perDay) },
    };
  }

  /* ---------- ton_proof (مشخصات TON Connect) ---------- */
  function tonProof(payload, domain, ts) {
    needWallet();
    if (!payload || typeof payload !== 'string' || payload.length > 256) throw new HttpFail(400, 'payload نامعتبر است.');
    if (!domain || typeof domain !== 'string' || domain.length > 253) throw new HttpFail(400, 'domain نامعتبر است.');
    const wc = Buffer.alloc(4); wc.writeInt32BE(wallet.address.workChain);
    const dom = Buffer.from(domain, 'utf8');
    const dlen = Buffer.alloc(4); dlen.writeUInt32LE(dom.length);
    const tsb = Buffer.alloc(8); tsb.writeBigUInt64LE(BigInt(ts));
    const message = Buffer.concat([Buffer.from('ton-proof-item-v2/'), wc, wallet.address.hash, dlen, dom, tsb, Buffer.from(payload, 'utf8')]);
    const full = Buffer.concat([Buffer.from([0xff, 0xff]), Buffer.from('ton-connect'), sha256(message)]);
    const signature = sign(sha256(full), keyPair.secretKey);
    return { timestamp: ts, domain: { lengthBytes: dom.length, value: domain }, payload, signature: signature.toString('base64') };
  }

  /* ---------- ارسال تراکنش ---------- */
  let queue = Promise.resolve();
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  function parseMessages(list) {
    if (!Array.isArray(list) || list.length < 1 || list.length > 4) throw new HttpFail(400, 'تعداد پیام‌ها باید بین ۱ تا ۴ باشد.');
    let total = 0n;
    const out = list.map((m) => {
      if (!m || typeof m.address !== 'string') throw new HttpFail(400, 'آدرس مقصد نامعتبر است.');
      let to, bounce = true;
      try {
        if (/^-?\d+:[0-9a-fA-F]{64}$/.test(m.address)) to = Address.parseRaw(m.address);
        else { const f = Address.parseFriendly(m.address); to = f.address; bounce = f.isBounceable; }
      } catch { throw new HttpFail(400, 'آدرس مقصد قابل‌خواندن نیست.'); }
      if (!/^\d{1,20}$/.test(String(m.amount))) throw new HttpFail(400, 'مبلغ نامعتبر است.');
      const value = BigInt(m.amount);
      if (value <= 0n) throw new HttpFail(400, 'مبلغ باید مثبت باشد.');
      let body;
      if (m.payload) { try { body = Cell.fromBase64(String(m.payload)); } catch { throw new HttpFail(400, 'payload قابل‌خواندن نیست.'); } }
      total += value;
      return { to, value, bounce, body };
    });
    return { msgs: out, total };
  }

  async function doSend(input) {
    needWallet();
    const { msgs, total } = parseMessages(input.messages);
    const t = now();
    const validUntil = Number(input.validUntil || t + 300);
    if (!Number.isFinite(validUntil) || validUntil <= t || validUntil > t + 900) throw new HttpFail(400, 'validUntil باید در ۱۵ دقیقه‌ی آینده باشد.');
    const idem = input.idem ? String(input.idem).slice(0, 80) : '';

    if (idem && state.idem[idem]) {
      const rec = state.byHash[state.idem[idem]];
      if (rec) {
        const cur = await chain.seqno();
        if (cur > rec.seqno) rec.included = true;
        if (rec.included || t <= rec.validUntil + 120) { persist(); return { hash: rec.hash, boc: rec.boc, seqno: rec.seqno, validUntil: rec.validUntil, duplicate: true }; }
        // مهلت گذشته و روی شبکه ننشسته: ارسال تازه مجاز است
      }
    }
    if (total > limits.perTx) throw new HttpFail(400, `مبلغ از سقف هر تراکنش (${fromNano(limits.perTx)} TON) بیشتر است.`);
    const day = new Date(t * 1000).toISOString().slice(0, 10);
    if (state.daily.date !== day) state.daily = { date: day, nano: '0' };
    if (BigInt(state.daily.nano) + total > limits.perDay) throw new HttpFail(400, `سقف روزانه (${fromNano(limits.perDay)} TON) پر می‌شود.`);
    const bal = await chain.balance();
    if (bal < total + 30_000_000n) throw new HttpFail(400, `موجودی ولت (${fromNano(bal)} TON) برای این تراکنش کافی نیست.`);

    // تراکنش قبلی باید اول روی شبکه بنشیند تا seqno تکراری نشود
    let seqno = await chain.seqno();
    const deadline = Date.now() + (cfg.waitPreviousMs ?? 100_000);
    while (state.last && seqno <= state.last.seqno && now() < state.last.validUntil) {
      if (Date.now() > deadline) throw new HttpFail(503, 'تراکنش قبلی هنوز روی شبکه ننشسته است؛ کمی بعد دوباره تلاش کنید.');
      await sleep(cfg.pollMs ?? 2000);
      seqno = await chain.seqno();
    }

    const transfer = wallet.createTransfer({
      seqno, secretKey: keyPair.secretKey, sendMode: SendMode.PAY_GAS_SEPARATELY | SendMode.IGNORE_ERRORS, timeout: validUntil,
      messages: msgs.map((m) => internal({ to: m.to, value: m.value, bounce: m.bounce, body: m.body })),
    });
    const ext = external({ to: wallet.address, init: seqno === 0 ? wallet.init : undefined, body: transfer });
    const cell = beginCell().store(storeMessage(ext)).endCell();
    const boc = cell.toBoc();
    const hash = cell.hash().toString('hex');
    await chain.send(boc);

    const rec = { hash, boc: boc.toString('base64'), seqno, validUntil, included: false, at: t, nano: total.toString() };
    state.byHash[hash] = rec;
    if (idem) state.idem[idem] = hash;
    state.daily.nano = (BigInt(state.daily.nano) + total).toString();
    state.last = { seqno, validUntil };
    persist();
    return { hash, boc: rec.boc, seqno, validUntil, duplicate: false };
  }

  async function status(hash) {
    needWallet();
    hash = String(hash || '').toLowerCase();
    if (!/^[a-f0-9]{64}$/.test(hash)) return { known: false, included: false, expired: false };
    const rec = state.byHash[hash];
    if (!rec) return { known: false, included: false, expired: false };
    const cur = await chain.seqno();
    if (cur > rec.seqno && !rec.included) { rec.included = true; persist(); }
    return { known: true, included: rec.included, expired: !rec.included && now() > rec.validUntil + 120, seqno: rec.seqno };
  }

  /* ---------- مدیریت ولت و تنظیمات از پنل ---------- */
  const pending = () => !!(state.last && now() < state.last.validUntil && !state.byHash[Object.keys(state.byHash).find((k) => state.byHash[k].seqno === state.last.seqno)]?.included);
  const publicConfig = () => ({
    configured: !!wallet, source, error: walletError || undefined, walletVersion: VERSIONS[versionOf()] || 'v4R2',
    address: wallet ? wallet.address.toString({ urlSafe: true, bounceable: false }) : null,
    endpoint: endpointOf(), hasApiKey: !!apiKeyOf(),
    limits: { perTx: fromNano(limits.perTx), perDay: fromNano(limits.perDay) },
    canChangeWallet: source !== 'env', pendingTransaction: pending(),
  });
  const cleanUrl = (v) => {
    const u = String(v || '').trim();
    if (u.length > 300) throw new HttpFail(400, 'آدرس خیلی بلند است.');
    let url; try { url = new URL(u); } catch { throw new HttpFail(400, 'آدرس endpoint نامعتبر است.'); }
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    if (!(url.protocol === 'https:' || (url.protocol === 'http:' && local))) throw new HttpFail(400, 'آدرس endpoint باید https باشد.');
    return u;
  };
  async function setConfig(b) {
    if (b.walletVersion !== undefined && String(b.walletVersion).trim() !== '') {
      const v = String(b.walletVersion).toLowerCase();
      if (!VERSIONS[v]) throw new HttpFail(400, 'نسخه‌ی ولت باید V4R2 یا V5R1 باشد.');
      if (v !== versionOf()) {
        if (source === 'env') throw new HttpFail(409, 'ولت از متغیر محیطی تنظیم شده و نسخه‌اش از پنل قابل تغییر نیست.');
        if (wallet && pending()) throw new HttpFail(409, 'یک تراکنش هنوز روی شبکه ننشسته است؛ چند دقیقه بعد دوباره امتحان کنید.');
        saved.walletVersion = v;
        if (curWords && wallet) { await applyWallet(curWords, source); state.last = null; }
      }
    }
    if (b.mnemonic !== undefined && String(b.mnemonic).trim() !== '') {
      if (source === 'env') throw new HttpFail(409, 'ولت از متغیر محیطی TON_MNEMONIC تنظیم شده و از پنل قابل تغییر نیست.');
      if (pending()) throw new HttpFail(409, 'یک تراکنش هنوز روی شبکه ننشسته است؛ چند دقیقه بعد دوباره امتحان کنید.');
      const words = parseWords(b.mnemonic);
      if (!(await mnemonicValidate(words))) throw new HttpFail(400, 'عبارت بازیابی معتبر نیست (یک کلمه اشتباه یا ترتیب نادرست است).');
      saved.mnemonic = seal(words.join(' '), cfg.token);
      if (b.walletVersion === undefined || String(b.walletVersion).trim() === '') saved.walletVersion = saved.walletVersion || versionOf();
      await applyWallet(words, 'panel');
      state.last = null;
    }
    if (b.endpoint !== undefined && String(b.endpoint).trim() !== '') saved.endpoint = cleanUrl(b.endpoint);
    if (b.endpoint === '') saved.endpoint = null;
    if (b.apiKey !== undefined && String(b.apiKey).trim() !== '') {
      if (String(b.apiKey).length > 200) throw new HttpFail(400, 'کلید API خیلی بلند است.');
      saved.apiKey = seal(String(b.apiKey).trim(), cfg.token);
    }
    if (b.clearApiKey) saved.apiKey = null;
    for (const [k, label] of [['maxTonPerTx', 'سقف هر تراکنش'], ['maxTonPerDay', 'سقف روزانه']]) {
      if (b[k] === undefined || b[k] === '' || b[k] === null) continue;
      const n = Number(b[k]);
      if (!Number.isFinite(n) || n <= 0 || n > 100000) throw new HttpFail(400, `${label} نامعتبر است.`);
      saved[k] = n;
    }
    applyLimits();
    if (wallet) buildChain();
    saveConfig(); persist();
    return publicConfig();
  }
  function removeWallet() {
    if (source === 'env') throw new HttpFail(409, 'ولت از متغیر محیطی TON_MNEMONIC تنظیم شده و از پنل قابل حذف نیست.');
    if (pending()) throw new HttpFail(409, 'یک تراکنش هنوز روی شبکه ننشسته است؛ چند دقیقه بعد دوباره امتحان کنید.');
    saved.mnemonic = null; keyPair = null; wallet = null; chain = null; source = null; walletError = ''; state.last = null; curWords = null;
    saveConfig(); persist();
    return publicConfig();
  }

  /* ---------- HTTP ---------- */
  const tokenBuf = Buffer.from(String(cfg.token));
  const authed = (req) => {
    const h = String(req.headers.authorization || '');
    const given = Buffer.from(h.startsWith('Bearer ') ? h.slice(7) : '');
    return given.length === tokenBuf.length && crypto.timingSafeEqual(given, tokenBuf);
  };
  const readJson = (req) => new Promise((resolve, reject) => {
    let size = 0; const chunks = [];
    req.on('data', (c) => { size += c.length; if (size > 65536) { reject(new HttpFail(413, 'درخواست بزرگ است.')); req.destroy(); } else chunks.push(c); });
    req.on('end', () => { try { resolve(chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {}); } catch { reject(new HttpFail(400, 'JSON نامعتبر است.')); } });
    req.on('error', reject);
  });

  const server = http.createServer(async (req, res) => {
    const send = (code, obj) => { const b = JSON.stringify(obj); res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }); res.end(b); };
    try {
      const url = new URL(req.url, 'http://x');
      if (req.method === 'GET' && url.pathname === '/health') return send(200, { ok: true });
      if (!authed(req)) throw new HttpFail(401, 'توکن نامعتبر است.');
      if (req.method === 'GET' && url.pathname === '/config') return send(200, publicConfig());
      if (req.method === 'POST' && url.pathname === '/config') return send(200, await setConfig(await readJson(req)));
      if (req.method === 'DELETE' && url.pathname === '/config/wallet') return send(200, removeWallet());
      if (req.method === 'GET' && url.pathname === '/usdt') { needWallet(); const raw = chain.usdt ? await chain.usdt(wallet.address) : 0n; return send(200, { balance: (Number(raw) / 1e6).toString(), raw: raw.toString() }); }
      if (req.method === 'GET' && url.pathname === '/info') return send(200, await info());
      if (req.method === 'GET' && url.pathname === '/status') return send(200, await status(url.searchParams.get('hash')));
      if (req.method === 'POST' && url.pathname === '/ton-proof') {
        const b = await readJson(req);
        return send(200, { account: account(), device: device(), proof: tonProof(b.payload, b.domain, b.timestamp || now()) });
      }
      if (req.method === 'POST' && url.pathname === '/send') {
        const b = await readJson(req);
        // ارسال‌ها پشت‌سرهم اجرا می‌شوند تا seqno هیچ‌وقت تکراری نشود
        const job = queue.then(() => doSend(b));
        queue = job.catch(() => {});
        return send(200, await job);
      }
      throw new HttpFail(404, 'مسیر پیدا نشد.');
    } catch (e) {
      const f = explain(e);
      if (!(e instanceof HttpFail)) console.error('signer error:', (e && e.stack ? String(e.stack).split('\n').slice(0, 4).join(' | ') : String(e)).replace(/(api[_-]?key|token)=[^&\s]+/gi, '$1=***'));
      send(f.status, { error: f.message });
    }
  });

  return { server, get wallet() { return wallet; }, get keyPair() { return keyPair; }, get chain() { return chain; }, info, tonProof, account, doSend, status, publicConfig, setConfig, removeWallet };
}

module.exports = { createApp, memoryChain, tonChain, seal, unseal };

if (require.main === module) {
  (async () => {
    const env = process.env;
    const chain = env.CHAIN === 'memory' ? memoryChain() : undefined;
    if (chain) console.warn('⚠ CHAIN=memory: فقط برای تست است و هیچ تراکنش واقعی ارسال نمی‌شود.');
    // توکن: از SIGNER_TOKEN؛ اگر نبود، یک‌بار ساخته و در signer-token.txt ذخیره می‌شود (نیازی به ویرایش فایل نیست)
    let token = env.SIGNER_TOKEN;
    const tokenFile = env.TOKEN_FILE || `${__dirname}/signer-token.txt`;
    let generated = false;
    if (!token) {
      if (fs.existsSync(tokenFile)) token = fs.readFileSync(tokenFile, 'utf8').trim();
      else { token = crypto.randomBytes(24).toString('hex'); fs.writeFileSync(tokenFile, token + '\n', { mode: 0o600 }); generated = true; }
    }
    const app = await createApp({
      mnemonic: env.TON_MNEMONIC, walletVersion: env.WALLET_VERSION, token, endpoint: env.TON_ENDPOINT, apiKey: env.TON_API_KEY, chain,
      maxTonPerTx: env.MAX_TON_PER_TX || 20, maxTonPerDay: env.MAX_TON_PER_DAY || 100,
      stateFile: env.STATE_FILE || `${__dirname}/state.json`, configFile: env.CONFIG_FILE || `${__dirname}/signer-config.json`,
    });
    const host = env.HOST || '127.0.0.1', port = Number(env.PORT || 8787);
    app.server.listen(port, host, () => {
      console.log(`TON signer روی http://${host}:${port} | ` + (app.wallet ? `ولت: ${app.wallet.address.toString({ urlSafe: true, bounceable: false })}` : 'ولت هنوز تنظیم نشده (از پنل فروشگاه وارد کنید)'));
      if (generated || !env.SIGNER_TOKEN) console.log(`توکن سرویس در فایل امن ${tokenFile} ساخته یا بارگذاری شد.`);
    });
  })().catch((e) => { console.error('راه‌اندازی ناموفق:', e.message); process.exit(1); });
}
