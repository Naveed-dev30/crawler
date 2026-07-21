// Given the array the page interceptor collected, walk every captured
// response recursively and pick the object that carries the most required
// keys — so the real data blob, where the keys co-locate, wins over a stray
// single-key false match, no matter how deep or under what envelope it sits.
const MAX_DEPTH = 6

function scoreObject(obj, requiredKeys, requireArray) {
  let n = 0
  for (const k of requiredKeys) {
    if (Object.prototype.hasOwnProperty.call(obj, k) && (!requireArray || Array.isArray(obj[k]))) n++
  }
  return n
}

// Depth-limited, cycle-safe walk. Visits every plain (non-array) object reachable
// from `body`, so a required key nested under any envelope is still found.
function walk(body, url, seen, depth, visit) {
  if (depth > MAX_DEPTH || body === null || typeof body !== 'object' || seen.has(body)) return
  seen.add(body)
  if (!Array.isArray(body)) visit(body, url)
  for (const key in body) {
    let value
    try { value = body[key] } catch (e) { continue }
    if (value && typeof value === 'object') walk(value, url, seen, depth + 1, visit)
  }
}

function keysOf(body) {
  if (Array.isArray(body)) return `array[${body.length}]`
  if (body && typeof body === 'object') return Object.keys(body).slice(0, 25)
  return typeof body
}

export function matchResponse(captured, requiredKeys, options = {}) {
  const requireArray = options.requireArray === true
  const list = Array.isArray(captured) ? captured.filter((e) => e && typeof e === 'object') : []

  let best = null
  let bestScore = 0
  let bestUrl = null
  let bestSize = -1

  for (const entry of list) {
    walk(entry.body, entry.url, new WeakSet(), 0, (obj, url) => {
      const s = scoreObject(obj, requiredKeys, requireArray)
      if (s === 0) return
      const size = Object.keys(obj).length
      // Higher score wins; on a tie, the richer object (more keys) wins — the
      // real data blob is denser than an incidental single-key match.
      if (s > bestScore || (s === bestScore && size > bestSize)) {
        best = obj
        bestScore = s
        bestUrl = url
        bestSize = size
      }
    })
  }

  if (best) return { strategy: `xhr ${bestUrl}`, data: best, diagnostics: {} }

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
