const TX_VERSION = 'v0.1.0'

export default {
  mount(el, context) {
    const { userId, apiBaseUrl, pluginBaseUrl, config } = context
    const BASE = `${apiBaseUrl}/api/v1/user/${userId}/plugins/templatex`
    const ASSET_BASE = pluginBaseUrl

    // =========================================================================
    // State
    // =========================================================================

    let t = {}
    let _loadedLang = ''
    let _langPollTimer = null
    let _pendingTemplateFile = null

    let state = {
      view: 'dashboard',
      loading: true,
      status: null,
      error: null,
      templates: [],
      selectedTemplate: null,
      forms: [],
      selectedForm: null,
      showNewForm: false,
      editingForm: null,
      entries: [],
      selectedEntry: null,
      showNewEntry: false,
      newEntryFormId: null,
      newEntryFormDef: null,
      entryVariables: null,
      entryVariablesLoading: false,
      extracting: false,
      generating: false,
      editingVarKey: null,
      selectedGenerateTemplate: null,
    }

    // =========================================================================
    // i18n — reads UI language from localStorage, NOT from plugin config
    // =========================================================================

    function T(key, fallback) {
      const parts = key.split('.')
      let val = t
      for (const p of parts) {
        val = val?.[p]
        if (val === undefined) return fallback || key
      }
      return val
    }

    async function loadTranslations(force) {
      const lang = localStorage.getItem('language') || 'en'
      if (!force && lang === _loadedLang && Object.keys(t).length > 0) return false
      try {
        const res = await fetch(`${ASSET_BASE}/i18n/${lang}.json`)
        if (res.ok) { t = await res.json(); _loadedLang = lang; return true }
      } catch (_) { /* fallback below */ }
      if (lang !== 'en') {
        try {
          const res = await fetch(`${ASSET_BASE}/i18n/en.json`)
          if (res.ok) { t = await res.json(); _loadedLang = 'en'; return true }
        } catch (_) { /* no translations available */ }
      }
      return false
    }

    function startLanguageWatcher() {
      if (_langPollTimer) return
      _langPollTimer = setInterval(async () => {
        const current = localStorage.getItem('language') || 'en'
        if (current !== _loadedLang) {
          const changed = await loadTranslations()
          if (changed && !state.loading) render()
        }
      }, 500)
    }

    function stopLanguageWatcher() {
      if (_langPollTimer) { clearInterval(_langPollTimer); _langPollTimer = null }
    }

    // =========================================================================
    // API helpers
    // =========================================================================

    async function api(path, opts = {}) {
      const res = await fetch(`${BASE}${path}`, {
        headers: { 'Content-Type': 'application/json', ...opts.headers },
        ...opts,
      })
      if (!res.ok) {
        const body = await res.json().catch(() => ({}))
        throw new Error(body.error || `HTTP ${res.status}`)
      }
      return res.json()
    }

    async function apiUpload(path, file, extraFields = {}) {
      const fd = new FormData()
      fd.append('file', file)
      for (const [k, v] of Object.entries(extraFields)) fd.append(k, v)
      const res = await fetch(`${BASE}${path}`, { method: 'POST', body: fd })
      if (!res.ok) {
        const body = await res.json().catch(() => ({}))
        throw new Error(body.error || `HTTP ${res.status}`)
      }
      return res.json()
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    function escHtml(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
    }

    function formatDate(d) {
      if (!d) return '—'
      try {
        return new Date(d).toLocaleDateString(undefined, {
          year: 'numeric', month: 'short', day: 'numeric',
        })
      } catch (_) { return d }
    }

    function statusBadge(status) {
      const colors = {
        draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        extracted: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        reviewed: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
        generated: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        incomplete: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
      }
      const label = T(`entries.status_${status}`, status)
      const cls = colors[status] || colors.draft
      return `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}">${escHtml(label)}</span>`
    }

    function entryDisplayName(entry) {
      if (entry.name) return entry.name
      const d = entry.field_values || {}
      return d.name || d.fullname || d.full_name
        || (d.first_name ? `${d.first_name} ${d.last_name || ''}`.trim() : null)
        || `#${entry.id}`
    }

    function formNameById(formId) {
      const f = state.forms.find(f => f.id == formId)
      return f ? f.name : `#${formId}`
    }

    function entryHasDoc(entry) {
      return entry.files?.cv
        || (entry.files?.additional && entry.files.additional.length > 0)
    }

    function showToast(msg, type = 'success') {
      const id = 'tx-toast-' + Date.now()
      const bg = type === 'error' ? '#fef2f2' : '#f0fdf4'
      const color = type === 'error' ? '#991b1b' : '#166534'
      const border = type === 'error' ? '#fecaca' : '#bbf7d0'
      const div = document.createElement('div')
      div.id = id
      div.style.cssText = `position:fixed;top:16px;right:16px;z-index:9999;background:${bg};color:${color};border:1px solid ${border};padding:10px 18px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:opacity .3s;`
      div.textContent = msg
      document.body.appendChild(div)
      setTimeout(() => { div.style.opacity = '0'; setTimeout(() => div.remove(), 300) }, 2700)
    }

    // =========================================================================
    // Router
    // =========================================================================

    const NAV_KEYS = ['dashboard', 'entries', 'templates', 'forms', 'settings']
    const NAV_ICONS = {
      dashboard: 'grid', entries: 'users', templates: 'file',
      forms: 'clipboard', settings: 'gear',
    }

    async function navigate(view) {
      state.selectedTemplate = null
      state.selectedForm = null
      state.selectedEntry = null
      state.showNewEntry = false
      state.showNewForm = false
      state.editingForm = null
      state.newEntryFormId = null
      state.newEntryFormDef = null
      state.entryVariables = null
      state.entryVariablesLoading = false
      state.extracting = false
      state.generating = false
      state.editingVarKey = null
      state.selectedGenerateTemplate = null
      _pendingTemplateFile = null
      state.view = view
      window.location.hash = `#tx-${view}`
      await loadTranslations()
      render()
      loadViewData(view)
    }

    function resolveRoute() {
      const h = window.location.hash.replace('#tx-', '')
      if (NAV_KEYS.includes(h)) state.view = h
    }

    window.addEventListener('hashchange', () => {
      const prev = state.view
      resolveRoute()
      if (state.view !== prev) navigate(state.view)
    })

    // =========================================================================
    // Icons (Heroicons outline 24×24, sized via Tailwind)
    // =========================================================================

    const ICONS = {
      grid:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>',
      users:     '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
      file:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
      clipboard: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
      gear:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
      plus:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>',
      upload:    '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>',
      trash:     '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
      download:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>',
      check:     '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
      back:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>',
      sparkle:   '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/></svg>',
      doc:       '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
      edit:      '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>',
      chevDown:  '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>',
      chevRight: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>',
    }

    // =========================================================================
    // Render engine
    // =========================================================================

    const TX_STYLES = `
      .tx { color: var(--txt-primary); }
      .tx-secondary { color: var(--txt-secondary); }
      .tx-card { background: var(--bg-card); border-radius: .75rem; box-shadow: inset 0 0 0 1px var(--border-light), 0 1px 2px rgba(0,0,0,.04); }
      .tx-row { background: var(--bg-card); box-shadow: inset 0 0 0 1px var(--border-light); border-radius: .5rem; }
      .tx-row:hover { box-shadow: inset 0 0 0 1px var(--brand-alpha-light), 0 2px 6px rgba(0,0,0,.06); }
      .tx-input { background: var(--bg-app); color: var(--txt-primary); border: 1px solid var(--divider); border-radius: .375rem; padding: .5rem .75rem; font-size: .875rem; outline: none; width: 100%; }
      .tx-input:focus { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-alpha-light); }
      .tx-input::placeholder { color: var(--txt-secondary); opacity: .6; }
      .tx-select { background: var(--bg-app); color: var(--txt-primary); border: 1px solid var(--divider); border-radius: .375rem; padding: .5rem .75rem; font-size: .875rem; outline: none; width: 100%; }
      .tx-select:focus { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-alpha-light); }
      .tx-btn { background: var(--brand); color: #fff; border: none; border-radius: .375rem; padding: .5rem 1rem; font-size: .875rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: .375rem; transition: background .15s; }
      .tx-btn:hover { background: var(--brand-hover); }
      .tx-btn:disabled { opacity: .5; cursor: not-allowed; }
      .tx-btn-sm { padding: .375rem .75rem; }
      .tx-btn-danger { background: var(--status-error); }
      .tx-btn-danger:hover { opacity: .85; }
      .tx-btn-ghost { background: transparent; color: var(--brand); }
      .tx-btn-ghost:hover { background: var(--brand-alpha-light); }
      .tx-link { color: var(--brand); cursor: pointer; }
      .tx-link:hover { text-decoration: underline; }
      .tx-label { display: block; font-size: .875rem; font-weight: 500; color: var(--txt-primary); margin-bottom: .25rem; }
      .tx-badge { display: inline-flex; align-items: center; padding: .125rem .5rem; border-radius: .25rem; font-size: .75rem; font-weight: 500; }
      .tx-divider { border-color: var(--divider); }
      .tx-drop { border: 2px dashed var(--divider); border-radius: .5rem; background: var(--bg-card); text-align: center; padding: 1rem; cursor: pointer; transition: border-color .15s; }
      .tx-drop:hover { border-color: var(--brand); }
    `

    function render() {
      el.innerHTML = `
        <style>${TX_STYLES}</style>
        <div class="tx max-w-6xl mx-auto px-4 py-6">
          ${renderHeader()}
          ${renderNav()}
          <div id="tx-content" class="mt-4">${renderView()}</div>
        </div>`
      bindEvents()
    }

    function renderHeader() {
      return `<div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-bold tracking-tight">${T('app.title')}</h1>
        <span class="text-xs text-gray-400 font-mono">${TX_VERSION}</span>
      </div>`
    }

    function renderNav() {
      return `<nav class="flex gap-1 overflow-x-auto" style="border-bottom:1px solid var(--divider)">${
        NAV_KEYS.map(key => {
          const active = state.view === key
          const style = active
            ? 'border-bottom:2px solid var(--brand);color:var(--brand)'
            : 'border-bottom:2px solid transparent;color:var(--txt-secondary)'
          return `<button data-nav="${key}" class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors" style="${style}">${ICONS[NAV_ICONS[key]]} ${T('nav.' + key)}</button>`
        }).join('')
      }</nav>`
    }

    function renderView() {
      if (state.loading) return renderLoading()
      if (state.error) return renderError(state.error)
      switch (state.view) {
        case 'dashboard':  return renderDashboard()
        case 'entries':    return renderEntries()
        case 'templates':  return renderTemplates()
        case 'forms':      return renderForms()
        case 'settings':   return renderSettings()
        default:           return renderDashboard()
      }
    }

    function renderLoading() {
      return `<div class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
        <span class="ml-3 text-gray-500 dark:text-gray-400">${T('app.loading')}</span>
      </div>`
    }

    function renderError(msg) {
      return `<div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 mt-4">
        <p class="text-red-600 dark:text-red-400">${T('app.error')}: ${escHtml(msg)}</p>
      </div>`
    }

    function emptyState(icon, title, message, hint) {
      return `<div class="rounded-lg border dark:border-gray-700 p-8 bg-white dark:bg-gray-800 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 mb-3 text-gray-400">${icon}</div>
        <h3 class="font-medium text-lg mb-1">${title}</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">${message}</p>
        ${hint ? `<p class="text-xs text-gray-400 dark:text-gray-500 mt-1">${hint}</p>` : ''}
      </div>`
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    function renderDashboard() {
      const s = state.status
      if (!s) return renderLoading()
      const counts = s.counts || {}
      const cfg = s.config || {}
      return `<div class="space-y-6 mt-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          ${statCard(T('dashboard.forms'), counts.forms ?? 0, 'forms')}
          ${statCard(T('dashboard.templates'), counts.templates ?? 0, 'templates')}
          ${statCard(T('dashboard.entries'), counts.candidates ?? 0, 'entries')}
        </div>
        <div class="tx-card p-4">
          <h3 class="font-medium mb-2">${T('dashboard.quickstart')}</h3>
          <ol class="list-decimal list-inside text-sm tx-secondary space-y-1">
            <li>${T('dashboard.step1')} <button data-nav="forms" class="tx-link">${T('nav.forms')}</button></li>
            <li>${T('dashboard.step2')} <button data-nav="templates" class="tx-link">${T('nav.templates')}</button></li>
            <li>${T('dashboard.step3')} <button data-nav="entries" class="tx-link">${T('nav.entries')}</button></li>
            <li>${T('dashboard.step4')}</li>
          </ol>
        </div>
        <div class="flex items-center gap-2">
          <span class="inline-block w-2 h-2 rounded-full" style="background:var(--status-success)"></span>
          <span class="text-xs tx-secondary">${T('dashboard.plugin_active')} · ${T('dashboard.language_label')}: ${escHtml(cfg.default_language || '—')} · ${T('dashboard.company_label')}: ${escHtml(cfg.company_name || T('dashboard.not_set'))}</span>
        </div>
      </div>`
    }

    function statCard(label, count, navTarget) {
      return `<button data-nav="${navTarget}" class="tx-row p-4 text-center cursor-pointer w-full">
        <div class="text-3xl font-bold">${count}</div>
        <div class="text-sm tx-secondary mt-1">${label}</div>
      </button>`
    }

    // =========================================================================
    // Templates view
    // =========================================================================

    function renderTemplates() {
      if (state.selectedTemplate) return renderTemplateDetail()

      const rows = state.templates.length > 0
        ? state.templates.map(tpl => `
          <div data-select-template="${tpl.id}" class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-4 hover:shadow-sm transition-shadow cursor-pointer">
            <div class="flex items-center justify-between">
              <div>
                <div class="font-medium">${escHtml(tpl.name)}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${formatDate(tpl.created_at)}${tpl.placeholder_count != null ? ` · ${tpl.placeholder_count} ${T('templates.placeholders')}` : ''}</div>
              </div>
              <div class="flex items-center gap-1">
                <button data-download-template="${tpl.id}" class="p-1.5 text-gray-400 hover:text-blue-500 rounded transition-colors" title="${T('app.download')}">${ICONS.download}</button>
                <button data-delete-template="${tpl.id}" class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors" title="${T('app.delete')}">${ICONS.trash}</button>
              </div>
            </div>
          </div>`).join('')
        : emptyState(ICONS.file, T('templates.title'), T('templates.empty'), T('templates.empty_hint'))

      return `<div class="mt-4 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-medium">${T('templates.title')}</h3>
          <label class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-medium transition-colors cursor-pointer">
            ${ICONS.upload} ${T('templates.upload')}
            <input type="file" id="tx-template-file-btn" accept=".docx" class="hidden" />
          </label>
        </div>
        <div id="tx-template-drop" class="rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 p-4 bg-white dark:bg-gray-800 text-center cursor-pointer hover:border-blue-400 dark:hover:border-blue-500 transition-colors">
          <p class="text-sm text-gray-500 dark:text-gray-400">${T('templates.drop_hint')}</p>
          <input type="file" id="tx-template-file" accept=".docx" class="hidden" />
          <div id="tx-template-upload-form" class="mt-3 hidden">
            <div class="flex items-center gap-2 max-w-md mx-auto">
              <input type="text" id="tx-template-name" placeholder="${T('templates.name_label')}" class="flex-1 rounded border dark:border-gray-600 dark:bg-gray-700 px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" />
              <button id="tx-upload-template-btn" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-medium transition-colors flex items-center gap-1">${ICONS.upload} ${T('templates.upload')}</button>
            </div>
          </div>
        </div>
        <div class="space-y-2">${rows}</div>
      </div>`
    }

    function renderTemplateDetail() {
      const tpl = state.selectedTemplate
      const phs = tpl.placeholders || []
      const phRows = phs.map(p => `
        <tr class="border-t dark:border-gray-700">
          <td class="py-2 px-3 font-mono text-sm">${escHtml(p.key || p.name || '')}</td>
          <td class="py-2 px-3 text-sm">${escHtml(p.type || 'text')}</td>
          <td class="py-2 px-3 text-sm text-center">${p.occurrences ?? p.count ?? '—'}</td>
        </tr>`).join('')

      return `<div class="mt-4 space-y-4">
        <button data-action="back-templates" class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">${ICONS.back} ${T('app.back')}</button>
        <div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-lg font-medium">${escHtml(tpl.name)}</h3>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${T('app.created')}: ${formatDate(tpl.created_at)}</div>
            </div>
            <div class="flex items-center gap-2">
              <button data-download-template="${tpl.id}" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 transition-colors">${ICONS.download} ${T('app.download')}</button>
              <button data-delete-template="${tpl.id}" class="flex items-center gap-1 text-sm text-red-600 hover:text-red-700 dark:text-red-400 transition-colors">${ICONS.trash} ${T('app.delete')}</button>
            </div>
          </div>
          ${phs.length > 0 ? `
            <h4 class="text-sm font-medium mb-2">${T('templates.placeholders')} (${phs.length})</h4>
            <div class="overflow-x-auto">
              <table class="w-full text-left">
                <thead>
                  <tr class="border-b dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <th class="py-2 px-3">${T('forms.field_key')}</th>
                    <th class="py-2 px-3">${T('app.type')}</th>
                    <th class="py-2 px-3 text-center">${T('templates.occurrences')}</th>
                  </tr>
                </thead>
                <tbody>${phRows}</tbody>
              </table>
            </div>
          ` : `<p class="text-sm text-gray-400 dark:text-gray-500">${T('templates.placeholder_count')}: 0</p>`}
        </div>
      </div>`
    }

    // =========================================================================
    // Forms view
    // =========================================================================

    function renderForms() {
      if (state.selectedForm) return renderFormDetail()
      if (state.showNewForm || state.editingForm) return renderFormEditor()

      const rows = state.forms.length > 0
        ? state.forms.map(f => {
          const count = f.fields?.length ?? f.field_count ?? 0
          const isDefault = f.is_default || f.id === 'default'
          return `
            <div data-select-form="${f.id}" class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-4 hover:shadow-sm transition-shadow cursor-pointer">
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium">${escHtml(f.name)}${isDefault ? ` <span class="text-xs text-gray-400 dark:text-gray-500">(${T('forms.default_form')})</span>` : ''}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${count} ${T('forms.field_count')} · ${T('app.language')}: ${escHtml(f.language || '—')}</div>
                </div>
                <div class="flex items-center gap-1">
                  ${!isDefault ? `<button data-delete-form="${f.id}" class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors" title="${T('app.delete')}">${ICONS.trash}</button>` : ''}
                </div>
              </div>
            </div>`
        }).join('')
        : emptyState(ICONS.clipboard, T('forms.title'), T('forms.empty'))

      return `<div class="mt-4 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-medium">${T('forms.title')}</h3>
          <button data-action="new-form" class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-medium transition-colors">${ICONS.plus} ${T('forms.new')}</button>
        </div>
        <div class="space-y-2">${rows}</div>
      </div>`
    }

    function renderFormDetail() {
      const f = state.selectedForm
      const fields = f.fields || []
      const isDefault = f.is_default || f.id === 'default'
      const rows = fields.map(fd => `
        <tr class="border-t dark:border-gray-700">
          <td class="py-2 px-3 font-mono text-sm">${escHtml(fd.key)}</td>
          <td class="py-2 px-3 text-sm">${escHtml(fd.label || '')}</td>
          <td class="py-2 px-3 text-sm">${escHtml(fd.type || 'text')}</td>
          <td class="py-2 px-3 text-sm text-center">${fd.required ? `<span class="text-green-600 dark:text-green-400">${T('app.yes')}</span>` : `<span class="text-gray-400">${T('app.no')}</span>`}</td>
        </tr>`).join('')

      return `<div class="mt-4 space-y-4">
        <button data-action="back-forms" class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">${ICONS.back} ${T('app.back')}</button>
        <div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
          <div class="flex items-center justify-between mb-4">
            <div>
              <h3 class="text-lg font-medium mb-1">${escHtml(f.name)}${isDefault ? ` <span class="text-xs text-gray-400">(${T('forms.default_form')})</span>` : ''}</h3>
              <div class="text-xs text-gray-500 dark:text-gray-400">${T('app.language')}: ${escHtml(f.language || '—')}</div>
            </div>
            <div class="flex items-center gap-2">
              <button data-action="edit-form" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 transition-colors">${ICONS.edit} ${T('app.edit')}</button>
              ${!isDefault ? `<button data-delete-form="${f.id}" class="flex items-center gap-1 text-sm text-red-600 hover:text-red-700 dark:text-red-400 transition-colors">${ICONS.trash} ${T('app.delete')}</button>` : ''}
            </div>
          </div>
          ${fields.length > 0 ? `
            <h4 class="text-sm font-medium mb-2">${T('forms.fields')} (${fields.length})</h4>
            <div class="overflow-x-auto">
              <table class="w-full text-left">
                <thead>
                  <tr class="border-b dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <th class="py-2 px-3">${T('forms.field_key')}</th>
                    <th class="py-2 px-3">${T('forms.field_label')}</th>
                    <th class="py-2 px-3">${T('forms.field_type')}</th>
                    <th class="py-2 px-3 text-center">${T('forms.field_required')}</th>
                  </tr>
                </thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          ` : `<p class="text-sm text-gray-400 dark:text-gray-500">${T('forms.fields')}: 0</p>`}
        </div>
      </div>`
    }

    function renderFormEditor() {
      const editing = state.editingForm
      const isNew = !editing
      const name = editing?.name || ''
      const language = editing?.language || 'de'
      const fields = editing?.fields || []
      const fieldTypeOptions = ['text', 'textarea', 'select', 'list', 'date', 'number', 'checkbox'].map(t =>
        `<option value="${t}">${t}</option>`
      ).join('')

      const fieldRows = fields.map((fd, idx) => `
        <div class="tx-row flex items-start gap-2 p-3" data-field-idx="${idx}">
          <div class="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <input name="fk_${idx}" value="${escHtml(fd.key || '')}" placeholder="${T('forms.field_key')}" class="tx-input" style="padding:.375rem .5rem" />
            <input name="fl_${idx}" value="${escHtml(fd.label || '')}" placeholder="${T('forms.field_label')}" class="tx-input" style="padding:.375rem .5rem" />
            <select name="ft_${idx}" class="tx-select" style="padding:.375rem .5rem">
              ${['text', 'textarea', 'select', 'list', 'date', 'number', 'checkbox'].map(tp =>
                `<option value="${tp}"${fd.type === tp ? ' selected' : ''}>${tp}</option>`
              ).join('')}
            </select>
            <label class="flex items-center gap-1.5 text-sm" style="color:var(--txt-primary)">
              <input type="checkbox" name="fr_${idx}" ${fd.required ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T('forms.field_required')}</span>
            </label>
          </div>
          <button data-action="remove-form-field" data-idx="${idx}" class="p-1 transition-colors mt-1" style="color:var(--txt-secondary)" title="${T('forms.remove_field')}">${ICONS.trash}</button>
        </div>`).join('')

      return `<div class="mt-4 space-y-4">
        <button data-action="back-forms" class="tx-btn-ghost tx-btn-sm flex items-center gap-1 text-sm" style="color:var(--txt-secondary)">${ICONS.back} ${T('app.back')}</button>
        <div class="tx-card p-6">
          <h3 class="text-lg font-medium mb-4">${isNew ? T('forms.new') : T('app.edit')}</h3>
          <form id="tx-form-editor" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
              <div>
                <label class="tx-label">${T('app.name')}</label>
                <input name="form_name" value="${escHtml(name)}" class="tx-input" required />
              </div>
              <div>
                <label class="tx-label">${T('app.language')}</label>
                <select name="form_language" class="tx-select">
                  <option value="de" ${language === 'de' ? 'selected' : ''}>${T('settings.lang_de')}</option>
                  <option value="en" ${language === 'en' ? 'selected' : ''}>${T('settings.lang_en')}</option>
                </select>
              </div>
            </div>
            <div>
              <div class="flex items-center justify-between mb-2">
                <h4 class="text-sm font-medium">${T('forms.fields')}</h4>
                <button type="button" data-action="add-form-field" class="tx-link flex items-center gap-1 text-sm">${ICONS.plus} ${T('forms.add_field')}</button>
              </div>
              <div id="tx-form-fields" class="space-y-2">${fieldRows}</div>
            </div>
            <button type="submit" class="tx-btn">${T('app.save')}</button>
          </form>
        </div>
      </div>`
    }

    // =========================================================================
    // Entries view
    // =========================================================================

    function renderEntries() {
      if (state.selectedEntry) return renderEntryDetail()
      if (state.showNewEntry) return renderNewEntry()

      const rows = state.entries.length > 0
        ? state.entries.map(e => `
          <div data-select-entry="${e.id}" class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-4 hover:shadow-sm transition-shadow cursor-pointer">
            <div class="flex items-center justify-between">
              <div>
                <div class="font-medium">${escHtml(entryDisplayName(e))}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${T('entries.form')}: ${escHtml(formNameById(e.form_id))} · ${formatDate(e.created_at)}</div>
              </div>
              <div class="flex items-center gap-3">
                ${statusBadge(e.status || 'draft')}
                <span class="text-xs ${entryHasDoc(e) ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500'}">${entryHasDoc(e) ? T('entries.doc_uploaded') : T('entries.doc_missing')}</span>
              </div>
            </div>
          </div>`).join('')
        : emptyState(ICONS.users, T('entries.title'), T('entries.empty'), T('entries.empty_hint'))

      return `<div class="mt-4 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-medium">${T('entries.title')}</h3>
          <button data-action="new-entry" class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-medium transition-colors">${ICONS.plus} ${T('entries.new')}</button>
        </div>
        <div class="space-y-2">${rows}</div>
      </div>`
    }

    function renderNewEntry() {
      const formOptions = state.forms.map(f =>
        `<option value="${f.id}"${state.newEntryFormId == f.id ? ' selected' : ''}>${escHtml(f.name)}</option>`
      ).join('')

      let fieldsHtml = ''
      if (state.newEntryFormDef?.fields) {
        fieldsHtml = state.newEntryFormDef.fields.map(renderFormField).join('')
      }

      return `<div class="mt-4 space-y-4">
        <button data-action="back-entries" class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">${ICONS.back} ${T('app.back')}</button>
        <div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
          <h3 class="text-lg font-medium mb-4">${T('entries.new')}</h3>
          <form id="tx-new-entry-form" class="space-y-4 max-w-lg">
            <div>
              <label class="block text-sm font-medium mb-1">${T('entries.form')}</label>
              <select id="tx-entry-form-select" class="w-full rounded border dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value="">— ${T('entries.form')} —</option>
                ${formOptions}
              </select>
            </div>
            <div id="tx-entry-fields" class="space-y-3">${fieldsHtml}</div>
            ${state.newEntryFormDef ? `<button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium transition-colors">${T('app.save')}</button>` : ''}
          </form>
        </div>
      </div>`
    }

    function renderFormField(field) {
      const fid = `tx-field-${escHtml(field.key)}`
      const inputCls = 'w-full rounded border dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none'
      const req = field.required ? 'required' : ''
      const reqMark = field.required ? ' <span class="text-red-500">*</span>' : ''
      const hint = field.hint ? `<p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">${escHtml(field.hint)}</p>` : ''

      if (field.type === 'checkbox') {
        return `<div>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="${fid}" name="${escHtml(field.key)}" class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500 h-4 w-4" />
            <span class="text-sm font-medium">${escHtml(field.label || field.key)}${reqMark}</span>
          </label>${hint}
        </div>`
      }

      const label = `<label for="${fid}" class="block text-sm font-medium mb-1">${escHtml(field.label || field.key)}${reqMark}</label>`
      let input

      switch (field.type) {
        case 'textarea':
          input = `<textarea id="${fid}" name="${escHtml(field.key)}" class="${inputCls}" rows="3" ${req}></textarea>`
          break
        case 'select': {
          const opts = (field.options || []).map(o => {
            const val = typeof o === 'object' ? o.value : o
            const lbl = typeof o === 'object' ? (o.label || o.value) : o
            return `<option value="${escHtml(val)}">${escHtml(lbl)}</option>`
          }).join('')
          input = `<select id="${fid}" name="${escHtml(field.key)}" class="${inputCls}" ${req}><option value="">—</option>${opts}</select>`
          break
        }
        case 'list':
          input = `<textarea id="${fid}" name="${escHtml(field.key)}" class="${inputCls}" rows="3" placeholder="${T('forms.list_placeholder')}" ${req}></textarea>`
          break
        case 'date':
          input = `<input type="date" id="${fid}" name="${escHtml(field.key)}" class="${inputCls}" ${req} />`
          break
        case 'number':
          input = `<input type="number" id="${fid}" name="${escHtml(field.key)}" class="${inputCls}" ${req} />`
          break
        default:
          input = `<input type="text" id="${fid}" name="${escHtml(field.key)}" class="${inputCls}" ${req} />`
      }

      return `<div>${label}${input}${hint}</div>`
    }

    function renderEntryDetail() {
      const e = state.selectedEntry
      return `<div class="mt-4 space-y-4">
        <button data-action="back-entries" class="flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">${ICONS.back} ${T('app.back')}</button>
        <div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-medium">${escHtml(entryDisplayName(e))}</h3>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${T('entries.form')}: ${escHtml(formNameById(e.form_id))} · ${T('app.created')}: ${formatDate(e.created_at)}</div>
            </div>
            <div class="flex items-center gap-3">
              ${statusBadge(e.status || 'draft')}
              <button data-delete-entry="${e.id}" class="flex items-center gap-1 text-sm text-red-600 hover:text-red-700 dark:text-red-400 transition-colors">${ICONS.trash} ${T('app.delete')}</button>
            </div>
          </div>
        </div>
        ${renderEntryDataSection(e)}
        ${renderEntryFilesSection(e)}
        ${renderEntryExtractionSection(e)}
        ${renderEntryVariablesSection()}
        ${renderEntryGenerateSection(e)}
      </div>`
    }

    // --- Section: Form Data ---

    function renderEntryDataSection(entry) {
      const formData = entry.field_values || {}
      const keys = Object.keys(formData)
      const dataRows = keys.map(key => {
        let val = formData[key]
        if (Array.isArray(val)) val = val.join(', ')
        else if (typeof val === 'boolean') val = val ? T('app.yes') : T('app.no')
        else val = String(val ?? '')
        return `<div class="flex justify-between py-2 border-b dark:border-gray-700 last:border-0">
          <span class="text-sm text-gray-500 dark:text-gray-400">${escHtml(key)}</span>
          <span class="text-sm font-medium text-right max-w-[60%]">${escHtml(val)}</span>
        </div>`
      }).join('')
      return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.clipboard} ${T('entries.section_data')}</h4>
        ${keys.length > 0
          ? `<div class="rounded border dark:border-gray-700 divide-y dark:divide-gray-700 px-3">${dataRows}</div>`
          : `<p class="text-sm text-gray-400 dark:text-gray-500">${T('app.no_data')}</p>`}
      </div>`
    }

    // --- Section: Files ---

    function renderEntryFilesSection(entry) {
      const hasCv = !!entry.files?.cv
      const additionalDocs = entry.files?.additional || []
      const cvInfo = hasCv ? entry.files.cv : null
      return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.file} ${T('entries.section_files')}</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="rounded border dark:border-gray-700 p-3">
            <div class="text-xs font-medium mb-2">${T('entries.upload_doc')}</div>
            ${hasCv ? `<p class="text-xs text-green-600 dark:text-green-400 mb-2">${ICONS.check} ${escHtml(cvInfo.filename || 'cv.pdf')}</p>` : ''}
            <label class="flex items-center justify-center gap-1.5 cursor-pointer text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 border border-dashed border-gray-300 dark:border-gray-600 rounded p-2 transition-colors hover:border-blue-400">
              ${ICONS.upload} ${hasCv ? T('entries.re_upload') || T('app.upload') : T('app.upload')}
              <input type="file" id="tx-cv-upload" accept=".pdf" class="hidden" />
            </label>
          </div>
          <div class="rounded border dark:border-gray-700 p-3">
            <div class="text-xs font-medium mb-2">${T('entries.upload_extra')}</div>
            ${additionalDocs.length > 0 ? additionalDocs.map(d => `<p class="text-xs text-green-600 dark:text-green-400 mb-1">${ICONS.check} ${escHtml(d.filename)}</p>`).join('') : ''}
            <label class="flex items-center justify-center gap-1.5 cursor-pointer text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 border border-dashed border-gray-300 dark:border-gray-600 rounded p-2 transition-colors hover:border-blue-400">
              ${ICONS.upload} ${T('app.upload')}
              <input type="file" id="tx-doc-upload" class="hidden" />
            </label>
          </div>
        </div>
      </div>`
    }

    // --- Section: AI Extraction ---

    function renderEntryExtractionSection(entry) {
      const hasDoc = entryHasDoc(entry)
      const isExtracted = entry.status === 'extracted' || entry.status === 'reviewed' || entry.status === 'generated'
      const extractInfo = entry.ai_extracted || {}
      let statusLine = ''
      if (state.extracting) {
        statusLine = `<div class="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400">
          <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-500"></div>
          ${T('entries.extract_running')}
        </div>`
      } else if (isExtracted) {
        statusLine = `<div class="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
          ${ICONS.check} ${T('entries.extract_done')}
          ${extractInfo.model_used ? ` · ${T('entries.extract_model')}: <span class="font-mono text-xs">${escHtml(extractInfo.model_used)}</span>` : ''}
        </div>`
      } else {
        statusLine = `<div class="text-sm text-gray-400 dark:text-gray-500">${hasDoc ? '' : T('entries.extract_no_cv')}</div>`
      }
      return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.sparkle} ${T('entries.section_extraction')}</h4>
        <div class="flex items-center justify-between">
          ${statusLine}
          <button data-action="extract" data-entry-id="${entry.id}" class="flex items-center gap-1.5 px-3 py-1.5 rounded text-sm font-medium transition-colors ${hasDoc && !state.extracting ? 'bg-purple-600 hover:bg-purple-700 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 cursor-not-allowed'}" ${!hasDoc || state.extracting ? 'disabled' : ''}>
            ${ICONS.sparkle} ${state.extracting ? T('entries.extract_running') : (isExtracted ? T('entries.re_extract') : T('entries.extract_btn'))}
          </button>
        </div>
      </div>`
    }

    // --- Section: Resolved Variables ---

    function renderEntryVariablesSection() {
      const e = state.selectedEntry
      const isExtracted = e.status === 'extracted' || e.status === 'reviewed' || e.status === 'generated'
      if (!isExtracted && !state.entryVariables) return ''
      if (state.entryVariablesLoading) {
        return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
          <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.clipboard} ${T('entries.section_variables')}</h4>
          <div class="flex items-center gap-2 py-4">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-500"></div>
            <span class="text-sm text-gray-500">${T('app.loading')}</span>
          </div>
        </div>`
      }
      const vars = state.entryVariables
      if (!vars || vars.length === 0) {
        return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
          <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.clipboard} ${T('entries.section_variables')}</h4>
          <p class="text-sm text-gray-400 dark:text-gray-500">${T('app.no_data')}</p>
        </div>`
      }
      const regularVars = vars.filter(v => v.type !== 'station')
      const stationVars = vars.filter(v => v.type === 'station')
      const rows = regularVars.map(v => renderVariableRow(v, e.id)).join('')
      const stationRows = stationVars.map(v => renderStationGroup(v)).join('')
      return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.clipboard} ${T('entries.section_variables')}</h4>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="border-b dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                <th class="py-2 px-3">${T('entries.var_key')}</th>
                <th class="py-2 px-3">${T('entries.var_value')}</th>
                <th class="py-2 px-3">${T('entries.var_source')}</th>
                <th class="py-2 px-3 text-right"></th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${stationRows}
      </div>`
    }

    function renderVariableRow(v, entryId) {
      const isEditing = state.editingVarKey === v.key
      const sourceLabel = v.source === 'ai' ? T('entries.source_ai')
        : v.source === 'override' ? T('entries.source_override')
        : T('entries.source_form')
      const sourceCls = v.source === 'ai' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
        : v.source === 'override' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
        : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
      let displayValue
      if (Array.isArray(v.value)) {
        displayValue = v.value.map(item => escHtml(String(item))).join('<br>')
      } else {
        displayValue = v.value != null && v.value !== '' ? escHtml(String(v.value)) : `<span class="text-gray-400 italic">${T('entries.var_not_set')}</span>`
      }
      if (isEditing) {
        const editVal = escHtml(Array.isArray(v.value) ? v.value.join('\n') : String(v.value ?? ''))
        return `<tr class="border-t dark:border-gray-700 bg-blue-50/50 dark:bg-blue-900/10">
          <td class="py-2 px-3 font-mono text-sm align-top">${escHtml(v.key)}</td>
          <td class="py-2 px-3" colspan="2">
            <textarea id="tx-override-input" class="w-full rounded border dark:border-gray-600 dark:bg-gray-700 px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" rows="2">${editVal}</textarea>
          </td>
          <td class="py-2 px-3 text-right align-top">
            <div class="flex items-center gap-1 justify-end">
              <button data-action="save-override" data-entry-id="${entryId}" data-var-key="${escHtml(v.key)}" class="text-green-600 hover:text-green-700 dark:text-green-400 p-1 rounded transition-colors" title="${T('app.save')}">${ICONS.check}</button>
              <button data-action="cancel-override" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1 rounded text-lg leading-none transition-colors" title="${T('app.cancel')}">&times;</button>
            </div>
          </td>
        </tr>`
      }
      return `<tr class="border-t dark:border-gray-700">
        <td class="py-2 px-3 font-mono text-sm">${escHtml(v.key)}</td>
        <td class="py-2 px-3 text-sm">${displayValue}</td>
        <td class="py-2 px-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${sourceCls}">${sourceLabel}</span></td>
        <td class="py-2 px-3 text-right">
          <button data-action="override-var" data-var-key="${escHtml(v.key)}" class="text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400 transition-colors flex items-center gap-1 ml-auto">${ICONS.edit} ${T('entries.override')}</button>
        </td>
      </tr>`
    }

    function renderStationGroup(v) {
      const stations = Array.isArray(v.value) ? v.value : []
      if (stations.length === 0) return ''
      const stationRows = stations.map((s, idx) => {
        const fields = Object.entries(s).map(([k, val]) =>
          `<div class="flex justify-between py-1">
            <span class="text-xs text-gray-600 dark:text-gray-400">${escHtml(k)}</span>
            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 text-right max-w-[65%]">${escHtml(String(val ?? ''))}</span>
          </div>`
        ).join('')
        return `<div class="rounded border border-gray-200 dark:border-gray-600 p-3 bg-white dark:bg-gray-700">
          <div class="text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">#${idx + 1}</div>
          ${fields}
        </div>`
      }).join('')
      const srcLabel = v.source === 'ai' ? T('entries.source_ai') : T('entries.source_form')
      return `<div class="mt-4">
        <button data-action="toggle-station" data-station-key="${escHtml(v.key)}" class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
          <span class="tx-station-chevron" data-key="${escHtml(v.key)}">${ICONS.chevDown}</span>
          ${escHtml(v.key)} (${stations.length})
          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">${srcLabel}</span>
        </button>
        <div class="tx-station-body grid gap-2" data-key="${escHtml(v.key)}">${stationRows}</div>
      </div>`
    }

    // --- Section: Generate Document ---

    function renderEntryGenerateSection(entry) {
      const hasVars = state.entryVariables && state.entryVariables.filter(v => v.type !== 'station').length > 0
      const hasTpls = state.templates && state.templates.length > 0
      if (!hasVars) return ''
      const tplOptions = (state.templates || []).map(tpl =>
        `<option value="${tpl.id}"${state.selectedGenerateTemplate == tpl.id ? ' selected' : ''}>${escHtml(tpl.name)}</option>`
      ).join('')
      const docsMap = entry.documents || {}
      const docs = Object.values(docsMap)
      const docRows = docs.map(doc => `
        <div class="flex items-center justify-between py-2 border-b dark:border-gray-700 last:border-0">
          <div>
            <span class="text-sm font-medium">${escHtml(doc.template_name || doc.name || T('entries.document'))}</span>
            <span class="text-xs text-gray-500 dark:text-gray-400 ml-2">${formatDate(doc.created_at || doc.generated_at)}</span>
          </div>
          <button data-action="download-doc" data-entry-id="${entry.id}" data-doc-id="${doc.id}" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 transition-colors">${ICONS.download} ${T('entries.download_doc')}</button>
        </div>`).join('')
      return `<div class="rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
        <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 flex items-center gap-2">${ICONS.doc} ${T('entries.section_generate')}</h4>
        ${hasTpls ? `
          <div class="flex items-center gap-3 mb-4">
            <select id="tx-generate-template" class="flex-1 rounded border dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
              <option value="">— ${T('entries.generate_select_tpl')} —</option>
              ${tplOptions}
            </select>
            <button data-action="generate" data-entry-id="${entry.id}" class="flex items-center gap-1.5 px-3 py-1.5 rounded text-sm font-medium transition-colors ${state.selectedGenerateTemplate && !state.generating ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 cursor-not-allowed'}" ${!state.selectedGenerateTemplate || state.generating ? 'disabled' : ''}>
              ${state.generating
                ? `<div class="animate-spin rounded-full h-3 w-3 border-b-2 border-white"></div> ${T('entries.generate_running')}`
                : `${ICONS.doc} ${T('entries.generate_btn')}`}
            </button>
          </div>
        ` : `<p class="text-sm text-gray-400 dark:text-gray-500 mb-4">${T('templates.empty')}</p>`}
        <div>
          <h5 class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">${T('entries.generated_docs')}</h5>
          ${docs.length > 0
            ? `<div class="rounded border dark:border-gray-700 divide-y dark:divide-gray-700 px-3">${docRows}</div>`
            : `<p class="text-sm text-gray-400 dark:text-gray-500">${T('entries.no_generated_docs')}</p>`}
        </div>
      </div>`
    }

    // =========================================================================
    // Settings view
    // =========================================================================

    function renderSettings() {
      const cfg = state.status?.config || {}
      const modelDisplay = cfg.extraction_model && cfg.extraction_model !== 'default' ? cfg.extraction_model : T('settings.model_auto')
      return `<div class="mt-4 space-y-4">
        <div class="rounded-lg border dark:border-gray-700 p-6 bg-white dark:bg-gray-800">
          <h3 class="text-lg font-medium mb-4">${T('settings.title')}</h3>
          <form id="tx-settings-form" class="space-y-4 max-w-md">
            ${settingsField('company_name', T('settings.company_name'), cfg.company_name || '')}
            <div>
              <label class="block text-sm font-medium mb-1">${T('settings.default_language')}</label>
              <select name="default_language" class="w-full rounded border dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                <option value="de" ${cfg.default_language === 'de' ? 'selected' : ''}>${T('settings.lang_de')}</option>
                <option value="en" ${cfg.default_language === 'en' ? 'selected' : ''}>${T('settings.lang_en')}</option>
              </select>
            </div>
            ${settingsField('extraction_model', T('settings.extraction_model'), cfg.extraction_model || 'default')}
            ${settingsField('validation_model', T('settings.validation_model'), cfg.validation_model || 'default')}
            <div class="flex items-center gap-3">
              <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium transition-colors">${T('app.save')}</button>
              <span id="tx-settings-msg" class="text-sm text-green-600 dark:text-green-400 hidden">${ICONS.check} ${T('app.saved')}</span>
            </div>
          </form>
        </div>
        <div class="rounded-lg border dark:border-gray-700 p-6 bg-white dark:bg-gray-800">
          <h4 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">${T('settings.info_title')}</h4>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between py-1.5 border-b dark:border-gray-700">
              <span class="text-gray-500 dark:text-gray-400">${T('settings.active_model')}</span>
              <span class="font-mono font-medium text-gray-900 dark:text-gray-100">${escHtml(modelDisplay)}</span>
            </div>
            <div class="flex justify-between py-1.5 border-b dark:border-gray-700">
              <span class="text-gray-500 dark:text-gray-400">${T('settings.default_language')}</span>
              <span class="font-medium text-gray-900 dark:text-gray-100">${cfg.default_language === 'de' ? T('settings.lang_de') : T('settings.lang_en')}</span>
            </div>
            <div class="flex justify-between py-1.5 border-b dark:border-gray-700">
              <span class="text-gray-500 dark:text-gray-400">${T('settings.company_name')}</span>
              <span class="font-medium text-gray-900 dark:text-gray-100">${escHtml(cfg.company_name || T('dashboard.not_set'))}</span>
            </div>
            <div class="flex justify-between py-1.5">
              <span class="text-gray-500 dark:text-gray-400">${T('settings.ui_language')}</span>
              <span class="font-medium text-gray-900 dark:text-gray-100">${_loadedLang === 'de' ? T('settings.lang_de') : _loadedLang === 'en' ? T('settings.lang_en') : _loadedLang}</span>
            </div>
          </div>
        </div>
      </div>`
    }

    function settingsField(name, label, value) {
      return `<div>
        <label class="block text-sm font-medium mb-1">${label}</label>
        <input name="${name}" value="${escHtml(value)}" type="text" class="w-full rounded border dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" />
      </div>`
    }

    // =========================================================================
    // Event binding
    // =========================================================================

    function bindEvents() {
      // --- Navigation tabs ---
      el.querySelectorAll('[data-nav]').forEach(btn =>
        btn.addEventListener('click', e => { e.preventDefault(); navigate(btn.dataset.nav) })
      )

      // --- Template: select ---
      el.querySelectorAll('[data-select-template]').forEach(row =>
        row.addEventListener('click', () => handleSelectTemplate(row.dataset.selectTemplate))
      )

      // --- Template: download ---
      el.querySelectorAll('[data-download-template]').forEach(btn =>
        btn.addEventListener('click', e => {
          e.stopPropagation()
          const a = document.createElement('a')
          a.href = `${BASE}/templates/${btn.dataset.downloadTemplate}/download`
          a.download = ''
          document.body.appendChild(a)
          a.click()
          a.remove()
        })
      )

      // --- Template: delete ---
      el.querySelectorAll('[data-delete-template]').forEach(btn =>
        btn.addEventListener('click', async e => {
          e.stopPropagation()
          if (!confirm(T('templates.confirm_delete'))) return
          try {
            await api(`/templates/${btn.dataset.deleteTemplate}`, { method: 'DELETE' })
            state.selectedTemplate = null
            showToast(T('app.saved'))
            await fetchTemplates()
            render()
          } catch (err) { showToast(err.message, 'error') }
        })
      )

      // --- Template: drag-and-drop upload ---
      const dropZone = el.querySelector('#tx-template-drop')
      const tplFileInput = el.querySelector('#tx-template-file')

      if (dropZone && tplFileInput) {
        dropZone.addEventListener('click', e => {
          if (e.target.closest('#tx-template-upload-form')) return
          tplFileInput.click()
        })
        dropZone.addEventListener('dragover', e => {
          e.preventDefault()
          dropZone.classList.add('border-blue-400', 'dark:border-blue-500')
        })
        dropZone.addEventListener('dragleave', () =>
          dropZone.classList.remove('border-blue-400', 'dark:border-blue-500')
        )
        dropZone.addEventListener('drop', e => {
          e.preventDefault()
          dropZone.classList.remove('border-blue-400', 'dark:border-blue-500')
          const file = e.dataTransfer.files[0]
          if (file) handleTemplateFile(file)
        })
        tplFileInput.addEventListener('change', () => {
          if (tplFileInput.files[0]) handleTemplateFile(tplFileInput.files[0])
        })
      }

      const uploadBtn = el.querySelector('#tx-upload-template-btn')
      const tplNameInput = el.querySelector('#tx-template-name')
      if (uploadBtn && tplNameInput) {
        uploadBtn.addEventListener('click', async () => {
          const file = tplFileInput?.files[0] || _pendingTemplateFile
          const name = tplNameInput.value.trim()
          if (!file || !name) return
          try {
            uploadBtn.disabled = true
            uploadBtn.textContent = T('templates.uploading')
            await apiUpload('/templates', file, { name })
            _pendingTemplateFile = null
            showToast(T('app.saved'))
            await fetchTemplates()
            render()
          } catch (err) {
            showToast(err.message, 'error')
            uploadBtn.disabled = false
            uploadBtn.innerHTML = `${ICONS.upload} ${T('templates.upload')}`
          }
        })
      }

      // --- Template: file button (header button) ---
      const tplFileBtn = el.querySelector('#tx-template-file-btn')
      if (tplFileBtn) {
        tplFileBtn.addEventListener('change', () => {
          if (tplFileBtn.files[0]) handleTemplateFile(tplFileBtn.files[0])
        })
      }

      // --- Form: select ---
      el.querySelectorAll('[data-select-form]').forEach(row =>
        row.addEventListener('click', e => {
          if (e.target.closest('[data-delete-form]')) return
          handleSelectForm(row.dataset.selectForm)
        })
      )

      // --- Form: new ---
      el.querySelector('[data-action="new-form"]')
        ?.addEventListener('click', () => { state.showNewForm = true; state.editingForm = null; render() })

      // --- Form: edit ---
      el.querySelector('[data-action="edit-form"]')
        ?.addEventListener('click', () => {
          if (state.selectedForm) {
            state.editingForm = JSON.parse(JSON.stringify(state.selectedForm))
            state.selectedForm = null
            render()
          }
        })

      // --- Form: delete ---
      el.querySelectorAll('[data-delete-form]').forEach(btn =>
        btn.addEventListener('click', async e => {
          e.stopPropagation()
          if (!confirm(T('forms.confirm_delete'))) return
          try {
            await api(`/forms/${btn.dataset.deleteForm}`, { method: 'DELETE' })
            state.selectedForm = null
            showToast(T('app.saved'))
            await fetchForms()
            render()
          } catch (err) { showToast(err.message, 'error') }
        })
      )

      // --- Form editor: add field ---
      el.querySelector('[data-action="add-form-field"]')
        ?.addEventListener('click', () => {
          const form = state.editingForm || { name: '', language: 'de', fields: [] }
          if (!form.fields) form.fields = []
          form.fields.push({ key: '', label: '', type: 'text', required: false })
          state.editingForm = form
          state.showNewForm = !state.editingForm?.id
          render()
        })

      // --- Form editor: remove field ---
      el.querySelectorAll('[data-action="remove-form-field"]').forEach(btn =>
        btn.addEventListener('click', () => {
          const idx = parseInt(btn.dataset.idx)
          const form = state.editingForm
          if (form?.fields) {
            form.fields.splice(idx, 1)
            render()
          }
        })
      )

      // --- Form editor: submit ---
      const formEditor = el.querySelector('#tx-form-editor')
      if (formEditor) {
        formEditor.addEventListener('submit', async e => {
          e.preventDefault()
          const fd = new FormData(formEditor)
          const name = fd.get('form_name')?.toString().trim()
          const language = fd.get('form_language')?.toString() || 'de'
          if (!name) return
          const fields = []
          let idx = 0
          while (fd.has(`fk_${idx}`)) {
            const key = fd.get(`fk_${idx}`)?.toString().trim()
            if (key) {
              fields.push({
                key,
                label: fd.get(`fl_${idx}`)?.toString().trim() || key,
                type: fd.get(`ft_${idx}`)?.toString() || 'text',
                required: fd.has(`fr_${idx}`),
                source: 'form',
              })
            }
            idx++
          }
          try {
            const editing = state.editingForm
            if (editing?.id) {
              await api(`/forms/${editing.id}`, { method: 'PUT', body: JSON.stringify({ name, language, fields }) })
            } else {
              await api('/forms', { method: 'POST', body: JSON.stringify({ name, language, fields }) })
            }
            state.showNewForm = false
            state.editingForm = null
            state.selectedForm = null
            showToast(T('app.saved'))
            await fetchForms()
            render()
          } catch (err) { showToast(err.message, 'error') }
        })
      }

      // --- Entry: select ---
      el.querySelectorAll('[data-select-entry]').forEach(row =>
        row.addEventListener('click', () => handleSelectEntry(row.dataset.selectEntry))
      )

      // --- Entry: delete ---
      el.querySelectorAll('[data-delete-entry]').forEach(btn =>
        btn.addEventListener('click', async e => {
          e.stopPropagation()
          if (!confirm(T('entries.confirm_delete'))) return
          try {
            await api(`/candidates/${btn.dataset.deleteEntry}`, { method: 'DELETE' })
            state.selectedEntry = null
            showToast(T('app.saved'))
            await fetchEntries()
            render()
          } catch (err) { showToast(err.message, 'error') }
        })
      )

      // --- Entry: new button ---
      const newEntryBtn = el.querySelector('[data-action="new-entry"]')
      if (newEntryBtn) {
        newEntryBtn.addEventListener('click', () => {
          state.showNewEntry = true
          state.newEntryFormId = null
          state.newEntryFormDef = null
          render()
        })
      }

      // --- New entry: form selector change ---
      const entryFormSelect = el.querySelector('#tx-entry-form-select')
      if (entryFormSelect) {
        entryFormSelect.addEventListener('change', async () => {
          const fid = entryFormSelect.value
          if (!fid) {
            state.newEntryFormId = null
            state.newEntryFormDef = null
            render()
            return
          }
          state.newEntryFormId = fid
          try {
            const data = await api(`/forms/${fid}`)
            state.newEntryFormDef = data.form
          } catch (err) { showToast(err.message, 'error') }
          render()
        })
      }

      // --- New entry: form submission ---
      const newEntryForm = el.querySelector('#tx-new-entry-form')
      if (newEntryForm) {
        newEntryForm.addEventListener('submit', async e => {
          e.preventDefault()
          if (!state.newEntryFormId || !state.newEntryFormDef) return
          const formData = {}
          for (const field of (state.newEntryFormDef.fields || [])) {
            const input = newEntryForm.querySelector(`[name="${field.key}"]`)
            if (!input) continue
            if (field.type === 'checkbox') formData[field.key] = input.checked
            else if (field.type === 'list') formData[field.key] = input.value.split('\n').map(s => s.trim()).filter(Boolean)
            else formData[field.key] = input.value
          }
          try {
            const nameField = formData['target-position'] || formData['fullname'] || formData['name'] || ''
            await api('/candidates', {
              method: 'POST',
              body: JSON.stringify({ form_id: state.newEntryFormId, field_values: formData, name: nameField }),
            })
            showToast(T('app.saved'))
            state.showNewEntry = false
            state.newEntryFormId = null
            state.newEntryFormDef = null
            await fetchEntries()
            render()
          } catch (err) { showToast(err.message, 'error') }
        })
      }

      // --- Entry detail: file uploads ---
      const cvUpload = el.querySelector('#tx-cv-upload')
      if (cvUpload) {
        cvUpload.addEventListener('change', async () => {
          const file = cvUpload.files[0]
          if (!file || !state.selectedEntry) return
          try {
            await apiUpload(`/candidates/${state.selectedEntry.id}/upload-cv`, file)
            showToast(T('entries.doc_uploaded'))
            await handleSelectEntry(state.selectedEntry.id)
          } catch (err) { showToast(err.message, 'error') }
        })
      }
      const docUpload = el.querySelector('#tx-doc-upload')
      if (docUpload) {
        docUpload.addEventListener('change', async () => {
          const file = docUpload.files[0]
          if (!file || !state.selectedEntry) return
          try {
            await apiUpload(`/candidates/${state.selectedEntry.id}/upload-doc`, file)
            showToast(T('entries.doc_uploaded'))
            await handleSelectEntry(state.selectedEntry.id)
          } catch (err) { showToast(err.message, 'error') }
        })
      }

      // --- Entry detail: extract ---
      el.querySelector('[data-action="extract"]')
        ?.addEventListener('click', () => {
          const eid = el.querySelector('[data-action="extract"]')?.dataset.entryId
          if (eid) handleExtract(eid)
        })

      // --- Entry detail: generate template selector ---
      const genTplSelect = el.querySelector('#tx-generate-template')
      if (genTplSelect) {
        genTplSelect.addEventListener('change', () => {
          state.selectedGenerateTemplate = genTplSelect.value || null
          render()
        })
      }

      // --- Entry detail: generate ---
      el.querySelector('[data-action="generate"]')
        ?.addEventListener('click', () => {
          const eid = el.querySelector('[data-action="generate"]')?.dataset.entryId
          if (eid && state.selectedGenerateTemplate) handleGenerate(eid, state.selectedGenerateTemplate)
        })

      // --- Entry detail: download generated document ---
      el.querySelectorAll('[data-action="download-doc"]').forEach(btn =>
        btn.addEventListener('click', () => handleDownloadDoc(btn.dataset.entryId, btn.dataset.docId))
      )

      // --- Entry detail: override variable ---
      el.querySelectorAll('[data-action="override-var"]').forEach(btn =>
        btn.addEventListener('click', () => {
          state.editingVarKey = btn.dataset.varKey
          render()
        })
      )

      // --- Entry detail: save override ---
      el.querySelector('[data-action="save-override"]')
        ?.addEventListener('click', () => {
          const input = el.querySelector('#tx-override-input')
          const btn = el.querySelector('[data-action="save-override"]')
          if (input && btn && state.selectedEntry) {
            handleOverrideSave(state.selectedEntry.id, btn.dataset.varKey, input.value)
          }
        })

      // --- Entry detail: cancel override ---
      el.querySelector('[data-action="cancel-override"]')
        ?.addEventListener('click', () => { state.editingVarKey = null; render() })

      // --- Entry detail: toggle station group ---
      el.querySelectorAll('[data-action="toggle-station"]').forEach(btn =>
        btn.addEventListener('click', () => {
          const key = btn.dataset.stationKey
          const body = el.querySelector(`.tx-station-body[data-key="${key}"]`)
          const chevron = el.querySelector(`.tx-station-chevron[data-key="${key}"]`)
          if (body) {
            const hidden = body.style.display === 'none'
            body.style.display = hidden ? '' : 'none'
            if (chevron) chevron.innerHTML = hidden ? ICONS.chevDown : ICONS.chevRight
          }
        })
      )

      // --- Back buttons ---
      el.querySelector('[data-action="back-templates"]')
        ?.addEventListener('click', () => { state.selectedTemplate = null; render() })

      el.querySelector('[data-action="back-forms"]')
        ?.addEventListener('click', () => { state.selectedForm = null; state.showNewForm = false; state.editingForm = null; render() })

      el.querySelector('[data-action="back-entries"]')
        ?.addEventListener('click', () => {
          state.selectedEntry = null
          state.showNewEntry = false
          state.newEntryFormId = null
          state.newEntryFormDef = null
          state.entryVariables = null
          state.entryVariablesLoading = false
          state.extracting = false
          state.generating = false
          state.editingVarKey = null
          state.selectedGenerateTemplate = null
          render()
        })

      // --- Settings form ---
      const settingsForm = el.querySelector('#tx-settings-form')
      if (settingsForm) {
        settingsForm.addEventListener('submit', async e => {
          e.preventDefault()
          const body = Object.fromEntries(new FormData(settingsForm).entries())
          try {
            const res = await api('/config', { method: 'PUT', body: JSON.stringify(body) })
            if (res.success && state.status) {
              state.status.config = res.config
              const msg = el.querySelector('#tx-settings-msg')
              if (msg) {
                msg.classList.remove('hidden')
                setTimeout(() => msg.classList.add('hidden'), 2000)
              }
            }
          } catch (err) { showToast(T('app.error') + ': ' + err.message, 'error') }
        })
      }
    }

    // =========================================================================
    // Template file handler (shared by click-to-browse and drag-and-drop)
    // =========================================================================

    function handleTemplateFile(file) {
      _pendingTemplateFile = file
      const form = el.querySelector('#tx-template-upload-form')
      const nameInput = el.querySelector('#tx-template-name')
      if (form) form.classList.remove('hidden')
      if (nameInput && !nameInput.value) {
        nameInput.value = file.name.replace(/\.docx$/i, '')
      }
    }

    // =========================================================================
    // Data fetchers (do not trigger render — callers handle that)
    // =========================================================================

    async function fetchTemplates() {
      try {
        const d = await api('/templates')
        state.templates = d.templates || []
      } catch (_) { state.templates = [] }
    }

    async function fetchForms() {
      try {
        const d = await api('/forms')
        state.forms = d.forms || []
      } catch (_) { state.forms = [] }
    }

    async function fetchEntries() {
      try {
        const d = await api('/candidates')
        state.entries = d.candidates || []
      } catch (_) { state.entries = [] }
    }

    // =========================================================================
    // Entry actions: Extract, Variables, Generate, Override
    // =========================================================================

    async function handleExtract(entryId) {
      state.extracting = true
      render()
      try {
        await api(`/candidates/${entryId}/extract`, { method: 'POST' })
        const d = await api(`/candidates/${entryId}`)
        state.selectedEntry = d.candidate
        await loadEntryVariables(entryId)
      } catch (err) { showToast(err.message, 'error') }
      state.extracting = false
      render()
    }

    async function loadEntryVariables(entryId) {
      state.entryVariablesLoading = true
      try {
        const d = await api(`/candidates/${entryId}/variables`)
        const varsMap = d.variables || {}
        const sourcesDef = d.sources || {}
        const stationCount = d.station_count || 0
        const varList = []
        const stationData = []
        for (const [key, value] of Object.entries(varsMap)) {
          if (key.startsWith('stations.')) {
            const m = key.match(/^stations\.(\w+)\.(\d+)$/)
            if (m) {
              const field = m[1], idx = parseInt(m[2]) - 1
              if (!stationData[idx]) stationData[idx] = {}
              stationData[idx][field] = value
            }
            continue
          }
          const src = sourcesDef[key]
          let source = 'form'
          const overrides = state.selectedEntry?.variable_overrides || {}
          if (overrides[key] !== undefined && overrides[key] !== null) source = 'override'
          else if (src?.primary === 'ai') source = 'ai'
          varList.push({ key, value, source, type: key.startsWith('checkb.') ? 'checkbox' : 'text' })
        }
        if (stationData.length > 0) {
          varList.push({ key: 'stations', value: stationData, source: 'ai', type: 'station' })
        }
        state.entryVariables = varList
      } catch (_) { state.entryVariables = null }
      state.entryVariablesLoading = false
    }

    async function handleGenerate(entryId, templateId) {
      state.generating = true
      render()
      try {
        await api(`/candidates/${entryId}/generate/${templateId}`, { method: 'POST' })
        const d = await api(`/candidates/${entryId}`)
        state.selectedEntry = d.candidate
        showToast(T('entries.generate_done'), 'success')
      } catch (err) { showToast(err.message, 'error') }
      state.generating = false
      render()
    }

    function handleDownloadDoc(entryId, docId) {
      window.open(`${BASE}/candidates/${entryId}/documents/${docId}/download`, '_blank')
    }

    async function handleOverrideSave(entryId, key, value) {
      try {
        const existing = state.selectedEntry?.variable_overrides || {}
        const overrides = { ...existing, [key]: value }
        await api(`/candidates/${entryId}/variables`, {
          method: 'PUT',
          body: JSON.stringify({ overrides }),
        })
        if (state.selectedEntry) state.selectedEntry.variable_overrides = overrides
        await loadEntryVariables(entryId)
        state.editingVarKey = null
        showToast(T('app.saved'))
      } catch (err) { showToast(err.message, 'error') }
      render()
    }

    // =========================================================================
    // View data loader + detail selectors
    // =========================================================================

    async function loadViewData(view) {
      switch (view) {
        case 'templates': await fetchTemplates(); break
        case 'forms':     await fetchForms(); break
        case 'entries':   await Promise.all([fetchEntries(), fetchForms()]); break
      }
      render()
    }

    async function handleSelectTemplate(id) {
      try {
        const [tData, pData] = await Promise.all([
          api(`/templates/${id}`),
          api(`/templates/${id}/placeholders`),
        ])
        state.selectedTemplate = {
          ...tData.template,
          placeholders: pData.placeholders || [],
        }
      } catch (err) { showToast(err.message, 'error') }
      render()
    }

    async function handleSelectForm(id) {
      try {
        const d = await api(`/forms/${id}`)
        state.selectedForm = d.form
      } catch (err) { showToast(err.message, 'error') }
      render()
    }

    async function handleSelectEntry(id) {
      try {
        const d = await api(`/candidates/${id}`)
        state.selectedEntry = d.candidate
        state.entryVariables = null
        state.editingVarKey = null
        state.selectedGenerateTemplate = null
        const st = d.candidate?.status
        if (st === 'extracted' || st === 'reviewed' || st === 'generated') {
          await loadEntryVariables(id)
        }
        if (!state.templates || state.templates.length === 0) {
          await fetchTemplates()
        }
      } catch (err) { showToast(err.message, 'error') }
      render()
    }

    // =========================================================================
    // Init
    // =========================================================================

    async function init() {
      await loadTranslations()
      startLanguageWatcher()
      resolveRoute()
      render()

      try {
        const data = await api('/setup-check')
        if (!data.success) {
          state.error = data.error || T('app.not_available')
          state.loading = false
          render()
          return
        }
        state.status = data
        state.loading = false
        await Promise.all([fetchForms(), fetchTemplates(), fetchEntries()])
        loadViewData(state.view)
      } catch (err) {
        state.error = err.message
        state.loading = false
        render()
      }
    }

    init()
  },
}
