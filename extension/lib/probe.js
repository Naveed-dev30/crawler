// Given the array the page interceptor collected, walk every captured
// response recursively and pick the object that carries the most required
// keys — so the real data blob, where the keys co-locate, wins over a stray
// single-key false match, no matter how deep or under what envelope it sits.
const MAX_DEPTH = 6

// Scores how many of the required keys are present on `obj` (gated by
// `requireArray` — a matched key only counts if its value is an array), and
// the combined length of the arrays behind those matched keys. Every
// property read is guarded: a required key can be a throwing getter on data
// we do not control, and this must never throw (matchResponse's contract is
// to return a no-match diagnostic instead of blowing up).
function scoreObject(obj, requiredKeys, requireArray) {
  let keyCount = 0
  let arrayLen = 0
  for (const k of requiredKeys) {
    if (!Object.prototype.hasOwnProperty.call(obj, k)) continue
    let value
    try {
      value = obj[k]
    } catch (e) {
      continue
    }
    if (requireArray && !Array.isArray(value)) continue
    keyCount++
    if (Array.isArray(value)) arrayLen += value.length
  }
  return { keyCount, arrayLen }
}

// Depth-limited, cycle-safe walk. Visits every plain (non-array) object reachable
// from `body`, so a required key nested under any envelope is still found.
// Passes the current depth to `visit` so callers can prefer shallower matches.
function walk(body, url, seen, depth, visit) {
  if (depth > MAX_DEPTH || body === null || typeof body !== 'object' || seen.has(body)) return
  seen.add(body)
  if (!Array.isArray(body)) visit(body, url, depth)
  for (const key in body) {
    let value
    try {
      value = body[key]
    } catch (e) {
      continue
    }
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
  let bestArrayLen = -1
  let bestDepth = Infinity
  let bestSize = -1
  let bestUrl = null

  for (const entry of list) {
    walk(entry.body, entry.url, new WeakSet(), 0, (obj, url, depth) => {
      const { keyCount, arrayLen } = scoreObject(obj, requiredKeys, requireArray)
      if (keyCount === 0) return
      const size = Object.keys(obj).length
      // Ordered tie-break: (1) more required keys, (2) a larger matched
      // array (a real list beats an incidental nested one, e.g. a stray
      // `attachments.items`), (3) shallower depth, (4) richer object (more
      // own keys) — checked strictly in that priority order.
      const better =
        keyCount > bestScore ||
        (keyCount === bestScore &&
          (arrayLen > bestArrayLen ||
            (arrayLen === bestArrayLen &&
              (depth < bestDepth || (depth === bestDepth && size > bestSize)))))
      if (better) {
        best = obj
        bestScore = keyCount
        bestArrayLen = arrayLen
        bestDepth = depth
        bestSize = size
        bestUrl = url
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
      endpoints: list.slice(0, 120).map((e) => ({ url: e.url, keys: keysOf(e.body) })),
    },
  }
}
