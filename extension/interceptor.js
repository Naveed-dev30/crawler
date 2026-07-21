// Injected at document_start in the page's MAIN world, before the SPA's bundle
// runs, so it observes every API call the app makes. Records each JSON response
// on window.__flCapture for the service worker to read after the page settles.
(function () {
  // Backstop: nothing in this script may ever throw into the page. Each patch
  // block below is already independently guarded (a failure to patch one
  // must not skip the other), but this outer try/catch covers anything
  // upstream of that — e.g. `window.__flCapture` or `window.fetch` being
  // accessors with throwing getters/setters (another extension's
  // document_start script racing this one).
  try {
    if (window.__flCapture) return // idempotent — never double-patch

    const captured = []
    window.__flCapture = captured
    const MAX = 120
    const MAX_BYTES = 2_000_000

    const record = (url, method, body) => {
      if (captured.length >= MAX) return
      captured.push({ url: String(url), method: method || 'GET', body })
    }

    const parse = (text) => {
      if (typeof text !== 'string' || text.length === 0 || text.length > MAX_BYTES) return undefined
      const t = text.trimStart()
      if (t[0] !== '{' && t[0] !== '[') return undefined
      try { return JSON.parse(text) } catch (e) { return undefined }
    }

    // responseType:'json' hands us an already-parsed object — there is no
    // raw text to measure against MAX_BYTES, so approximate the same cap by
    // re-stringifying. A stringify failure (e.g. a circular structure) means
    // skip recording, never throw.
    const withinByteCap = (value) => {
      try { return JSON.stringify(value).length <= MAX_BYTES } catch (e) { return false }
    }

    try {
      const realFetch = window.fetch
      if (typeof realFetch === 'function') {
        window.fetch = function (...args) {
          const promise = realFetch.apply(this, args)
          promise.then((res) => {
            try {
              const url = (res && res.url) || (args[0] && args[0].url) || args[0]
              const method = (args[1] && args[1].method) || (args[0] && args[0].method) || 'GET'
              res.clone().text().then((text) => {
                const body = parse(text)
                if (body !== undefined) record(url, method, body)
              }).catch(() => {})
            } catch (e) {}
          }).catch(() => {})
          return promise
        }
      }
    } catch (e) {}

    try {
      const XHR = window.XMLHttpRequest
      if (XHR && XHR.prototype) {
        const open = XHR.prototype.open
        const send = XHR.prototype.send
        XHR.prototype.open = function (method, url) {
          this.__flMethod = method
          this.__flUrl = url
          return open.apply(this, arguments)
        }
        XHR.prototype.send = function () {
          this.addEventListener('load', () => {
            try {
              const type = this.responseType
              if (type === '' || type === 'text') {
                const body = parse(this.responseText)
                if (body !== undefined) record(this.__flUrl, this.__flMethod, body)
              } else if (type === 'json' && this.response && typeof this.response === 'object') {
                if (withinByteCap(this.response)) record(this.__flUrl, this.__flMethod, this.response)
              }
            } catch (e) {}
          })
          return send.apply(this, arguments)
        }
      }
    } catch (e) {}
  } catch (e) {}
})()
