// Given the array the page interceptor collected, pick the response whose body
// (possibly wrapped in a common API envelope) carries the required keys.
const ENVELOPES = ['result', 'data', 'payload', 'response']

function candidates(body) {
  const out = [body]
  if (body && typeof body === 'object' && !Array.isArray(body)) {
    for (const key of ENVELOPES) {
      if (body[key] && typeof body[key] === 'object') out.push(body[key])
    }
  }
  return out
}

function looksRight(o, requiredKeys, requireArray) {
  if (o === null || typeof o !== 'object') return false
  return requiredKeys.some((k) => (k in o) && (requireArray ? Array.isArray(o[k]) : true))
}

function keysOf(body) {
  if (Array.isArray(body)) return `array[${body.length}]`
  if (body && typeof body === 'object') return Object.keys(body).slice(0, 25)
  return typeof body
}

export function matchResponse(captured, requiredKeys, options = {}) {
  const requireArray = options.requireArray === true
  const list = Array.isArray(captured) ? captured.filter((e) => e && typeof e === 'object') : []

  for (const entry of list) {
    for (const candidate of candidates(entry.body)) {
      if (looksRight(candidate, requiredKeys, requireArray)) {
        return { strategy: `xhr ${entry.url}`, data: candidate, diagnostics: {} }
      }
    }
  }

  return {
    strategy: null,
    data: null,
    diagnostics: {
      requiredKeys,
      requireArray,
      capturedCount: list.length,
      // A miss hands us the discovery: every endpoint the SPA called + its keys.
      endpoints: list.slice(0, 40).map((e) => ({ url: e.url, keys: keysOf(e.body) })),
    },
  }
}
