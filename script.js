(function(){
  // --- Constants and DOM references ---
  const DATA_PATH = './data/data.json';
  const pageWash = document.getElementById('page-wash');
  const banner = document.getElementById('banner');
  const bannerPill = document.getElementById('banner-pill');
  const overallMessageEl = document.getElementById('overall-message');
  const overallSubEl = document.getElementById('overall-sub');
  const lastUpdatedEl = document.getElementById('last-updated');
  const tzSelect = document.getElementById('tz');
  const tzNote = document.getElementById('tz-note');
  const cardsEl = document.getElementById('cards');
  const emptyEl = document.getElementById('empty');
  const errorEl = document.getElementById('error');
  const countsEl = document.getElementById('counts');
  const searchEl = document.getElementById('search');
  const themeBtn = document.getElementById('themeBtn');
  const themeIcon = document.getElementById('themeIcon');
  const themeLabel = document.getElementById('themeLabel');

  let DATA = null;
  let LABEL_MAP = {};
  let selectedTZ = null;
  let pollHandle = null;

  // --- Utility Functions ---

  function sanitizeHTML(dirty) {
    if (!dirty) return '';
    const doc = new DOMParser().parseFromString(dirty, 'text/html');
    doc.querySelectorAll('script,style,iframe,object,embed').forEach(n => n.remove());
    [...doc.querySelectorAll('*')].forEach(node => {
      [...node.attributes].forEach(attr => {
        const name = attr.name.toLowerCase();
        const val = attr.value || '';
        if (name.startsWith('on')) { node.removeAttribute(attr.name); return; }
        if ((name === 'href' || name === 'src') && val.trim().toLowerCase().startsWith('javascript:')) { node.removeAttribute(attr.name); return; }
        if ((name === 'href' || name === 'src') && val.trim().toLowerCase().startsWith('data:')) { node.removeAttribute(attr.name); return; }
      });
    });
    return doc.body.innerHTML;
  }

  function localOffsetHours() { return -new Date().getTimezoneOffset()/60; }
  function formatTZLabel(offset) { 
    if (offset === 0) return `GMT`;
    const sign = offset >= 0 ? '+' : '-'; 
    return `GMT${sign}${String(Math.abs(offset)).padStart(2,'0')}`; 
  }

  function parseTimestampAsUTC(ts) {
    if (!ts) return null;
    let s = String(ts).trim();
    // CRITICAL: Ensure string is parsed as UTC if no TZ info is provided in the JSON data
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) {
         s = s.replace(' ', 'T') + 'Z';
    } else {
        s = s.replace(' ', 'T');
    }
    const d = new Date(s);
    return isNaN(d) ? null : d;
  }

  function applyOffsetToDate(utcDate, offsetHours) {
    if (!utcDate) return null;
    // Mathematically shift the UTC time by the selected offset
    const offsetMs = offsetHours * 3600 * 1000;
    return new Date(utcDate.getTime() + offsetMs);
  }
  
  /**
   * CRITICAL FIX: Formats the Date object into YYYY-MM-DD HH:MM using UTC methods 
   * to prevent the browser from applying its own local offset a second time.
   * @param {Date} date - The date object already adjusted by selectedTZ offset.
   * @param {number} offsetHours - The target offset used to adjust the date.
   * @returns {string} The formatted local time string.
   */
  function formatLocal(date, offsetHours) {
    if (!date) return '—';
    const pad = n => String(n).padStart(2,'0');
    
    // We use getUTC* methods on the offset-adjusted Date object.
    // This is the key to preventing the double-offset bug.
    return date.getUTCFullYear() + '-' + pad(date.getUTCMonth()+1) + '-' + pad(date.getUTCDate()) + ' ' + pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes());
  }
  
  function timeAgoFrom(date) {
    if (!date) return 'unknown';
    // Time ago must always be calculated from the original UTC timestamp against current time
    const diff = Math.floor((Date.now() - date.getTime())/1000); 
    if (diff < 10) return 'just now';
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
  }

  function buildLabelMap(labels) {
    const map = {};
    (labels || []).forEach(l => { if (!l || !l.type) return; map[String(l.type)] = { label: l.label || l.type, color: l.color || null }; });
    return map;
  }
  function pickColorForType(type) {
    // Default palette for types
    const palette = ['#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#10b981','#06b6d4','#38bdf8','#60a5fa','#7c3aed','#a78bfa','#f472b6'];
    if (!type) return '#94a3b8';
    let hash = 0;
    for (let i=0;i<type.length;i++) hash = ((hash<<5)-hash) + type.charCodeAt(i);
    const idx = Math.abs(hash) % palette.length;
    return palette[idx];
  }

  function hexToRgb(hex) {
    if (!hex) return null;
    let c = hex.replace('#','');
    if (c.length === 3) c = c.split('').map(ch=>ch+ch).join('');
    const r = parseInt(c.substr(0,2),16);
    const g = parseInt(c.substr(2,2),16);
    const b = parseInt(c.substr(4,2),16);
    return {r,g,b};
  }
  function hexToRgba(hex, a=1) {
    const rgb = hexToRgb(hex) || {r:7,g:18,b:34};
    return `rgba(${rgb.r},${rgb.g},${rgb.b},${a})`;
  }
  function luminance(hex) {
    const rgb = hexToRgb(hex);
    if (!rgb) return 0;
    const srgb = [rgb.r/255, rgb.g/255, rgb.b/255].map(v => v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4));
    return 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2];
  }
  function contrast(hex1, hex2) {
    const L1 = luminance(hex1) + 0.05;
    const L2 = luminance(hex2) + 0.05;
    return Math.max(L1, L2) / Math.min(L1, L2);
  }
  function bestTextColorOn(hexBg) {
    const white = '#ffffff';
    const black = getComputedStyle(document.documentElement).getPropertyValue('--page-text').trim() || '#071226';
    return contrast(hexBg, white) >= 4.5 ? white : black;
  }

  // --- Core Rendering Logic ---

  /**
   * Applies the ambient glow effect to the entire page background.
   * CRITICAL FIX: Uses a single solid color for full, even coverage.
   * @param {string} color - The hex color of the latest status.
   */
  function setPageWash(color) {
    if (!color) { pageWash.style.opacity = '0'; return; }

    // Use a solid color wash across the entire background
    const rgbaColor = hexToRgba(color, 1.0); // Use 1.0 here, rely on CSS var(--wash-opacity) and mix-blend-mode
    
    pageWash.style.background = rgbaColor;
    pageWash.style.opacity = getComputedStyle(document.documentElement).getPropertyValue('--wash-opacity').trim();
  }

  /**
   * Builds the DOM for a single status card, applying the card-body accent.
   * @param {object} s - Status data object.
   * @param {object} labelMap - Map of status types to label info.
   * @param {number} tzOffsetHours - Timezone offset in hours.
   * @returns {object} The card DOM element and metadata.
   */
  function buildCardDOM(s, labelMap, tzOffsetHours) {
    const card = document.createElement('article'); card.className = 'card';
    const accentBar = document.createElement('div'); accentBar.className = 'card-accent';
    const body = document.createElement('div'); body.className = 'card-body';
    const header = document.createElement('div'); header.className = 'card-header';
    const title = document.createElement('div'); title.className = 'title'; title.textContent = s.title || '(untitled)';
    const timeDiv = document.createElement('div'); timeDiv.className = 'time';

    const utc = parseTimestampAsUTC(s.timestamp);
    // Apply offset to get the target time representation
    const local = utc ? applyOffsetToDate(utc, tzOffsetHours) : null; 
    
    // Use the fixed formatLocal function which bypasses browser's local TZ settings
    timeDiv.textContent = local ? (formatLocal(local, tzOffsetHours) + ' — ' + timeAgoFrom(utc)) : 'no time';

    header.appendChild(title);
    header.appendChild(timeDiv);

    const labelKey = s.type || '';
    const labelInfo = labelMap[labelKey] || { label: (s.label_override || labelKey || 'Unknown'), color: null };
    const color = labelInfo.color || pickColorForType(labelKey);
    const labelEl = document.createElement('div'); labelEl.className = 'label';
    labelEl.textContent = (s.label_override && s.label_override.trim()) ? s.label_override : (labelInfo.label || labelKey || 'Unknown');
    labelEl.style.background = color;
    labelEl.style.color = bestTextColorOn(color);

    const desc = document.createElement('div'); desc.className = 'desc'; desc.innerHTML = sanitizeHTML(s.description || '');
    const metaRow = document.createElement('div'); metaRow.className = 'meta-row';
    metaRow.textContent = `id: ${s.id || '—'}`;

    // 1. Accent Bar (Top Line) gets the solid color
    accentBar.style.background = color;

    // 2. Card Body gets the greenish/reddish tint
    const rgbaTint = hexToRgba(color, 0.15); 
    body.style.background = rgbaTint;
    body.style.boxShadow = `inset 0 0 0 1px ${hexToRgba(color, 0.25)}` 

    body.appendChild(header);
    body.appendChild(labelEl);
    body.appendChild(desc);
    body.appendChild(metaRow);

    card.appendChild(accentBar);
    card.appendChild(body);

    return { card, utcDate: utc, labelColor: color };
  }

  function renderBannerAndWash() {
    const statuses = (DATA.statuses || []).slice();
    banner.style.display = 'flex';
    bannerPill.textContent = `${statuses.length || 0} statuses`;

    const withDates = statuses.map(s => ({s, d: parseTimestampAsUTC(s.timestamp)}));
    withDates.sort((a,b) => {
      const ta = a.d ? a.d.getTime() : 0;
      const tb = b.d ? b.d.getTime() : 0;
      return tb - ta;
    });
    const latest = withDates.length ? withDates[0].s : null;
    const latestDate = withDates.length ? withDates[0].d : null;
    const latestDateLocal = applyOffsetToDate(latestDate, selectedTZ);

    // Use the fixed formatLocal function here too
    lastUpdatedEl.textContent = latestDate ? (formatLocal(latestDateLocal, selectedTZ) + ' — ' + timeAgoFrom(latestDate)) : '—';
    overallSubEl.textContent = `Summary — last update: ${lastUpdatedEl.textContent}`;

    let washColor = null;
    if (latest) {
      const t = latest.type || '';
      const info = LABEL_MAP[t] || {};
      washColor = info.color || pickColorForType(t);
    }
    if (!latest) washColor = '#10b981'; // Green default when no statuses

    // Banner styling
    bannerPill.style.background = washColor;
    bannerPill.style.color = bestTextColorOn(washColor);
    banner.style.borderColor = hexToRgba(washColor, 0.3); 
    // Banner background: light translucent tint of the wash
    banner.style.background = `linear-gradient(180deg, ${hexToRgba(washColor,0.12)}, rgba(255,255,255,0.02))`;
    
    // Overall title color
    overallMessageEl.style.color = washColor;
    
    // Apply the global page wash
    setPageWash(washColor);
  }

  function renderStatuses() {
    cardsEl.innerHTML = '';
    const statuses = (DATA.statuses || []).slice();
    if (!statuses.length) {
      emptyEl.style.display = 'block';
      cardsEl.style.display = 'none';
      countsEl.textContent = `0 statuses`;
      overallMessageEl.innerHTML = sanitizeHTML((DATA.overall_message && String(DATA.overall_message).trim()) ? DATA.overall_message : 'All Systems Operational');
      return;
    } else {
      emptyEl.style.display = 'none';
      cardsEl.style.display = 'flex';
      overallMessageEl.innerHTML = sanitizeHTML((DATA.overall_message && String(DATA.overall_message).trim()) ? DATA.overall_message : 'All Systems Operational');
    }

    const q = (searchEl.value || '').trim().toLowerCase();
    const filtered = statuses.filter(s => {
      if (!q) return true;
      if ((s.title||'').toLowerCase().includes(q)) return true;
      if ((s.id||'').toLowerCase().includes(q)) return true;
      if ((s.description||'').toLowerCase().includes(q)) return true;
      if ((s.label_override||'').toLowerCase().includes(q)) return true;
      if ((s.type||'').toLowerCase().includes(q)) return true;
      return false;
    });

    filtered.sort((a,b) => {
      const da = parseTimestampAsUTC(a.timestamp);
      const db = parseTimestampAsUTC(b.timestamp);
      const ta = da ? da.getTime() : 0;
      const tb = db ? db.getTime() : 0;
      return tb - ta;
    });

    filtered.forEach(s => {
      const { card } = buildCardDOM(s, LABEL_MAP, selectedTZ);
      cardsEl.appendChild(card);
    });

    countsEl.textContent = `${filtered.length} of ${statuses.length} statuses`;
  }

  async function fetchAndUpdate(showErrorOnFail = true) {
    try {
      const res = await fetch(DATA_PATH, { cache: 'no-store' });
      if (!res.ok) throw new Error('non-ok status ' + res.status);
      const json = await res.json();
      DATA = json;
      LABEL_MAP = buildLabelMap(DATA.custom_labels || []);
      renderBannerAndWash();
      renderStatuses();
      errorEl.style.display = 'none';
      return true;
    } catch (err) {
      console.error('poll fetch failed', err);
      if (!DATA) {
        banner.style.display = 'none';
        cardsEl.style.display = 'none';
        emptyEl.style.display = 'none';
        errorEl.style.display = 'block';
        errorEl.textContent = "We are having an issue fetching status data — maybe we're down and even the status page is down.";
        return false;
      } else {
        if (showErrorOnFail) {
          errorEl.style.display = 'block';
          errorEl.textContent = "Temporary issue fetching latest data — showing cached statuses.";
          setTimeout(()=> { if (errorEl) errorEl.style.display = 'none'; }, 6000);
        }
        return false;
      }
    }
  }

  // --- Initialization and Event Listeners ---

  function initTZ() {
    const opts = [];
    // PER USER ORDER: Limit the range of time zones strictly from GMT-12 to GMT+12.
    for (let h = -12; h <= 12; h++) {
      const label = formatTZLabel(h);
      const opt = document.createElement('option');
      opt.value = String(h);
      opt.textContent = label;
      tzSelect.appendChild(opt);
    }
    
    // Set the initial value to local time
    const local = localOffsetHours();
    // Ensure the initial local time offset is within the new range [-12, +12]
    const clampedLocal = Math.min(12, Math.max(-12, local));
    
    selectedTZ = clampedLocal;
    tzSelect.value = String(clampedLocal);
    tzNote.textContent = `Times shown in ${formatTZLabel(clampedLocal)} (auto-detected)`;
    
    tzSelect.addEventListener('change', () => {
      selectedTZ = Number(tzSelect.value);
      tzNote.textContent = `Times shown in ${formatTZLabel(selectedTZ)}`;
      renderStatuses();
      renderBannerAndWash();
    });
  }

  function initTheme() {
    const html = document.documentElement;
    function setTheme(mode) {
      if (mode === 'dark') { html.classList.remove('light'); html.classList.add('dark'); themeIcon.textContent = '🌙'; themeLabel.textContent = 'Dark'; }
      else { html.classList.remove('dark'); html.classList.add('light'); themeIcon.textContent = '🌞'; themeLabel.textContent = 'Light'; }
    }
    setTheme('light');
    themeBtn.addEventListener('click', () => {
      const cur = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
      setTheme(cur === 'dark' ? 'light' : 'dark');
      // Re-run wash render to ensure mix-blend-mode variable updates correctly
      renderBannerAndWash(); 
    });
  }

  searchEl.addEventListener('input', () => renderStatuses());
  searchEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') renderStatuses(); });

  (async function boot() {
    initTZ();
    initTheme();
    const ok = await fetchAndUpdate(true);
    if (!ok) return;
    pollHandle = setInterval(() => fetchAndUpdate(false), 10000);
    window.__STATUS_DATA = DATA;
    window.__RENDER_STATUSES = () => { renderBannerAndWash(); renderStatuses(); };
  })();

})();
