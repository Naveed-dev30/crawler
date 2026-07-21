// Injected at document_start in the page's MAIN world, before the SPA's bundle
// runs, so it observes every API call the app makes. Records each JSON response
// on window.__flCapture for the service worker to read after the page settles.
(function () {
  if (window.__flCapture) return // idempotent — never double-patch

  const captured = []
  window.__flCapture = captured
  const MAX = 60
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

  const realFetch = window.fetch
  if (typeof realFetch === 'function') {
    window.fetch = function (...args) {
      const promise = realFetch.apply(this, args)
      promise.then((res) => {
        try {
          const url = (res && res.url) || (args[0] && args[0].url) || args[0]
          const method = (args[1] && args[1].method) || 'GET'
          res.clone().text().then((text) => {
            const body = parse(text)
            if (body !== undefined) record(url, method, body)
          }).catch(() => {})
        } catch (e) {}
      }).catch(() => {})
      return promise
    }
  }

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
            record(this.__flUrl, this.__flMethod, this.response)
          }
        } catch (e) {}
      })
      return send.apply(this, arguments)
    }
  }
})()
