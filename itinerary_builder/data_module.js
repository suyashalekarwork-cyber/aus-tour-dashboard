const _SK = 'au_itinerary_corrections';
window.DATA_MODULE = {
  THEMES: THEMES,
  CATALOG: DATA,
  TEMPLATES: TEMPLATES,
  STORAGE_KEY: _SK,
  loadCorrections() {
    try { return JSON.parse(localStorage.getItem(_SK) || '{}'); } catch(e) { return {}; }
  },
  saveCorrections(c) {
    localStorage.setItem(_SK, JSON.stringify(c));
  },
  rewriteName(sk, name, corrections) {
    const key = sk + ':' + name, c = corrections[key];
    if (!c) return [name];
    if (c.action === 'rename') return [c.target || name];
    if (c.action === 'merge') return [c.target || name];
    if (c.action === 'remove') return [];
    if (c.action === 'split') return c.parts || [name];
    return [name];
  },
  resolveCatalog(corrections) {
    if (!corrections || !Object.keys(corrections).length) return DATA;
    const self = this, result = {};
    for (const [sk, sd] of Object.entries(DATA)) {
      const np = {}, nc = Object.assign({}, sd.cities || {});
      for (const [name, prod] of Object.entries(sd.products)) {
        for (const nn of self.rewriteName(sk, name, corrections)) {
          if (nn in np) {
            const ex = np[nn];
            ex.pop = (ex.pop || 0) + (prod.pop || 0);
            ex.t = Array.from(new Set([...(ex.t || []), ...(prod.t || [])]));
            ex.board = ex.board || prod.board;
            ex.op = ex.op || prod.op;
          } else {
            np[nn] = Object.assign({}, prod);
            if (!(nn in nc) && sd.cities && sd.cities[name]) nc[nn] = sd.cities[name];
          }
        }
      }
      const nt = (sd.templates || []).map(t => {
        const nd = (t.days || []).map(day => {
          const arr = [];
          (day.places || []).forEach(p => { self.rewriteName(sk, p, corrections).forEach(r => { if (!arr.includes(r)) arr.push(r); }); });
          return Object.assign({}, day, { places: arr });
        });
        return Object.assign({}, t, { days: nd });
      });
      result[sk] = Object.assign({}, sd, { products: np, cities: nc, templates: nt });
    }
    return result;
  },
  resolveTemplates(corrections) {
    if (!corrections || !Object.keys(corrections).length) return TEMPLATES;
    const self = this;
    return TEMPLATES.map(t => {
      const nd = (t.days || []).map(day => {
        const arr = [];
        (day.places || []).forEach(p => {
          self.rewriteName(day.state, p, corrections).forEach(r => { if (!arr.includes(r)) arr.push(r); });
        });
        return Object.assign({}, day, { places: arr });
      });
      return Object.assign({}, t, { days: nd });
    });
  },
  allTokens() {
    const T = window.TOKENS;
    if (!T) return [];
    return T.filter(t => !t.resolved).flatMap(t => {
      const states = t.state ? t.state.split(',').map(s => s.trim()).filter(Boolean) : [''];
      return states.map(s => Object.assign({}, t, { state: s }));
    });
  },
  tokenKey(state, name) { return (state || '') + ':' + name; },
  recommendedCorrection(token) {
    const i = token.issue;
    if (i === 'nonplace' || i === 'junk') return { action: 'remove' };
    if (i === 'split' && token.split) return { action: 'split', parts: token.split };
    if (i === 'dupe' && token.suggest) return { action: 'merge', target: token.suggest };
    if (i && token.suggest) return { action: 'rename', target: token.suggest };
    if (!i) return { action: 'approve' };
    return { action: 'flag' };
  },
  pendingIssueCount(corrections) {
    const T = window.TOKENS;
    if (!T) return 0;
    return T.filter(t => !t.resolved && t.issue && t.issue !== 'junk').filter(t => {
      const states = t.state ? t.state.split(',').map(s => s.trim()).filter(Boolean) : [''];
      return states.some(s => { const c = corrections[s + ':' + t.name]; return !c || c.action === 'flag'; });
    }).length;
  }
};
