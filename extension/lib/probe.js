// Injected into the page's MAIN world by the service worker. It must be
// self-contained: no imports, no closure over module scope. Chrome serializes
// the function source across the isolation boundary, so anything it references
// from outside would be undefined at run time.
export function findPageData(requiredKeys, options) {
  // `options` is passed across the MAIN-world serialization boundary, so it
  // must never be assumed present: an undefined/null options must not throw.
  const opts = options || {}
  const requireArray = opts.requireArray === true

  const NAMED_GLOBALS = ['serverData', '__INITIAL_STATE__', '__NUXT__', '__DATA__', 'appData', 'webapp']

  // Runs `thunk`, swallowing any exception in favor of `fallback`. Property
  // reads below (globals, DOM nodes, location) can all raise — cross-origin
  // accessors, hostile getters, a null document — and a throw inside an
  // injected MAIN-world function surfaces as an opaque failure with no
  // diagnostics, which is exactly what this probe exists to avoid.
  const safe = (thunk, fallback) => {
    try {
      return thunk()
    } catch (e) {
      return fallback
    }
  }

  const looksRight = (o) => {
    if (o === null || typeof o !== 'object') return false
    // `k in o` can itself throw for a hostile Proxy `has` trap; treat that
    // candidate as a non-match instead of aborting the whole scan.
    return safe(() => requiredKeys.some((k) => {
      if (!(k in o)) return false
      // With requireArray, mere key presence isn't enough — key-presence-only
      // matching is generic enough to hit an unrelated global that happens to
      // carry e.g. an `items` property that isn't a list at all.
      return requireArray ? Array.isArray(o[k]) : true
    }), false)
  }

  const read = (key) => safe(() => window[key], undefined)

  try {
    for (const key of NAMED_GLOBALS) {
      const value = read(key)
      if (looksRight(value)) return { strategy: `window.${key}`, data: value, diagnostics: {} }
    }

    const globalKeys = safe(() => Object.keys(window), [])
    for (const key of globalKeys) {
      const value = read(key)
      if (looksRight(value)) return { strategy: `window.${key} (scan)`, data: value, diagnostics: {} }
    }

    const scripts = safe(() => Array.from(document.querySelectorAll('script:not([src])')), [])
    const scriptText = (script) => safe(() => script.textContent || '', '')

    for (const script of scripts) {
      const text = scriptText(script)
      // Match an assignment to any of the named globals, then balance-scan the
      // object literal. A lazy regex would stop at the first inner brace.
      for (const name of NAMED_GLOBALS) {
        const start = text.indexOf(name + ' =') >= 0 ? text.indexOf(name + ' =') : text.indexOf(name + '=')
        if (start < 0) continue

        const braceStart = text.indexOf('{', start)
        if (braceStart < 0) continue

        let depth = 0
        for (let i = braceStart; i < text.length; i++) {
          if (text[i] === '{') depth++
          else if (text[i] === '}') depth--

          if (depth === 0) {
            try {
              const parsed = JSON.parse(text.slice(braceStart, i + 1))
              if (looksRight(parsed)) return { strategy: 'inline script', data: parsed, diagnostics: {} }
            } catch (e) {
              // Not JSON (a JS literal with unquoted keys, say) — keep looking.
            }
            break
          }
        }
      }
    }

    return {
      strategy: null,
      data: null,
      diagnostics: {
        url: safe(() => location.href, null),
        title: safe(() => document.title, null),
        globalCount: globalKeys.length,
        globalsSampled: globalKeys.filter((k) => !k.startsWith('webkit')).slice(0, 60),
        namedGlobalsTried: NAMED_GLOBALS,
        inlineScripts: scripts.length,
        scriptsMentioningKeys: scripts.filter((s) =>
          requiredKeys.some((k) => scriptText(s).includes(k))
        ).length,
        // Only metadata, never full script bodies: enough to spot a pattern
        // our two script strategies don't handle yet, e.g. a JSON island like
        // `<script type="application/json" id="__NEXT_DATA__">{...}</script>`
        // with no `name = ` assignment for the regex scan to find.
        scriptsSample: scripts.slice(0, 5).map((s) => ({
          id: safe(() => s.id, null) || null,
          type: safe(() => s.type, null) || null,
          length: scriptText(s).length,
        })),
        requiredKeys,
        requireArray,
      },
    }
  } catch (e) {
    // Belt-and-suspenders: something outside the guarded reads above raised
    // anyway. Still never throw — return the same diagnostics shape callers
    // expect, with whatever we know about the failure.
    return {
      strategy: null,
      data: null,
      diagnostics: { error: String((e && e.message) || e) },
    }
  }
}
