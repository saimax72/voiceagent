/*! VoiceAgent embeddable widget - voice & text assistant. (c) VoiceAgent. */
(function () {
  'use strict';
  if (window.__VoiceAgentWidget) { return; }

  /* ------------------------------------------------------------------ boot */
  var script = document.currentScript;
  if (!script) {
    var candidates = document.querySelectorAll('script[data-agent-id]');
    script = candidates[candidates.length - 1];
  }
  if (!script) { return; }
  var AGENT_ID = script.getAttribute('data-agent-id');
  if (!AGENT_ID) { console.warn('[VoiceAgent] Missing data-agent-id attribute'); return; }
  var BASE = (script.getAttribute('data-base') || script.src.replace(/\/widget\.js.*$/, '')).replace(/\/$/, '');
  var API = BASE + '/api/widget';
  var PREVIEW = script.getAttribute('data-preview') === '1';
  var PREVIEW_TOKEN = script.getAttribute('data-preview-token') || '';
  var MODE = script.getAttribute('data-mode') || 'live';
  var FORCE_OPEN = script.getAttribute('data-auto-open') === '1';
  var STORAGE_KEY = 'va_widget_' + AGENT_ID;

  var ICONS = {
    chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-4.5A8 8 0 1 1 21 12z"/></svg>',
    mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8 21h8"/></svg>',
    sparkle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 17l.7 2.3L22 20l-2.3.7L19 23l-.7-2.3L16 20l2.3-.7z"/></svg>',
    bot: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4m-4 4h8M9 14h.01M15 14h.01"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg>',
    headset: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14v-3a8 8 0 0 1 16 0v3"/><rect x="3" y="13" width="4" height="7" rx="2"/><rect x="17" y="13" width="4" height="7" rx="2"/></svg>',
    keyboard: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/></svg>',
    refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>',
    stop: '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
    user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    thumbUp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v11H3V10zM7 10l4-7a2.5 2.5 0 0 1 2.5 2.5V10h5a2 2 0 0 1 2 2.3l-1.4 7A2 2 0 0 1 17.2 21H7"/></svg>',
    thumbDown: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 14V3h4v11zM17 14l-4 7a2.5 2.5 0 0 1-2.5-2.5V14h-5a2 2 0 0 1-2-2.3l1.4-7A2 2 0 0 1 6.8 3H17"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>',
    speaker: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9v6h4l5 4V5L8 9z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a8 8 0 0 1 0 11"/></svg>',
    speakerOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9v6h4l5 4V5L8 9z"/><path d="M17 9l4 6M21 9l-4 6"/></svg>'
  };

  var FONT_FAMILIES = {
    'Inter': "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
    'DM Sans': "'DM Sans', system-ui, sans-serif",
    'Poppins': "'Poppins', system-ui, sans-serif",
    'Roboto': "'Roboto', system-ui, sans-serif",
    'Open Sans': "'Open Sans', system-ui, sans-serif",
    'Lato': "'Lato', system-ui, sans-serif",
    'Montserrat': "'Montserrat', system-ui, sans-serif",
    'Nunito': "'Nunito', system-ui, sans-serif",
    'Manrope': "'Manrope', system-ui, sans-serif",
    'Source Sans 3': "'Source Sans 3', system-ui, sans-serif",
    'System': "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
  };

  /* --------------------------------------------------------------- helpers */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function hexToRgb(hex) {
    hex = String(hex || '').replace('#', '');
    if (hex.length === 3) { hex = hex.split('').map(function (c) { return c + c; }).join(''); }
    var n = parseInt(hex.substring(0, 6), 16);
    if (isNaN(n)) { return { r: 91, g: 91, b: 214 }; }
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
  }
  function rgba(hex, a) { var c = hexToRgb(hex); return 'rgba(' + c.r + ',' + c.g + ',' + c.b + ',' + a + ')'; }
  function contrast(hex) { var c = hexToRgb(hex); return (c.r * 299 + c.g * 587 + c.b * 114) / 1000 > 150 ? '#0f172a' : '#ffffff'; }
  function md(text) {
    var html = esc(text);
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    html = html.replace(/(^|[^"'>])(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/(^|\s)\*([^*\n]+)\*(?=\s|$|[.,!?])/g, '$1<em>$2</em>');
    var lines = html.split('\n'), out = [], inList = false;
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var m = line.match(/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/);
      if (m) {
        if (!inList) { out.push('<ul>'); inList = true; }
        out.push('<li>' + m[1] + '</li>');
      } else {
        if (inList) { out.push('</ul>'); inList = false; }
        if (line.trim() === '') { out.push('<span class="va-br"></span>'); } else { out.push('<p>' + line + '</p>'); }
      }
    }
    if (inList) { out.push('</ul>'); }
    return out.join('');
  }
  function store(key, value) {
    if (PREVIEW && key !== 'va_visitor') { return; } // dashboard previews never persist conversations
    try { if (value === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, JSON.stringify(value)); } } catch (e) { /* ignore */ }
  }
  function load(key) { try { var v = localStorage.getItem(key); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
  function uid() { return 'v' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36); }
  function debounce(fn, ms) { var t; return function () { clearTimeout(t); var a = arguments, s = this; t = setTimeout(function () { fn.apply(s, a); }, ms); }; }

  /* ----------------------------------------------------------------- styles */
  function buildCss(w) {
    var radius = Math.max(0, Math.min(40, parseInt(w.border_radius, 10) || 14));
    var launcher = Math.max(44, Math.min(90, parseInt(w.launcher_size, 10) || 60));
    var width = Math.max(320, Math.min(560, parseInt(w.popup_width, 10) || 400));
    var height = Math.max(420, Math.min(860, parseInt(w.popup_height, 10) || 640));
    var ox = Math.max(0, Math.min(120, parseInt(w.offset_x, 10) || 24));
    var oy = Math.max(0, Math.min(120, parseInt(w.offset_y, 10) || 24));
    var side = w.position === 'left' ? 'left' : 'right';
    var otherSide = side === 'left' ? 'right' : 'left';
    var font = FONT_FAMILIES[w.font] || FONT_FAMILIES.Inter;
    var launcherRadius = w.launcher_shape === 'square' ? '5px' : (w.launcher_shape === 'rounded' ? '12px' : '50%');
    var dark = w.theme === 'dark';
    var bg = w.bg_color || (dark ? '#0f172a' : '#ffffff');
    var text = w.text_color || (dark ? '#e2e8f0' : '#0f172a');
    var muted = dark ? 'rgba(226,232,240,.6)' : '#64748b';
    var border = dark ? 'rgba(255,255,255,.08)' : '#e6e8f0';
    var inputBg = dark ? 'rgba(255,255,255,.06)' : '#f8f9fc';
    return [
      ':host{all:initial}',
      '*,*::before,*::after{box-sizing:border-box}',
      '[hidden]{display:none!important}',
      '.va-listen{border:0;background:transparent;color:var(--va-muted);cursor:pointer;width:22px;height:22px;border-radius:6px;display:inline-grid;place-items:center;padding:0;margin-left:2px}.va-listen:hover,.va-listen.on{color:var(--va-primary);background:var(--va-primary-soft)}.va-listen svg{width:13px;height:13px}',
      '.va{font-family:' + font + ';font-size:14.5px;line-height:1.5;color:' + text + ';-webkit-font-smoothing:antialiased;--va-primary:' + w.primary_color + ';--va-primary-soft:' + rgba(w.primary_color, .14) + ';--va-header-bg:' + w.header_bg + ';--va-header-text:' + w.header_text + ';--va-bg:' + bg + ';--va-text:' + text + ';--va-muted:' + muted + ';--va-border:' + border + ';--va-input-bg:' + inputBg + ';--va-bot-bg:' + w.bot_bubble_bg + ';--va-bot-text:' + w.bot_bubble_text + ';--va-user-bg:' + w.user_bubble_bg + ';--va-user-text:' + w.user_bubble_text + ';--va-btn:' + w.button_color + ';--va-btn-text:' + w.button_text_color + ';--va-radius:' + radius + 'px;--va-level:0}',
      '.va-launcher{position:fixed;' + side + ':' + ox + 'px;bottom:' + oy + 'px;z-index:' + (parseInt(w.z_index, 10) || 2147483000) + ';display:flex;align-items:center;gap:10px;flex-direction:' + (side === 'left' ? 'row' : 'row-reverse') + '}',
      '.va-launcher-btn{width:' + launcher + 'px;height:' + launcher + 'px;border-radius:' + launcherRadius + ';border:0;cursor:pointer;background:var(--va-btn);color:var(--va-btn-text);display:grid;place-items:center;box-shadow:0 10px 30px ' + rgba(w.button_color, .35) + ',0 2px 6px rgba(15,23,42,.15);transition:transform .2s cubic-bezier(.2,.8,.2,1),box-shadow .2s;position:relative;padding:0;overflow:hidden}',
      '.va-launcher-btn:hover{transform:translateY(-2px) scale(1.03)}',
      '.va-launcher-btn svg{width:' + Math.round(launcher * .46) + 'px;height:' + Math.round(launcher * .46) + 'px}',
      '.va-launcher-btn img{width:100%;height:100%;object-fit:cover}',
      '.va-launcher-btn .va-x{position:absolute;inset:0;display:grid;place-items:center;background:var(--va-btn);opacity:0;transform:rotate(-90deg) scale(.6);transition:all .25s}',
      '.va.open .va-launcher-btn .va-x{opacity:1;transform:none}',
      '.va-pulse::after{content:"";position:absolute;inset:0;border-radius:inherit;box-shadow:0 0 0 0 ' + rgba(w.button_color, .5) + ';animation:va-pulse 2.4s ease-out infinite;pointer-events:none}',
      '.va.open .va-pulse::after{animation:none}',
      '@keyframes va-pulse{0%{box-shadow:0 0 0 0 ' + rgba(w.button_color, .45) + '}70%{box-shadow:0 0 0 16px rgba(0,0,0,0)}100%{box-shadow:0 0 0 0 rgba(0,0,0,0)}}',
      '.va-label{background:var(--va-bg);color:var(--va-text);padding:9px 14px;border-radius:8px;font-weight:600;font-size:13.5px;box-shadow:0 8px 24px rgba(15,23,42,.14);border:1px solid var(--va-border);white-space:nowrap;animation:va-fade .4s}',
      '.va.open .va-label{display:none}',
      '.va-panel{position:fixed;' + side + ':' + ox + 'px;bottom:' + (oy + launcher + 14) + 'px;width:' + width + 'px;height:' + height + 'px;max-height:calc(100vh - ' + (oy + launcher + 28) + 'px);max-width:calc(100vw - ' + (ox * 2) + 'px);background:var(--va-bg);border-radius:var(--va-radius);box-shadow:0 24px 70px rgba(15,23,42,.28),0 2px 8px rgba(15,23,42,.1);display:flex;flex-direction:column;overflow:hidden;z-index:' + (parseInt(w.z_index, 10) || 2147483000) + ';transform-origin:bottom ' + side + ';opacity:0;transform:translateY(14px) scale(.96);pointer-events:none;transition:opacity .22s cubic-bezier(.2,.8,.2,1),transform .22s cubic-bezier(.2,.8,.2,1);border:1px solid var(--va-border)}',
      '.va.open .va-panel{opacity:1;transform:none;pointer-events:auto}',
      '.va-header{background:var(--va-header-bg);color:var(--va-header-text);padding:14px 14px 14px 16px;display:flex;align-items:center;gap:12px;flex-shrink:0}',
      '.va-avatar{width:40px;height:40px;border-radius:8px;background:rgba(255,255,255,.18);display:grid;place-items:center;font-weight:700;font-size:15px;overflow:hidden;flex-shrink:0}',
      '.va-avatar img{width:100%;height:100%;object-fit:cover}.va-avatar svg{width:22px;height:22px}',
      '.va-htext{flex:1;min-width:0}.va-htitle{font-weight:700;font-size:15.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.va-hsub{font-size:12.5px;opacity:.85;display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.va-hsub .va-dot{width:7px;height:7px;border-radius:50%;background:#34d399;box-shadow:0 0 0 3px rgba(52,211,153,.25);flex-shrink:0}',
      '.va-hbtn{width:34px;height:34px;border-radius:5px;border:0;background:rgba(255,255,255,.14);color:inherit;cursor:pointer;display:grid;place-items:center;transition:background .15s;flex-shrink:0;padding:0}',
      '.va-hbtn:hover{background:rgba(255,255,255,.26)}.va-hbtn svg{width:18px;height:18px}.va-hbtn.active{background:rgba(255,255,255,.3)}',
      '.va-body{flex:1;overflow-y:auto;padding:18px 16px 8px;display:flex;flex-direction:column;gap:12px;scroll-behavior:smooth;overscroll-behavior:contain}',
      '.va-body::-webkit-scrollbar{width:6px}.va-body::-webkit-scrollbar-thumb{background:' + rgba(w.text_color || '#0f172a', .15) + ';border-radius:6px}',
      '.va-msg{display:flex;gap:8px;max-width:88%;animation:va-fade .25s cubic-bezier(.2,.8,.2,1)}',
      '.va-msg.user{align-self:flex-end;flex-direction:row-reverse}.va-msg.bot{align-self:flex-start}',
      '.va-bubble{padding:11px 14px;border-radius:12px;font-size:14.5px;line-height:1.5;word-wrap:break-word;overflow-wrap:anywhere;position:relative}',
      '.va-msg.bot .va-bubble{background:var(--va-bot-bg);color:var(--va-bot-text);border-bottom-left-radius:6px}',
      '.va-msg.user .va-bubble{background:var(--va-user-bg);color:var(--va-user-text);border-bottom-right-radius:6px}',
      '.va-bubble p{margin:0}.va-bubble p+p{margin-top:8px}.va-bubble .va-br{display:block;height:6px}.va-bubble ul{margin:6px 0 2px;padding-left:18px}.va-bubble li{margin:3px 0}.va-bubble a{color:inherit;text-decoration:underline}',
      '.va-msg.bot .va-bubble a{color:var(--va-primary)}',
      '.va-meta{font-size:11px;color:var(--va-muted);margin-top:4px;display:flex;gap:6px;align-items:center;padding:0 4px}',
      '.va-msg.user .va-meta{justify-content:flex-end}',
      '.va-mic-tag{display:inline-flex;align-items:center;gap:3px}.va-mic-tag svg{width:11px;height:11px}',
      '.va-fb{display:inline-flex;gap:2px;margin-left:4px;opacity:0;transition:opacity .15s}.va-msg:hover .va-fb,.va-fb.done{opacity:1}',
      '.va-fb button{border:0;background:transparent;color:var(--va-muted);cursor:pointer;width:22px;height:22px;border-radius:6px;display:grid;place-items:center;padding:0}.va-fb button:hover,.va-fb button.on{color:var(--va-primary);background:var(--va-primary-soft)}.va-fb svg{width:13px;height:13px}',
      '.va-typing{display:inline-flex;gap:4px;padding:4px 2px}.va-typing span{width:7px;height:7px;border-radius:50%;background:var(--va-muted);opacity:.5;animation:va-blink 1.2s infinite}.va-typing span:nth-child(2){animation-delay:.2s}.va-typing span:nth-child(3){animation-delay:.4s}',
      '@keyframes va-blink{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}',
      '@keyframes va-fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}',
      '.va-welcome{text-align:center;padding:8px 6px 4px}.va-welcome .va-big{width:64px;height:64px;border-radius:12px;margin:0 auto 12px;background:var(--va-header-bg);color:var(--va-header-text);display:grid;place-items:center;font-size:24px;font-weight:700;overflow:hidden}.va-welcome .va-big img{width:100%;height:100%;object-fit:cover}.va-welcome .va-big svg{width:30px;height:30px}',
      '.va-welcome h3{margin:0 0 4px;font-size:17px;font-weight:700;color:var(--va-text)}.va-welcome p{margin:0;color:var(--va-muted);font-size:13.5px}',
      '.va-chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-start;padding:2px 0 6px}',
      '.va-chip{border:1px solid var(--va-border);background:var(--va-bg);color:var(--va-text);padding:8px 13px;border-radius:5px;font:inherit;font-size:13px;cursor:pointer;transition:all .15s;text-align:left}',
      '.va-chip:hover{border-color:var(--va-primary);color:var(--va-primary);background:var(--va-primary-soft)}',
      '.va-footer{flex-shrink:0;border-top:1px solid var(--va-border);padding:10px 12px 8px;background:var(--va-bg)}',
      '.va-input-row{display:flex;align-items:flex-end;gap:8px;background:var(--va-input-bg);border:1px solid var(--va-border);border-radius:calc(var(--va-radius) - 4px);padding:6px 6px 6px 14px;transition:border-color .15s,box-shadow .15s}',
      '.va-input-row:focus-within{border-color:var(--va-primary);box-shadow:0 0 0 3px var(--va-primary-soft)}',
      '.va-input{flex:1;border:0;background:transparent;resize:none;font:inherit;font-size:14.5px;color:var(--va-text);outline:none;max-height:120px;min-height:24px;padding:6px 0;line-height:1.4}',
      '.va-input::placeholder{color:var(--va-muted)}',
      '.va-ibtn{width:38px;height:38px;border-radius:5px;border:0;cursor:pointer;display:grid;place-items:center;flex-shrink:0;transition:all .15s;padding:0;background:transparent;color:var(--va-muted)}',
      '.va-ibtn svg{width:19px;height:19px}.va-ibtn:hover{background:var(--va-primary-soft);color:var(--va-primary)}',
      '.va-ibtn.send{background:var(--va-btn);color:var(--va-btn-text)}.va-ibtn.send:hover{filter:brightness(1.08)}.va-ibtn.send:disabled{opacity:.45;cursor:default;filter:none}',
      '.va-ibtn.mic.rec{background:#ef4444;color:#fff;animation:va-recpulse 1.2s infinite}',
      '@keyframes va-recpulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.45)}50%{box-shadow:0 0 0 8px rgba(239,68,68,0)}}',
      '.va-brand{text-align:center;font-size:11px;color:var(--va-muted);padding:7px 0 0}.va-brand a{color:inherit;text-decoration:none;font-weight:600}.va-brand a:hover{color:var(--va-primary)}',
      '.va-toolbar{display:flex;justify-content:space-between;align-items:center;padding:0 4px 6px;font-size:12px;color:var(--va-muted)}.va-toolbar button{border:0;background:transparent;color:var(--va-primary);font:inherit;font-size:12px;font-weight:600;cursor:pointer;padding:2px 4px}',
      /* voice dock */
      '.va-voice{display:none;flex-direction:column;align-items:center;gap:10px;padding:6px 6px 10px}',
      '.va.voice .va-voice{display:flex}.va.voice .va-input-row{display:none}',
      '.va-orb-wrap{position:relative;width:96px;height:96px;display:grid;place-items:center;cursor:pointer;-webkit-tap-highlight-color:transparent}',
      '.va-orb{width:72px;height:72px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#fff3,transparent 60%),linear-gradient(135deg,var(--va-primary),' + (w.header_bg || w.primary_color) + ');display:grid;place-items:center;color:#fff;box-shadow:0 12px 30px ' + rgba(w.primary_color, .4) + ';transition:transform .2s;position:relative;z-index:2}',
      '.va-orb svg{width:28px;height:28px}.va-orb-wrap:hover .va-orb{transform:scale(1.04)}',
      '.va-ring{position:absolute;inset:0;border-radius:50%;border:2px solid var(--va-primary);opacity:0;pointer-events:none}',
      '.va-state-listening .va-orb{background:linear-gradient(135deg,#ef4444,#f97316);box-shadow:0 12px 30px rgba(239,68,68,.4);transform:scale(calc(1 + var(--va-level) * .18))}',
      '.va-state-listening .va-ring{animation:va-ring 1.6s ease-out infinite;border-color:#ef4444}.va-state-listening .va-ring:nth-child(2){animation-delay:.5s}.va-state-listening .va-ring:nth-child(3){animation-delay:1s}',
      '@keyframes va-ring{0%{transform:scale(.8);opacity:.6}100%{transform:scale(1.5);opacity:0}}',
      '.va-state-thinking .va-orb{background:linear-gradient(135deg,var(--va-primary),' + (w.header_bg || w.primary_color) + ');animation:va-breathe 1.6s ease-in-out infinite}',
      '.va-state-thinking .va-ring:first-child{opacity:1;border:3px solid transparent;border-top-color:var(--va-primary);border-right-color:var(--va-primary);animation:va-spin 1s linear infinite}',
      '@keyframes va-spin{to{transform:rotate(360deg)}}@keyframes va-breathe{0%,100%{transform:scale(1)}50%{transform:scale(.92)}}',
      '.va-state-speaking .va-orb{background:linear-gradient(135deg,#10b981,#06b6d4);box-shadow:0 12px 30px rgba(16,185,129,.4)}',
      '.va-bars{display:none;align-items:center;gap:3px;height:28px}.va-state-speaking .va-bars{display:flex}.va-state-speaking .va-orb-icon{display:none}',
      '.va-bars span{width:4px;height:10px;border-radius:3px;background:#fff;animation:va-bar .9s ease-in-out infinite}.va-bars span:nth-child(2){animation-delay:.15s}.va-bars span:nth-child(3){animation-delay:.3s}.va-bars span:nth-child(4){animation-delay:.45s}.va-bars span:nth-child(5){animation-delay:.6s}',
      '@keyframes va-bar{0%,100%{height:8px}50%{height:26px}}',
      '.va-vstatus{font-size:13.5px;font-weight:600;color:var(--va-text);min-height:20px;text-align:center}',
      '.va-vhint{font-size:12px;color:var(--va-muted);text-align:center;min-height:16px;max-width:300px;line-height:1.4}',
      '.va-vactions{display:flex;gap:8px;align-items:center}',
      '.va-vbtn{border:1px solid var(--va-border);background:var(--va-bg);color:var(--va-text);font:inherit;font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:5px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}.va-vbtn svg{width:14px;height:14px}.va-vbtn:hover{border-color:var(--va-primary);color:var(--va-primary)}',
      /* lead form */
      '.va-form{background:var(--va-bot-bg);color:var(--va-bot-text);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px;align-self:stretch;animation:va-fade .25s}',
      '.va-form h4{margin:0 0 2px;font-size:14px}.va-form input,.va-form textarea{width:100%;border:1px solid var(--va-border);border-radius:6px;padding:9px 11px;font:inherit;font-size:13.5px;background:var(--va-bg);color:var(--va-text)}.va-form textarea{resize:vertical;min-height:60px}',
      '.va-form .va-fbtn{background:var(--va-btn);color:var(--va-btn-text);border:0;border-radius:5px;padding:10px;font:inherit;font-weight:600;cursor:pointer}.va-form .va-ferr{font-size:12px;color:#dc2626}',
      '.va-notice{align-self:center;font-size:12px;color:var(--va-muted);background:var(--va-input-bg);border:1px solid var(--va-border);padding:5px 10px;border-radius:20px;display:inline-flex;gap:6px;align-items:center;animation:va-fade .25s}.va-notice svg{width:13px;height:13px;color:#10b981}',
      '.va-error{align-self:center;font-size:12.5px;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:7px 12px;border-radius:8px;text-align:center}',
      '@media (max-width:480px){.va-panel{' + side + ':0;' + otherSide + ':0;bottom:0;width:100%;max-width:100%;height:100%;max-height:100%;border-radius:0;border:0}.va.open .va-launcher{display:none}}',
      '@media (prefers-reduced-motion:reduce){.va *{animation-duration:.01s!important;transition-duration:.01s!important}}'
    ].join('\n');
  }

  /* --------------------------------------------------------------- widget */
  function Widget(cfg) {
    this.cfg = cfg;
    this.w = cfg.widget;
    this.agent = cfg.agent;
    this.state = 'idle';
    this.open = false;
    this.voiceMode = false;
    this.busy = false;
    this.token = null;
    this.messages = [];
    this.ttsQueue = [];
    this.audioEl = null;
    this.recognition = null;
    this.recorder = null;
    this.mediaStream = null;
    this.analyser = null;
    this.levelTimer = null;
    this.speechChunks = [];
    this.ttsPrefetch = {};
    this.abort = null;
    this.build();
  }

  Widget.prototype.build = function () {
    var self = this;
    this.host = document.createElement('div');
    this.host.id = 'voiceagent-widget';
    this.host.setAttribute('data-agent', AGENT_ID);
    this.root = this.host.attachShadow ? this.host.attachShadow({ mode: 'open' }) : this.host;
    this.styleEl = document.createElement('style');
    this.root.appendChild(this.styleEl);
    this.container = document.createElement('div');
    this.container.className = 'va';
    this.root.appendChild(this.container);
    document.body.appendChild(this.host);
    this.render();
    this.applyConfig();
    this.loadFont();
    if (FORCE_OPEN || MODE === 'test') { setTimeout(function () { self.toggle(true); }, 300); }
    else if (this.w.auto_open && !PREVIEW) {
      var opened = false;
      try { opened = sessionStorage.getItem(STORAGE_KEY + '_opened') === '1'; } catch (e) { /* ignore */ }
      if (!opened) { setTimeout(function () { self.toggle(true); try { sessionStorage.setItem(STORAGE_KEY + '_opened', '1'); } catch (e) { /* ignore */ } }, Math.max(1, parseInt(this.w.auto_open_delay, 10) || 8) * 1000); }
    }
    if (PREVIEW) {
      window.addEventListener('message', function (ev) {
        var d = ev.data || {};
        if (d.type === 'va:config' && d.config) { self.updateConfig(d.config); }
        if (d.type === 'va:open') { self.toggle(true); }
        if (d.type === 'va:close') { self.toggle(false); }
        if (d.type === 'va:voice') { self.toggle(true); self.setVoiceMode(!!d.on); }
      });
      try { window.parent.postMessage({ type: 'va:ready', agent: AGENT_ID }, '*'); } catch (e) { /* ignore */ }
    }
  };

  Widget.prototype.render = function () {
    var self = this;
    var w = this.w;
    this.container.innerHTML =
      '<div class="va-launcher">' +
        '<button class="va-launcher-btn' + (w.launcher_pulse ? ' va-pulse' : '') + '" type="button" aria-label="Open assistant"><span class="va-licon"></span><span class="va-x">' + ICONS.close + '</span></button>' +
        '<div class="va-label" hidden></div>' +
      '</div>' +
      '<div class="va-panel" role="dialog" aria-label="Assistant">' +
        '<div class="va-header">' +
          '<div class="va-avatar"></div>' +
          '<div class="va-htext"><div class="va-htitle"></div><div class="va-hsub"><span class="va-dot"></span><span class="va-hsubtext"></span></div></div>' +
          '<button class="va-hbtn va-mute" type="button" title="Sound on / off" aria-label="Sound on or off">' + ICONS.speaker + '</button>' +
          '<button class="va-hbtn va-voicetoggle" type="button" title="Voice conversation" aria-label="Voice conversation">' + ICONS.headset + '</button>' +
          '<button class="va-hbtn va-reset" type="button" title="New conversation" aria-label="New conversation">' + ICONS.refresh + '</button>' +
          '<button class="va-hbtn va-close" type="button" aria-label="Close">' + ICONS.close + '</button>' +
        '</div>' +
        '<div class="va-body"></div>' +
        '<div class="va-footer">' +
          '<div class="va-voice">' +
            '<div class="va-orb-wrap" role="button" tabindex="0" aria-label="Talk"><span class="va-ring"></span><span class="va-ring"></span><span class="va-ring"></span><div class="va-orb"><span class="va-orb-icon">' + ICONS.mic + '</span><div class="va-bars"><span></span><span></span><span></span><span></span><span></span></div></div></div>' +
            '<div class="va-vstatus"></div><div class="va-vhint"></div>' +
            '<div class="va-vactions"><button class="va-vbtn va-stop" type="button" hidden>' + ICONS.stop + '<span>Stop</span></button><button class="va-vbtn va-typeinstead" type="button">' + ICONS.keyboard + '<span>Type instead</span></button></div>' +
          '</div>' +
          '<div class="va-input-row">' +
            '<textarea class="va-input" rows="1" aria-label="Message"></textarea>' +
            '<button class="va-ibtn mic" type="button" aria-label="Speak"></button>' +
            '<button class="va-ibtn send" type="button" aria-label="Send" disabled>' + ICONS.send + '</button>' +
          '</div>' +
          '<div class="va-brand" hidden></div>' +
        '</div>' +
      '</div>';
    var q = function (s) { return self.container.querySelector(s); };
    this.el = {
      launcher: q('.va-launcher-btn'), licon: q('.va-licon'), label: q('.va-label'), panel: q('.va-panel'), avatar: q('.va-avatar'),
      title: q('.va-htitle'), subtitle: q('.va-hsubtext'), body: q('.va-body'), input: q('.va-input'), send: q('.va-ibtn.send'), mic: q('.va-ibtn.mic'),
      brand: q('.va-brand'), voiceToggle: q('.va-voicetoggle'), mute: q('.va-mute'), orbWrap: q('.va-orb-wrap'), vstatus: q('.va-vstatus'), vhint: q('.va-vhint'),
      stop: q('.va-stop'), typeInstead: q('.va-typeinstead'), reset: q('.va-reset'), close: q('.va-close')
    };
    this.muted = load('va_muted') === true;
    this.renderMute();
    this.el.mute.addEventListener('click', function () { self.setMuted(!self.muted); });
    this.el.launcher.addEventListener('click', function () { self.toggle(); });
    this.el.close.addEventListener('click', function () { self.toggle(false); });
    this.el.reset.addEventListener('click', function () { self.resetConversation(); });
    this.el.voiceToggle.addEventListener('click', function () { self.setVoiceMode(!self.voiceMode); });
    this.el.typeInstead.addEventListener('click', function () { self.setVoiceMode(false); });
    this.el.stop.addEventListener('click', function () { self.interrupt(); });
    this.el.orbWrap.addEventListener('click', function () { self.orbTap(); });
    this.el.orbWrap.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.orbTap(); } });
    this.el.mic.addEventListener('click', function () { self.micTap(); });
    this.el.send.addEventListener('click', function () { self.submit(); });
    this.el.input.addEventListener('input', function () { self.autosize(); self.el.send.disabled = self.el.input.value.trim() === '' || self.busy; });
    this.el.input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); self.submit(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && self.open) { self.toggle(false); } });
  };

  Widget.prototype.applyConfig = function () {
    var w = this.w, a = this.agent;
    this.styleEl.textContent = buildCss(w);
    var iconHtml;
    if (w.launcher_icon === 'custom' && w.launcher_image) { iconHtml = '<img src="' + esc(w.launcher_image) + '" alt="">'; }
    else { iconHtml = ICONS[w.launcher_icon] || ICONS.chat; }
    this.el.licon.innerHTML = iconHtml;
    if (w.launcher_label) { this.el.label.textContent = w.launcher_label; this.el.label.hidden = false; } else { this.el.label.hidden = true; }
    this.el.launcher.className = 'va-launcher-btn' + (w.launcher_pulse ? ' va-pulse' : '');
    this.el.title.textContent = w.header_title || a.name;
    this.el.subtitle.textContent = w.header_subtitle || '';
    this.el.avatar.innerHTML = this.avatarHtml();
    this.el.input.placeholder = w.input_placeholder || 'Type your message...';
    this.el.mic.innerHTML = ICONS.mic;
    this.el.mic.title = w.mic_text || 'Tap to talk';
    this.el.mic.hidden = !a.voice_enabled;
    this.el.voiceToggle.hidden = !a.voice_enabled;
    this.renderMute();
    if (this.cfg.branding && this.cfg.branding.show) {
      this.el.brand.innerHTML = '<a href="' + esc(this.cfg.branding.url) + '?utm_source=widget" target="_blank" rel="noopener">' + esc(this.cfg.branding.text) + '</a>';
      this.el.brand.hidden = false;
    } else { this.el.brand.hidden = true; }
    this.renderWelcome();
    this.updateVoiceUi();
  };

  Widget.prototype.avatarHtml = function () {
    var w = this.w;
    if (w.avatar_style === 'image' && w.avatar_image) { return '<img src="' + esc(w.avatar_image) + '" alt="">'; }
    if (w.avatar_style === 'icon') { return ICONS.bot; }
    var name = (w.header_title || this.agent.name || 'A').trim();
    var parts = name.split(/\s+/);
    var initials = (parts[0] ? parts[0][0] : 'A') + (parts[1] ? parts[1][0] : '');
    return esc(initials.toUpperCase());
  };

  Widget.prototype.loadFont = function () {
    var font = this.w.font;
    if (!font || font === 'System' || document.getElementById('va-font-' + font.replace(/\s/g, ''))) { return; }
    var link = document.createElement('link');
    link.id = 'va-font-' + font.replace(/\s/g, '');
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(font).replace(/%20/g, '+') + ':wght@400;500;600;700&display=swap';
    document.head.appendChild(link);
  };

  Widget.prototype.updateConfig = function (partial) {
    var oldFont = this.w.font;
    for (var k in partial) { if (Object.prototype.hasOwnProperty.call(partial, k)) { this.w[k] = partial[k]; } }
    if (typeof this.w.suggested_questions === 'string') { this.w.suggested_questions = this.w.suggested_questions.split('\n').map(function (s) { return s.trim(); }).filter(Boolean); }
    this.applyConfig();
    if (this.w.font !== oldFont) { this.loadFont(); }
    if (this.messages.length) { this.renderMessages(); }
  };

  /* ------------------------------------------------------------- open/close */
  Widget.prototype.toggle = function (force) {
    var self = this;
    this.open = typeof force === 'boolean' ? force : !this.open;
    this.container.classList.toggle('open', this.open);
    if (this.open) {
      this.ensureConversation().then(function () { if (self.w.voice_mode_default && self.agent.voice_enabled && !self.voiceMode) { self.setVoiceMode(true); } });
      setTimeout(function () { if (!self.voiceMode) { try { self.el.input.focus(); } catch (e) { /* ignore */ } } }, 250);
    } else {
      this.interrupt();
      this.stopListening();
    }
  };

  Widget.prototype.setVoiceMode = function (on) {
    var self = this;
    if (!this.agent.voice_enabled) { on = false; }
    var wasOn = this.voiceMode;
    this.voiceMode = on;
    this.container.classList.toggle('voice', on);
    this.el.voiceToggle.classList.toggle('active', on);
    if (!on) { this.stopListening(); this.interrupt(); }
    this.updateVoiceUi();
    if (on) {
      this.setVoiceStatus(this.w.mic_text || 'Tap to talk', 'Tap the circle and ask your question');
      this.playTone(660, .08);
      // Speak the greeting once when a conversation starts in voice mode
      if (!wasOn && !this.messages.length && !this.greetingSpoken && this.speakMode() !== 'never' && this.greeting()) {
        this.greetingSpoken = true;
        this.ensureConversation().then(function () { self.speakText(self.greeting()); }).catch(function () { /* ignore */ });
      }
    }
  };

  /* Read-aloud policy: 'voice' (spoken replies only after voice input / in voice mode), 'always', 'never'. Mute wins. */
  Widget.prototype.speakMode = function () {
    if (this.muted) { return 'never'; }
    var mode = this.agent.speak_replies || (this.agent.auto_speak ? 'voice' : 'never');
    return mode === 'always' || mode === 'never' ? mode : 'voice';
  };
  Widget.prototype.setMuted = function (muted) {
    this.muted = !!muted;
    store('va_muted', this.muted ? true : null);
    if (this.muted) { this.interrupt(); }
    this.renderMute();
  };
  Widget.prototype.renderMute = function () {
    if (!this.el.mute) { return; }
    this.el.mute.innerHTML = this.muted ? ICONS.speakerOff : ICONS.speaker;
    this.el.mute.title = this.muted ? 'Sound is off - click to turn on' : 'Sound is on - click to mute';
    this.el.mute.classList.toggle('active', !this.muted);
    this.el.mute.hidden = !this.agent.voice_enabled;
  };
  /* Speak an arbitrary assistant text (greeting or a reply via the listen button). */
  Widget.prototype.speakText = function (text) {
    var self = this;
    if (!text || this.muted) { return; }
    this.ttsReset();
    if (this.abort) { try { this.abort.abort(); } catch (e) { /* ignore */ } this.abort = null; }
    this.setState('speaking'); this.setVoiceStatus(this.w.speaking_text || 'Speaking...', '');
    var parts = String(text).match(/[^.!?]+[.!?]+(\s|$)|[^.!?]+$/g) || [String(text)];
    parts.forEach(function (p) { if (p.trim()) { self.ttsEnqueue(p.trim()); } });
    this.ttsFinish();
  };

  /* ----------------------------------------------------------- conversation */
  Widget.prototype.ensureConversation = function () {
    var self = this;
    if (this.token) { return Promise.resolve(); }
    if (this.starting) { return this.starting; }
    var saved = PREVIEW ? null : load(STORAGE_KEY);
    if (saved && saved.token && saved.at && Date.now() - saved.at < 24 * 3600 * 1000) {
      this.token = saved.token;
      this.starting = this.api('/history?agent=' + encodeURIComponent(AGENT_ID) + '&token=' + encodeURIComponent(this.token), null, 'GET').then(function (data) {
        self.messages = (data.messages || []).filter(function (m) { return m.role !== 'system'; }).map(function (m) { return { id: m.id, role: m.role, content: m.content, modality: m.modality, feedback: m.feedback }; });
        self.renderMessages();
        self.starting = null;
      }).catch(function () { self.token = null; store(STORAGE_KEY, null); self.starting = null; return self.ensureConversation(); });
      return this.starting;
    }
    var body = { agent: AGENT_ID, visitor_id: this.visitorId(), page_url: location.href, referrer: document.referrer, language: this.visitorLang(), test: MODE === 'test' };
    this.starting = this.api('/conversations', body).then(function (data) {
      self.token = data.token;
      store(STORAGE_KEY, { token: data.token, at: Date.now() });
      self.messages = [];
      self.renderMessages();
      self.starting = null;
    }).catch(function (err) {
      self.starting = null;
      self.showError(err.code === 'limit' ? 'This assistant is temporarily unavailable (monthly limit reached).' : (err.message || 'The assistant is unavailable right now.'));
      throw err;
    });
    return this.starting;
  };

  Widget.prototype.visitorId = function () {
    var id = load('va_visitor');
    if (!id) { id = uid(); store('va_visitor', id); }
    return id;
  };

  Widget.prototype.resetConversation = function () {
    this.interrupt(); this.stopListening();
    if (this.token) { this.api('/end', { agent: AGENT_ID, token: this.token }).catch(function () { /* ignore */ }); }
    this.token = null; this.messages = []; store(STORAGE_KEY, null);
    this.renderMessages();
    this.ensureConversation();
  };

  Widget.prototype.api = function (path, body, method) {
    var headers = { 'Accept': 'application/json' };
    if (PREVIEW_TOKEN) { headers['X-Preview-Token'] = PREVIEW_TOKEN; }
    var opts = { method: method || 'POST', headers: headers, mode: 'cors' };
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    else if (body) { opts.body = body; }
    return fetch(API + path, opts).then(function (res) {
      return res.text().then(function (text) {
        var data = {};
        try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: 'Unexpected response' }; }
        if (!res.ok) { var err = new Error(data.error || ('Request failed (' + res.status + ')')); err.code = data.code; err.status = res.status; throw err; }
        return data;
      });
    });
  };

  /* --------------------------------------------------------------- render */
  Widget.prototype.renderWelcome = function () {
    if (this.messages.length) { return; }
    var w = this.w;
    var chips = (w.suggested_questions || []).filter(Boolean).slice(0, 6);
    var html = '<div class="va-welcome"><div class="va-big">' + this.avatarHtml() + '</div><h3>' + esc(w.greeting_text || 'Hi there!') + '</h3><p>' + esc(w.welcome_message || '') + '</p></div>';
    if (chips.length) {
      html += '<div class="va-toolbar"><span>' + esc(w.ask_me_text || 'Ask me anything') + '</span></div><div class="va-chips">' + chips.map(function (c) { return '<button class="va-chip" type="button">' + esc(c) + '</button>'; }).join('') + '</div>';
    }
    this.el.body.innerHTML = html;
    if (this.greeting()) {
      var greetingEl = this.bubble({ role: 'assistant', content: this.greeting(), id: 0 });
      this.el.body.insertBefore(greetingEl, this.el.body.querySelector('.va-toolbar'));
    }
    var self = this;
    Array.prototype.forEach.call(this.el.body.querySelectorAll('.va-chip'), function (chip) { chip.addEventListener('click', function () { self.send(chip.textContent, 'text'); }); });
  };

  Widget.prototype.renderMessages = function () {
    if (!this.messages.length) { this.renderWelcome(); return; }
    var self = this;
    this.el.body.innerHTML = '';
    if (this.greeting()) { this.el.body.appendChild(this.bubble({ role: 'assistant', content: this.greeting(), id: 0 })); }
    this.messages.forEach(function (m) { self.el.body.appendChild(self.bubble(m)); });
    this.scroll();
  };

  Widget.prototype.bubble = function (m) {
    var self = this;
    var div = document.createElement('div');
    div.className = 'va-msg ' + (m.role === 'user' ? 'user' : 'bot');
    var meta = '';
    if (m.modality === 'voice') { meta += '<span class="va-mic-tag">' + ICONS.mic + ' voice</span>'; }
    if (m.role === 'assistant' && m.content) {
      meta += '<button type="button" class="va-listen" title="Listen" aria-label="Read this reply aloud">' + ICONS.speaker + '</button>';
    }
    if (m.role === 'assistant' && m.id) {
      meta += '<span class="va-fb' + (m.feedback ? ' done' : '') + '"><button type="button" data-v="1" class="' + (m.feedback === 1 ? 'on' : '') + '" aria-label="Helpful">' + ICONS.thumbUp + '</button><button type="button" data-v="-1" class="' + (m.feedback === -1 ? 'on' : '') + '" aria-label="Not helpful">' + ICONS.thumbDown + '</button></span>';
    }
    div.innerHTML = '<div style="min-width:0"><div class="va-bubble">' + (m.role === 'user' ? esc(m.content).replace(/\n/g, '<br>') : md(m.content)) + '</div>' + (meta ? '<div class="va-meta">' + meta + '</div>' : '') + '</div>';
    var listen = div.querySelector('.va-listen');
    if (listen) {
      listen.addEventListener('click', function () {
        if (self.state === 'speaking') { self.interrupt(); return; }
        self.setMuted(false);
        self.speakText(m.content);
      });
    }
    Array.prototype.forEach.call(div.querySelectorAll('.va-fb button'), function (btn) {
      btn.addEventListener('click', function () {
        var v = parseInt(btn.getAttribute('data-v'), 10);
        var wrap = btn.parentNode;
        Array.prototype.forEach.call(wrap.querySelectorAll('button'), function (b) { b.classList.remove('on'); });
        btn.classList.add('on'); wrap.classList.add('done'); m.feedback = v;
        self.api('/feedback', { agent: AGENT_ID, token: self.token, message_id: m.id, value: v }).catch(function () { /* ignore */ });
      });
    });
    return div;
  };

  Widget.prototype.scroll = function () { var b = this.el.body; b.scrollTop = b.scrollHeight; };
  Widget.prototype.autosize = function () { var i = this.el.input; i.style.height = 'auto'; i.style.height = Math.min(120, i.scrollHeight) + 'px'; };
  Widget.prototype.showError = function (msg) {
    var d = document.createElement('div'); d.className = 'va-error'; d.textContent = msg; this.el.body.appendChild(d); this.scroll();
  };
  Widget.prototype.showNotice = function (msg) {
    var d = document.createElement('div'); d.className = 'va-notice'; d.innerHTML = ICONS.check + '<span>' + esc(msg) + '</span>'; this.el.body.appendChild(d); this.scroll();
  };

  /* ---------------------------------------------------------------- sending */
  Widget.prototype.submit = function () {
    var text = this.el.input.value.trim();
    if (!text || this.busy) { return; }
    this.el.input.value = ''; this.autosize(); this.el.send.disabled = true;
    this.send(text, 'text');
  };

  Widget.prototype.send = function (text, modality) {
    var self = this;
    if (this.busy) { return Promise.resolve(); }
    this.busy = true;
    this.interrupt();
    return this.ensureConversation().then(function () {
      if (!self.messages.length) { self.el.body.innerHTML = ''; if (self.greeting()) { self.el.body.appendChild(self.bubble({ role: 'assistant', content: self.greeting(), id: 0 })); } }
      var userMsg = { role: 'user', content: text, modality: modality };
      self.messages.push(userMsg);
      self.el.body.appendChild(self.bubble(userMsg));
      var botMsg = { role: 'assistant', content: '', modality: modality, id: null };
      var botEl = self.bubble(botMsg);
      var bubble = botEl.querySelector('.va-bubble');
      bubble.innerHTML = '<div class="va-typing"><span></span><span></span><span></span></div>';
      self.el.body.appendChild(botEl);
      self.scroll();
      self.setState('thinking');
      self.setVoiceStatus(self.w.thinking_text || 'Thinking...', '');
      var mode = self.speakMode();
      var speakEnabled = mode === 'always' || (mode !== 'never' && (modality === 'voice' || self.voiceMode));
      self.ttsReset();
      var spoken = 0;
      var full = '';
      var gotFirst = false;
      return self.stream({ agent: AGENT_ID, token: self.token, message: text, modality: modality, page_url: location.href }, function (event, data) {
        if (event === 'delta') {
          if (!gotFirst) { gotFirst = true; if (speakEnabled) { self.setState('speaking'); self.setVoiceStatus(self.w.speaking_text || 'Speaking...', ''); } }
          full += data.text;
          botMsg.content = full;
          bubble.innerHTML = md(full);
          self.scroll();
          if (speakEnabled) {
            // speak completed sentences as they arrive; the very first segment may be a clause so audio starts sooner
            var pending = full.slice(spoken);
            var m = pending.match(/^[\s\S]*?[.!?](?=\s|$)/);
            if (!m && spoken === 0 && pending.length > 70) {
              var cut = Math.max(pending.lastIndexOf(', '), pending.lastIndexOf('; '), pending.lastIndexOf(': '));
              if (cut > 30) { m = [pending.slice(0, cut + 1)]; }
            }
            while (m && m[0].trim().length > 0) {
              var sentence = m[0];
              spoken += sentence.length;
              self.ttsEnqueue(sentence.trim());
              pending = full.slice(spoken);
              m = pending.match(/^[\s\S]*?[.!?](?=\s|$)/);
              if (m && m[0].length < 8) { break; }
            }
          }
        } else if (event === 'lead') {
          self.showNotice('Your details were saved. The team will be in touch.');
        } else if (event === 'done') {
          botMsg.id = data.message_id;
          botMsg.content = data.text || full;
          full = botMsg.content;
          var newEl = self.bubble(botMsg); botEl.parentNode.replaceChild(newEl, botEl); botEl = newEl;
          if (speakEnabled) {
            var rest = full.slice(spoken).trim();
            if (rest) { self.ttsEnqueue(rest); }
            self.ttsFinish();
          }
        } else if (event === 'error') {
          bubble.innerHTML = '<span style="opacity:.75">' + esc(data.error || 'Something went wrong.') + '</span>';
        }
      }).then(function () {
        self.messages.push(botMsg);
        store(STORAGE_KEY, { token: self.token, at: Date.now() });
      }).catch(function (err) {
        bubble.innerHTML = '<span style="opacity:.75">' + esc(err.message || 'Something went wrong. Please try again.') + '</span>';
        self.ttsReset();
      }).then(function () {
        self.busy = false;
        self.el.send.disabled = self.el.input.value.trim() === '';
        if (!self.ttsActive()) { self.afterSpeaking(); }
        self.scroll();
      });
    }).catch(function (err) {
      if (err && err.message && window.console) { console.error('[VoiceAgent] send failed:', err); }
      self.busy = false; self.setState('idle');
    });
  };

  /* Read a server-sent event stream via fetch. */
  Widget.prototype.stream = function (body, onEvent) {
    var self = this;
    var headers = { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' };
    if (PREVIEW_TOKEN) { headers['X-Preview-Token'] = PREVIEW_TOKEN; }
    this.abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
    return fetch(API + '/messages', { method: 'POST', headers: headers, body: JSON.stringify(body), mode: 'cors', signal: this.abort ? this.abort.signal : undefined }).then(function (res) {
      var type = res.headers.get('content-type') || '';
      if (!res.ok || type.indexOf('text/event-stream') === -1) {
        return res.text().then(function (t) { var d = {}; try { d = JSON.parse(t); } catch (e) { /* ignore */ } var err = new Error(d.error || 'The assistant is unavailable right now.'); err.code = d.code; throw err; });
      }
      if (!res.body || !res.body.getReader) {
        return res.text().then(function (t) { self.parseSse(t, onEvent, { buf: '' }); });
      }
      var reader = res.body.getReader();
      var decoder = new TextDecoder();
      var st = { buf: '' };
      return new Promise(function (resolve, reject) {
        function pump() {
          reader.read().then(function (r) {
            if (r.done) { self.parseSse('\n\n', onEvent, st); resolve(); return; }
            self.parseSse(decoder.decode(r.value, { stream: true }), onEvent, st);
            pump();
          }).catch(reject);
        }
        pump();
      });
    });
  };

  Widget.prototype.parseSse = function (chunk, onEvent, st) {
    st.buf += chunk;
    var idx;
    while ((idx = st.buf.indexOf('\n\n')) !== -1) {
      var raw = st.buf.slice(0, idx); st.buf = st.buf.slice(idx + 2);
      var event = 'message', data = '';
      raw.split('\n').forEach(function (line) {
        if (line.indexOf('event:') === 0) { event = line.slice(6).trim(); }
        else if (line.indexOf('data:') === 0) { data += line.slice(5).trim(); }
      });
      if (!data) { continue; }
      var parsed = null;
      try { parsed = JSON.parse(data); } catch (e) { continue; }
      onEvent(event, parsed);
    }
  };

  /* ------------------------------------------------------------------ voice */
  Widget.prototype.setState = function (state) {
    this.state = state;
    var c = this.el.orbWrap;
    c.className = 'va-orb-wrap va-state-' + state;
    this.el.stop.hidden = !(state === 'speaking' || state === 'thinking');
    this.el.mic.classList.toggle('rec', state === 'listening');
  };

  Widget.prototype.setVoiceStatus = function (status, hint) {
    this.el.vstatus.textContent = status || '';
    this.el.vhint.textContent = hint || '';
  };

  Widget.prototype.updateVoiceUi = function () {
    var supported = this.sttSupported();
    if (!supported && this.agent.voice_enabled) { this.el.mic.title = 'Voice input is not supported in this browser'; }
  };

  Widget.prototype.sttSupported = function () {
    if (this.agent.stt && this.agent.stt.mode === 'server') { return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder); }
    return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
  };

  /* Language helpers: greeting and speech settings follow the visitor's language when allowed. */
  Widget.prototype.visitorLang = function () { return ((navigator.language || 'en').slice(0, 2)).toLowerCase(); };
  Widget.prototype.speechLang = function () {
    var def = this.agent.language, extra = this.agent.languages || [], nav = navigator.language || 'en-US';
    if (!def || def === 'auto') { return nav; }
    return extra.indexOf(nav.slice(0, 2).toLowerCase()) !== -1 ? nav : def;
  };
  Widget.prototype.greeting = function () {
    var g = this.agent.greetings || {};
    return g[this.visitorLang()] || this.agent.greeting || '';
  };

  Widget.prototype.orbTap = function () {
    if (this.state === 'listening') { this.stopListening(true); return; }
    if (this.state === 'speaking' || this.state === 'thinking') {
      if (this.agent.interruptible === false && this.state === 'speaking') { this.setVoiceStatus(this.w.speaking_text || 'Speaking...', 'Please wait until the assistant finishes.'); return; }
      this.interrupt();
    }
    this.startListening();
  };

  Widget.prototype.micTap = function () {
    if (!this.voiceMode) { this.setVoiceMode(true); }
    this.orbTap();
  };

  Widget.prototype.startListening = function () {
    var self = this;
    if (this.busy && this.state === 'thinking') { return; }
    if (!this.sttSupported() || (this.browserSttFailed && !(this.agent.stt && this.agent.stt.mode === 'server'))) {
      this.setVoiceStatus('Voice input is not available in this browser', 'Please use Chrome, Edge or Safari, or type your question instead.');
      return;
    }
    this.interrupt();
    this.ensureConversation().then(function () {
      if (self.agent.stt && self.agent.stt.mode === 'server') { self.startRecording(); } else { self.startRecognition(); }
    }).catch(function () { /* error already shown */ });
  };

  Widget.prototype.startRecognition = function () {
    var self = this;
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var rec = new SR();
    rec.lang = this.speechLang(); rec.interimResults = true; rec.continuous = false; rec.maxAlternatives = 1;
    var finalText = '';
    // Note: no second microphone capture here. In Chrome, opening getUserMedia while SpeechRecognition runs
    // makes the recognizer abort silently, so the orb uses a CSS pulse instead of a live level meter.
    rec.onstart = function () { self.setState('listening'); self.setVoiceStatus(self.w.listening_text || 'Listening...', 'Speak now, I am listening'); self.playTone(880, .08); self.container.style.setProperty('--va-level', '0.35'); };
    rec.onresult = function (e) {
      var interim = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) { finalText += e.results[i][0].transcript; } else { interim += e.results[i][0].transcript; }
      }
      self.setVoiceStatus(self.w.listening_text || 'Listening...', (finalText + ' ' + interim).trim());
    };
    rec.onerror = function (e) {
      self.stopLevelMeter();
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { self.setVoiceStatus('Microphone blocked', 'Please allow microphone access for this site in your browser settings, then try again.'); }
      else if (e.error === 'network' || e.error === 'language-not-supported' || e.error === 'audio-capture') {
        // Chromium builds without Google speech services (Brave, Opera, embedded browsers) report "network"
        self.browserSttFailed = true;
        self.setVoiceStatus('Voice input is not available in this browser', 'Please use Chrome, Edge or Safari, or type your question instead.');
      }
      else if (e.error !== 'aborted' && e.error !== 'no-speech') { self.setVoiceStatus('Could not hear you', 'Tap the circle and try again.'); }
      else if (e.error === 'no-speech') { self.setVoiceStatus(self.w.mic_text || 'Tap to talk', 'I did not catch that. Tap to try again.'); }
      self.recognition = null; self.setState('idle');
    };
    rec.onend = function () {
      clearTimeout(self.recTimeout);
      self.stopLevelMeter();
      self.recognition = null;
      var text = finalText.trim();
      if (text) { self.setState('thinking'); self.send(text, 'voice'); }
      else if (self.state === 'listening' || self.state === 'idle') { self.setState('idle'); self.setVoiceStatus(self.w.mic_text || 'Tap to talk', 'I did not catch that. Tap the circle and speak clearly, or type instead.'); }
    };
    this.recognition = rec;
    try { rec.start(); } catch (e) { this.recognition = null; this.setVoiceStatus('Could not start the microphone', ''); return; }
    // Safety net: if recognition never reports anything (some browsers hang silently), give up after 25s
    var self2 = this;
    clearTimeout(this.recTimeout);
    this.recTimeout = setTimeout(function () {
      if (self2.recognition === rec && self2.state === 'listening') {
        try { rec.abort(); } catch (e) { /* ignore */ }
        self2.recognition = null; self2.stopLevelMeter(); self2.setState('idle');
        self2.setVoiceStatus('No speech detected', 'Please check your microphone and tap to try again, or type your question.');
      }
    }, 25000);
  };

  Widget.prototype.startRecording = function () {
    var self = this;
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
      self.mediaStream = stream;
      var mime = '';
      ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'].some(function (t) { if (window.MediaRecorder.isTypeSupported(t)) { mime = t; return true; } return false; });
      var rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
      self.speechChunks = [];
      self.recordStart = Date.now();
      rec.ondataavailable = function (e) { if (e.data && e.data.size) { self.speechChunks.push(e.data); } };
      rec.onstop = function () {
        var duration = (Date.now() - self.recordStart) / 1000;
        var blob = new Blob(self.speechChunks, { type: rec.mimeType || mime || 'audio/webm' });
        self.releaseStream();
        if (self.cancelledRecording || blob.size < 1500 || duration < 0.6) { self.setState('idle'); self.setVoiceStatus(self.w.mic_text || 'Tap to talk', self.cancelledRecording ? '' : 'I did not catch that. Tap to try again.'); return; }
        self.setState('thinking'); self.setVoiceStatus('Transcribing...', '');
        var fd = new FormData();
        fd.append('agent', AGENT_ID); fd.append('token', self.token); fd.append('duration', String(Math.round(duration)));
        fd.append('audio', blob, 'speech.' + (blob.type.indexOf('mp4') !== -1 ? 'mp4' : (blob.type.indexOf('ogg') !== -1 ? 'ogg' : 'webm')));
        fd.append('language', self.speechLang().slice(0, 2));
        self.api('/stt', fd).then(function (data) {
          var text = (data.text || '').trim();
          if (!text) { self.setState('idle'); self.setVoiceStatus(self.w.mic_text || 'Tap to talk', 'I did not catch that. Tap to try again.'); return; }
          self.setVoiceStatus(self.w.thinking_text || 'Thinking...', text);
          self.send(text, 'voice');
        }).catch(function (err) { self.setState('idle'); self.setVoiceStatus('Transcription failed', err.message || ''); });
      };
      self.recorder = rec;
      self.cancelledRecording = false;
      rec.start(250);
      self.setState('listening'); self.setVoiceStatus(self.w.listening_text || 'Listening...', 'Speak now, then tap the circle when you are done (or pause and I will answer)');
      self.playTone(880, .08);
      self.startLevelMeter(stream, true);
      self.autoStopTimer = setTimeout(function () { self.stopListening(true); }, 45000);
    }).catch(function () { self.setVoiceStatus('Microphone blocked', 'Please allow microphone access in your browser.'); });
  };

  Widget.prototype.stopListening = function (keep) {
    clearTimeout(this.autoStopTimer);
    if (this.recognition) { try { this.recognition.stop(); } catch (e) { /* ignore */ } if (!keep) { this.recognition = null; } }
    if (this.recorder && this.recorder.state !== 'inactive') { this.cancelledRecording = !keep; try { this.recorder.stop(); } catch (e) { /* ignore */ } }
    else { this.releaseStream(); }
    this.stopLevelMeter();
    if (!keep && this.state === 'listening') { this.setState('idle'); }
  };

  Widget.prototype.releaseStream = function () {
    if (this.mediaStream) { this.mediaStream.getTracks().forEach(function (t) { t.stop(); }); this.mediaStream = null; }
  };

  /* Volume meter drives the orb scale while listening; also auto-stops server recordings after silence. */
  Widget.prototype.startLevelMeter = function (stream, autoStop) {
    var self = this;
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { return; }
      if (!stream) {
        if (!navigator.mediaDevices) { return; }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) { self.meterStream = s; self.startLevelMeter(s, false); }).catch(function () { /* ignore */ });
        return;
      }
      this.audioCtx = this.audioCtx || new AC();
      if (this.audioCtx.state === 'suspended') { try { this.audioCtx.resume(); } catch (e) { /* ignore */ } }
      var source = this.audioCtx.createMediaStreamSource(stream);
      var analyser = this.audioCtx.createAnalyser(); analyser.fftSize = 512;
      source.connect(analyser);
      var data = new Uint8Array(analyser.frequencyBinCount);
      var lastLoud = Date.now(), spoke = false;
      this.levelTimer = setInterval(function () {
        analyser.getByteTimeDomainData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) { var v = (data[i] - 128) / 128; sum += v * v; }
        var rms = Math.sqrt(sum / data.length);
        var level = Math.min(1, rms * 6);
        self.container.style.setProperty('--va-level', level.toFixed(2));
        if (autoStop) {
          if (level > 0.12) { lastLoud = Date.now(); spoke = true; }
          else if (spoke && Date.now() - lastLoud > 1200) { self.stopListening(true); }
          else if (!spoke && Date.now() - lastLoud > 8000) { self.stopListening(false); self.setVoiceStatus(self.w.mic_text || 'Tap to talk', 'I did not hear anything. Tap to try again.'); }
        }
      }, 80);
    } catch (e) { /* ignore */ }
  };

  Widget.prototype.stopLevelMeter = function () {
    clearInterval(this.levelTimer); this.levelTimer = null;
    this.container.style.setProperty('--va-level', '0');
    if (this.meterStream) { this.meterStream.getTracks().forEach(function (t) { t.stop(); }); this.meterStream = null; }
  };

  Widget.prototype.playTone = function (freq, dur) {
    if (!this.w.sound_effects) { return; }
    try {
      var AC = window.AudioContext || window.webkitAudioContext; if (!AC) { return; }
      this.audioCtx = this.audioCtx || new AC();
      var o = this.audioCtx.createOscillator(), g = this.audioCtx.createGain();
      o.frequency.value = freq; o.type = 'sine'; g.gain.value = 0.06;
      o.connect(g); g.connect(this.audioCtx.destination);
      o.start(); g.gain.exponentialRampToValueAtTime(0.0001, this.audioCtx.currentTime + dur); o.stop(this.audioCtx.currentTime + dur);
    } catch (e) { /* ignore */ }
  };

  /* ----------------------------------------------------------------- TTS */
  Widget.prototype.ttsReset = function () {
    this.ttsQueue = []; this.ttsDone = false; this.ttsPlaying = false; this.ttsPrefetch = {};
    if (this.audioEl) { try { this.audioEl.pause(); } catch (e) { /* ignore */ } this.audioEl = null; }
    if (window.speechSynthesis) { try { window.speechSynthesis.cancel(); } catch (e) { /* ignore */ } }
  };
  Widget.prototype.ttsActive = function () { return this.ttsPlaying || this.ttsQueue.length > 0; };
  Widget.prototype.ttsEnqueue = function (text) {
    text = this.speakable(text);
    if (!text) { return; }
    this.ttsQueue.push(text);
    if (this.agent.tts.mode !== 'browser') { this.ttsPrefetchAudio(text); }
    if (!this.ttsPlaying) { this.ttsNext(); }
  };
  Widget.prototype.ttsFinish = function () { this.ttsDone = true; if (!this.ttsActive()) { this.afterSpeaking(); } };
  Widget.prototype.speakable = function (t) {
    return t.replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g, '$1').replace(/https?:\/\/\S+/g, 'the link').replace(/[*_`#>]+/g, '').replace(/^\s*[-•]\s+/gm, '').replace(/\s+/g, ' ').trim();
  };
  Widget.prototype.ttsPrefetchAudio = function (text) {
    var self = this;
    if (this.ttsPrefetch[text]) { return this.ttsPrefetch[text]; }
    var headers = { 'Content-Type': 'application/json' };
    if (PREVIEW_TOKEN) { headers['X-Preview-Token'] = PREVIEW_TOKEN; }
    var p = fetch(API + '/tts', { method: 'POST', headers: headers, body: JSON.stringify({ agent: AGENT_ID, token: this.token, text: text, language: this.speechLang().slice(0, 2) }), mode: 'cors' }).then(function (res) {
      if (!res.ok) { throw new Error('tts'); }
      return res.blob();
    }).then(function (blob) { return URL.createObjectURL(blob); }).catch(function () { return null; });
    this.ttsPrefetch[text] = p;
    return p;
  };
  Widget.prototype.ttsNext = function () {
    var self = this;
    if (!this.ttsQueue.length) { this.ttsPlaying = false; if (this.ttsDone) { this.afterSpeaking(); } return; }
    this.ttsPlaying = true;
    var text = this.ttsQueue.shift();
    this.setState('speaking'); this.setVoiceStatus(this.w.speaking_text || 'Speaking...', '');
    if (this.agent.tts.mode === 'browser') {
      if (!window.speechSynthesis) { this.ttsPlaying = false; this.ttsQueue = []; this.afterSpeaking(); return; }
      var u = new SpeechSynthesisUtterance(text);
      var lang = this.speechLang();
      u.lang = lang; u.rate = Math.max(0.6, Math.min(1.6, this.agent.tts.speed || 1));
      var voice = this.pickVoice(lang);
      if (voice) { u.voice = voice; }
      u.onend = function () { self.ttsNext(); };
      u.onerror = function () { self.ttsNext(); };
      try { window.speechSynthesis.speak(u); } catch (e) { this.ttsNext(); }
      return;
    }
    this.ttsPrefetchAudio(text).then(function (url) {
      if (!url) { self.ttsNext(); return; }
      var audio = new Audio(url);
      self.audioEl = audio;
      audio.onended = function () { URL.revokeObjectURL(url); if (self.audioEl === audio) { self.ttsNext(); } };
      audio.onerror = function () { if (self.audioEl === audio) { self.ttsNext(); } };
      audio.play().catch(function () { self.ttsNext(); });
    });
  };
  Widget.prototype.pickVoice = function (lang) {
    try {
      var voices = window.speechSynthesis.getVoices() || [];
      var short = lang.slice(0, 2).toLowerCase();
      var preferred = voices.filter(function (v) { return v.lang && v.lang.toLowerCase().indexOf(short) === 0; });
      var natural = preferred.filter(function (v) { return /natural|neural|premium|enhanced|google|samantha|daniel|karen|moira|siri/i.test(v.name); });
      return natural[0] || preferred[0] || null;
    } catch (e) { return null; }
  };
  Widget.prototype.interrupt = function () {
    this.ttsReset();
    if (this.abort) { try { this.abort.abort(); } catch (e) { /* ignore */ } this.abort = null; }
    if (this.state === 'speaking' || this.state === 'thinking') { this.setState('idle'); this.setVoiceStatus(this.w.mic_text || 'Tap to talk', ''); }
  };
  Widget.prototype.afterSpeaking = function () {
    var self = this;
    if (this.state === 'listening') { return; }
    this.setState('idle');
    if (this.voiceMode && this.open && !this.busy) {
      this.setVoiceStatus(this.w.mic_text || 'Tap to talk', 'Listening again in a moment...');
      clearTimeout(this.relistenTimer);
      this.relistenTimer = setTimeout(function () { if (self.voiceMode && self.open && self.state === 'idle' && !self.busy) { self.startListening(); } }, 900);
    } else {
      this.setVoiceStatus(this.w.mic_text || 'Tap to talk', '');
    }
  };

  /* ------------------------------------------------------------ lead form */
  Widget.prototype.showLeadForm = function () {
    var self = this;
    if (!this.agent.lead_capture || this.el.body.querySelector('.va-form')) { return; }
    var fields = this.agent.lead_fields || ['name', 'email'];
    var labels = { name: 'Your name', email: 'Email address', phone: 'Phone number', message: 'How can we help?' };
    var form = document.createElement('form');
    form.className = 'va-form';
    form.innerHTML = '<h4>' + esc(this.w.lead_form_title || 'Leave your details') + '</h4>' + fields.map(function (f) {
      return f === 'message' ? '<textarea name="message" placeholder="' + esc(labels[f]) + '"></textarea>' : '<input name="' + f + '" type="' + (f === 'email' ? 'email' : (f === 'phone' ? 'tel' : 'text')) + '" placeholder="' + esc(labels[f]) + '"' + (f === 'name' ? ' required' : '') + '>';
    }).join('') + '<div class="va-ferr" hidden></div><button class="va-fbtn" type="submit">Send</button>';
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = { agent: AGENT_ID, token: self.token };
      fields.forEach(function (f) { data[f] = form.elements[f] ? form.elements[f].value : ''; });
      var err = form.querySelector('.va-ferr');
      self.api('/leads', data).then(function () { form.remove(); self.showNotice('Thanks! The team will get back to you soon.'); }).catch(function (ex) { err.textContent = ex.message; err.hidden = false; });
    });
    this.ensureConversation().then(function () { self.el.body.appendChild(form); self.scroll(); });
  };

  /* ----------------------------------------------------------- public API */
  window.__VoiceAgentWidget = true;
  fetch(API + '/config?agent=' + encodeURIComponent(AGENT_ID) + (PREVIEW_TOKEN ? '&preview_token=' + encodeURIComponent(PREVIEW_TOKEN) : ''), { mode: 'cors', headers: PREVIEW_TOKEN ? { 'X-Preview-Token': PREVIEW_TOKEN } : {} })
    .then(function (r) { if (!r.ok) { throw new Error('config ' + r.status); } return r.json(); })
    .then(function (cfg) {
      if (!cfg || !cfg.widget) { return; }
      var run = function () {
        var widget = new Widget(cfg);
        window.VoiceAgentWidget = {
          open: function () { widget.toggle(true); },
          close: function () { widget.toggle(false); },
          toggle: function () { widget.toggle(); },
          voice: function () { widget.toggle(true); widget.setVoiceMode(true); },
          send: function (text) { widget.toggle(true); widget.send(String(text), 'text'); },
          leadForm: function () { widget.toggle(true); widget.showLeadForm(); },
          setConfig: function (c) { widget.updateConfig(c); }
        };
        Array.prototype.forEach.call(document.querySelectorAll('[data-voiceagent-open]'), function (el) { el.addEventListener('click', function (e) { e.preventDefault(); widget.toggle(true); }); });
      };
      if (document.body) { run(); } else { document.addEventListener('DOMContentLoaded', run); }
    })
    .catch(function (err) { console.warn('[VoiceAgent] Could not load the assistant:', err.message); });
})();
