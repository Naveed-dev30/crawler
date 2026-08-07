import { CAPTURES } from './captures/index.js'
import { matchResponse } from './lib/probe.js'
import { postCapture, MissingConfigError } from './lib/http.js'
import { assertLoggedIn, LoggedOutError } from './lib/session.js'
import { withRetry } from './lib/retry.js'

// Two cadences: fast-changing bid activity hourly, everything else daily.
// A capture's `cadence` field ('hourly' | 'daily') routes it; default is daily.
const DAILY_ALARM = 'capture-daily'
const HOURLY_ALARM = 'capture-hourly'
const DAILY_MINUTES = 24 * 60
const HOURLY_MINUTES = 60
// A stalled navigation or redirect loop must never hang the run forever —
// silence is the worst possible outcome for a system whose whole point is to
// report back what happened on the first real click.
const LOAD_TIMEOUT_MS = 30000
// A login page's <head> (analytics bootstrapping, inline bundles) can run
// well past a few KB; truncating too early lets a login page evade the guard.
const LOGIN_GUARD_HTML_LIMIT = 50000
const CAPTURE_WINDOW_MS = 25000  // how long to wait for the SPA's data XHR
const POLL_INTERVAL_MS = 1500
const SCRAPE_SETTLE_MS = 4000  // charts/tables render after load; give them a moment

// Creating an alarm that already exists replaces it, restarting its period —
// so an unguarded re-create on every browser start would fire the daily capture
// (and its foreground tab) a minute after each restart. Only fill in what's
// missing.
async function ensureAlarms() {
  const existing = new Set((await chrome.alarms.getAll()).map((a) => a.name))
  if (!existing.has(DAILY_ALARM)) {
    chrome.alarms.create(DAILY_ALARM, { periodInMinutes: DAILY_MINUTES, delayInMinutes: 1 })
  }
  if (!existing.has(HOURLY_ALARM)) {
    chrome.alarms.create(HOURLY_ALARM, { periodInMinutes: HOURLY_MINUTES, delayInMinutes: 1 })
  }
}

chrome.runtime.onInstalled.addListener(ensureAlarms)
// Alarms survive a browser restart, but one that is somehow lost or cleared
// otherwise never comes back — and a capture system that has silently stopped
// scheduling itself looks identical to one with nothing to report.
chrome.runtime.onStartup.addListener(ensureAlarms)

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === DAILY_ALARM) captureAll((c) => c.cadence !== 'hourly')
  else if (alarm.name === HOURLY_ALARM) captureAll((c) => c.cadence === 'hourly')
})

// A manual click captures everything, regardless of cadence.
chrome.action.onClicked.addListener(() => captureAll())

// Resolves once the tab reports status:'complete', or after `timeoutMs`,
// whichever comes first. The onUpdated listener is always removed before
// resolving, on both paths, so nothing leaks across runs.
function waitForTabComplete(tabId, timeoutMs) {
  return new Promise((resolve) => {
    let timer
    let settled = false
    const finish = (value) => {
      if (settled) return
      settled = true
      chrome.tabs.onUpdated.removeListener(listener)
      clearTimeout(timer)
      resolve(value)
    }
    const listener = (updatedTabId, info) => {
      if (updatedTabId === tabId && info.status === 'complete') finish({ timedOut: false })
    }
    chrome.tabs.onUpdated.addListener(listener)
    timer = setTimeout(() => finish({ timedOut: true }), timeoutMs)

    // The tab can reach 'complete' in the gap between chrome.tabs.create
    // resolving and this listener attaching; that event is then gone for good,
    // so the promise would settle only on the timeout and report a page that
    // loaded fine as loadTimedOut. Re-read the current state to close the race.
    // `pendingUrl` and an empty url both mean the navigation hasn't committed
    // yet — a fresh tab can report 'complete' for its initial blank document.
    chrome.tabs.get(tabId).then((tab) => {
      if (tab && tab.status === 'complete' && tab.url && !tab.pendingUrl) finish({ timedOut: false })
    }).catch(() => {})
  })
}

async function registerInterceptor(source, matchPattern) {
  const id = `fl-interceptor-${source}`
  try {
    const existing = await chrome.scripting.getRegisteredContentScripts({ ids: [id] })
    if (existing.length) await chrome.scripting.unregisterContentScripts({ ids: [id] })
  } catch (e) {}
  await chrome.scripting.registerContentScripts([{
    id,
    matches: [matchPattern],
    js: ['interceptor.js'],
    runAt: 'document_start',
    world: 'MAIN',
    persistAcrossSessions: false,
  }])
  return id
}

// chrome.scripting.executeScript resolves to an empty array when the target
// frame is gone (closed tab, mid-navigation) and to a null `result` when the
// injected function threw — destructuring either blindly turns a diagnosable
// page-read failure into an opaque TypeError in the run report.
async function executeInTab(options, what) {
  const frames = await chrome.scripting.executeScript(options)
  const result = frames && frames[0] ? frames[0].result : null
  if (result == null) throw new Error(`${what}: the tab returned no result (frame gone, or the read threw)`)
  return result
}

// Pulls only the entries the poller has not already seen. The whole array is
// re-serialized across the world boundary on every read otherwise — up to 120
// bodies of up to 2MB each (interceptor.js's caps), ~17 times across one
// capture window, which is enough to make the polling itself the bottleneck.
// Returns { total, entries } so the caller can still detect a shrink, or null
// if the read failed — which must stay distinguishable from "nothing new".
async function readCaptured(tabId, from) {
  try {
    return await executeInTab({
      target: { tabId },
      world: 'MAIN',
      func: (start) => {
        const all = window.__flCapture || []
        return { total: all.length, entries: all.slice(start) }
      },
      args: [from],
    }, 'capture read')
  } catch (e) {
    return null
  }
}

async function openAndCapture(capture) {
  // registerInterceptor is awaited first and left outside the try: if it
  // throws, nothing was registered, so there is nothing to unregister. Once
  // it succeeds, everything that could fail (tab creation included) must be
  // inside the try so the finally below can always clean up the shim — and,
  // if the tab was created, the tab too.
  const id = await registerInterceptor(capture.source, capture.matchPattern)
  let tab = null

  try {
    // Some captures (chart-heavy dashboards) only fetch their data when the tab
    // is visible, so honor a per-capture activeTab flag; the rest stay background.
    tab = await chrome.tabs.create({ url: capture.url, active: capture.activeTab === true })
    const { timedOut: loadTimedOut } = await waitForTabComplete(tab.id, LOAD_TIMEOUT_MS)

    const pageInfo = await executeInTab({
      target: { tabId: tab.id },
      func: (limit) => ({ url: location.href, html: document.documentElement.outerHTML.slice(0, limit) }),
      args: [LOGIN_GUARD_HTML_LIMIT],
    }, `${capture.source}: login check`)
    assertLoggedIn({ url: pageInfo.url, status: 200 }, pageInfo.html)

    // The SPA fires its data XHR shortly after load. Poll until a response
    // matches, or the window closes — whichever first, accumulating only what
    // each read adds. A read that fails transiently (its own executeScript
    // losing the frame mid-navigation) is skipped, never treated as an empty
    // page; a total smaller than what we have already pulled means the document
    // was replaced and its interceptor started a fresh array, so keep the
    // entries collected so far and start pulling the new document from zero.
    const deadline = Date.now() + CAPTURE_WINDOW_MS
    const captured = []
    let pulled = 0
    while (Date.now() < deadline) {
      const read = await readCaptured(tab.id, pulled)
      if (read) {
        if (read.total < pulled) pulled = 0
        else if (read.entries.length) {
          captured.push(...read.entries)
          pulled = read.total
          // Only worth re-walking when something new arrived; this is purely an
          // early exit, and runIntercept re-runs the match on the final set.
          if (matchResponse(captured, capture.requiredKeys, capture.probeOptions ?? {}).strategy) break
        }
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS))
    }

    return { captured, loadTimedOut }
  } finally {
    // Each cleanup call is independently guarded so a failure in one does not
    // skip the other: the shim must be unregistered whether or not the tab
    // was ever created (chrome.tabs.create throwing leaves tab === null).
    if (tab) await chrome.tabs.remove(tab.id).catch(() => {})
    await chrome.scripting.unregisterContentScripts({ ids: [id] }).catch(() => {})
  }
}

// Truncated, safe-to-log preview of a captured body: top-level keys plus
// roughly the first 500 characters of its JSON. Never the full payload —
// these can be large, and this only needs to be enough to spot a shape bug.
// Top-level arrays are cut to two entries BEFORE stringifying: a bids payload
// holds thousands of records, and building a multi-MB string only to slice 500
// characters off it is the last thing a failing run should spend memory on.
// `arrayLengths` keeps the fact that they were long, which the shape alone
// would otherwise lose.
function previewBody(body) {
  const shallow = {}
  const arrayLengths = {}
  for (const [key, value] of Object.entries(body ?? {})) {
    if (Array.isArray(value)) {
      arrayLengths[key] = value.length
      shallow[key] = value.slice(0, 2)
    } else {
      shallow[key] = value
    }
  }

  let json
  try {
    json = JSON.stringify(shallow)
  } catch (e) {
    json = '(unserializable)'
  }

  return {
    keys: Object.keys(body ?? {}),
    arrayLengths,
    json: json.length > 500 ? json.slice(0, 500) + '…' : json,
  }
}

async function runOne(capture) {
  return capture.mode === 'scrape' ? runScrape(capture) : runIntercept(capture)
}

async function readPage(tabId, what) {
  // MAIN world so the reader can reach page globals (window.Chart) for the
  // canvas charts; the DOM (innerText, attributes) is shared across worlds, so
  // the text and attribute reads work here too.
  return executeInTab({
    target: { tabId },
    world: 'MAIN',
    func: (limit) => {
      // "Rating per skill" stores each rating in a `data-star_rating` DOM
      // attribute (not page text). Guarded so a markup change yields [] rather
      // than breaking the read.
      let ratingPerSkill = []
      try {
        const card = Array.from(document.querySelectorAll('.StatCard')).find((c) => {
          const t = c.querySelector('.StatCard-header-title')
          return t && t.textContent.trim() === 'Rating per skill'
        })
        if (card) {
          ratingPerSkill = Array.from(card.querySelectorAll('.StatTypeList-row'))
            .map((row) => {
              const nameEl = row.querySelector('.StatTypeList-row-name')
              const ratingEl = row.querySelector('[data-star_rating]')
              const name = nameEl ? nameEl.textContent.trim() : null
              return name ? { name, value: ratingEl ? ratingEl.getAttribute('data-star_rating') : null } : null
            })
            .filter(Boolean)
        }
      } catch (e) {
        ratingPerSkill = []
      }

      // "Trending skills" shows each skill's movement as an arrow icon, which
      // contributes nothing to innerText — so the direction has to come from the
      // markup. Two independent signals, tried in order:
      //   1. direction words in the row's class/data/aria attributes
      //   2. the arrow's rendered colour (Freelancer draws up green, down red)
      // Neither hit means unknown, reported as 'even' — a neutral marker is
      // correct-looking; a guessed arrow is wrong data.
      let trendingSkills = []
      try {
        const UP = new Set(['up', 'upward', 'upwards', 'increase', 'increasing', 'increased', 'rise', 'rising', 'risen', 'positive', 'gain', 'growth', 'ascending', 'asc', 'success', 'green'])
        const DOWN = new Set(['down', 'downward', 'downwards', 'decrease', 'decreasing', 'decreased', 'fall', 'falling', 'fallen', 'negative', 'loss', 'drop', 'dropping', 'descending', 'desc', 'danger', 'red'])
        // Attributes that can name a direction. The row's visible text is
        // deliberately excluded: a skill literally called "Growth Hacking" or
        // "Upwork Migration" must not read as an up arrow.
        const DIRECTION_ATTRS = ['class', 'name', 'title', 'alt', 'aria-label', 'href', 'xlink:href', 'd']
        // Splits on separators AND camelCase, so 'TrendingRow-arrow--up',
        // 'bx-trending-down' and 'arrowUp' all yield a bare 'up'/'down' token.
        const tokens = (s) => String(s || '')
          .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
          .toLowerCase()
          .split(/[^a-z0-9]+/)
          .filter(Boolean)

        const fromAttributes = (elements) => {
          let seen = null
          for (const el of elements) {
            for (const attr of Array.from(el.attributes || [])) {
              const n = attr.name.toLowerCase()
              // 'd' is the SVG path geometry — included only because icon sprites
              // sometimes carry the direction in a path id, never as coordinates.
              if (!DIRECTION_ATTRS.includes(n) && !n.startsWith('data-')) continue
              for (const t of tokens(attr.value)) {
                // 'down' wins on sight: a down arrow inside a widget whose own
                // container is named "...trending-up-list" must read as down.
                if (DOWN.has(t)) return 'down'
                if (UP.has(t)) seen = 'up'
              }
            }
          }
          return seen
        }

        // Green-dominant → up, red-dominant → down. Margins are wide enough that
        // the grey neutral marker (#a1acb8) and ordinary text colours stay
        // unclassified.
        const hue = (value) => {
          const m = String(value || '').match(/rgba?\(([^)]+)\)/)
          if (!m) return null
          const p = m[1].split(',').map((x) => parseFloat(x))
          if (p.length < 3 || p.some((x) => !isFinite(x))) return null
          if (p.length > 3 && p[3] === 0) return null
          const [r, g, b] = p
          if (g > r + 30 && g > b + 10) return 'up'
          if (r > g + 30 && r > b + 10) return 'down'
          return null
        }

        const fromColour = (elements) => {
          for (const el of elements) {
            const cs = window.getComputedStyle(el)
            if (!cs) continue
            for (const prop of ['fill', 'color', 'borderBottomColor', 'borderTopColor', 'backgroundColor']) {
              // An element's own text colour is only evidence if it renders no
              // text — otherwise every row's label would classify itself.
              if (prop === 'color' && el.textContent && el.textContent.trim()) continue
              const d = hue(cs[prop])
              if (d) return d
            }
          }
          return null
        }

        const card = Array.from(document.querySelectorAll('.StatCard')).find((c) => {
          const t = c.querySelector('.StatCard-header-title')
          return t && t.textContent.trim().toLowerCase() === 'trending skills'
        })
        if (card) {
          let rows = Array.from(card.querySelectorAll('.StatTypeList-row'))
          if (!rows.length) rows = Array.from(card.querySelectorAll('li'))
          trendingSkills = rows
            .map((row) => {
              const nameEl = row.querySelector('.StatTypeList-row-name')
              const name = (nameEl ? nameEl.textContent : row.textContent).replace(/\s+/g, ' ').trim()
              if (!name) return null
              // Scan the row minus the name cell, so the skill's own label (and
              // any tooltip repeating it) cannot supply a direction token. The
              // row element itself leads the list: a modifier class on the row
              // ('StatTypeList-row--up') is the likeliest place for the
              // direction to live, and querySelectorAll('*') returns only
              // descendants, so scanning children alone would always miss it.
              const scope = [row, ...row.querySelectorAll('*')]
                .filter((el) => !nameEl || (el !== nameEl && !nameEl.contains(el)))
              const direction = fromAttributes(scope) || fromColour(scope) || 'even'
              return { name, direction }
            })
            .filter(Boolean)
        }
      } catch (e) {
        trendingSkills = []
      }

      // Profile-view counts are Chart.js line charts on a <canvas>; their data
      // lives in the chart instance, not the DOM. Read it by canvas id, handling
      // both Chart.js v2 (Chart.instances) and v3+ (Chart.getChart). Returns
      // { labels, values } or null if the chart isn't reachable.
      const chartData = (canvasId) => {
        try {
          const canvas = document.getElementById(canvasId)
          if (!canvas) return null
          let chart = null
          const C = window.Chart
          if (C) {
            if (typeof C.getChart === 'function') chart = C.getChart(canvas)
            if (!chart && C.instances) {
              chart = Object.values(C.instances).find((x) => x && (x.canvas === canvas || (x.chart && x.chart.canvas === canvas)))
            }
          }
          if (!chart && canvas.chart) chart = canvas.chart
          const data = chart && (chart.data || (chart.chart && chart.chart.data))
          if (!data) return null
          const ds = (data.datasets || [])[0]
          return { labels: data.labels || [], values: ds ? (ds.data || []) : [] }
        } catch (e) {
          return null
        }
      }

      return {
        url: location.href,
        text: document.body ? document.body.innerText : '',
        html: document.documentElement.outerHTML.slice(0, limit),
        dom: {
          ratingPerSkill,
          trendingSkills,
          profileViewCountPastWeek: chartData('profileViewCountPastWeek-chart'),
          profileViewCountPastYear: chartData('profileViewCountPastYear-chart'),
        },
      }
    },
    args: [LOGIN_GUARD_HTML_LIMIT],
  }, what)
}

async function runScrape(capture) {
  let tab = null
  try {
    const views = capture.views || null
    tab = await chrome.tabs.create({ url: views ? views[0].url : capture.url, active: capture.activeTab === true })
    const { timedOut: loadTimedOut } = await waitForTabComplete(tab.id, LOAD_TIMEOUT_MS)

    let body
    if (views) {
      const collected = {}
      for (let i = 0; i < views.length; i++) {
        // Subsequent views are hash-route switches on the same SPA — no full
        // reload fires, so just change the hash and let it render.
        if (i > 0) await chrome.tabs.update(tab.id, { url: views[i].url })
        await new Promise((r) => setTimeout(r, SCRAPE_SETTLE_MS))
        const page = await readPage(tab.id, `${capture.source}: ${views[i].key}`)
        assertLoggedIn({ url: page.url, status: 200 }, page.html)
        collected[views[i].key] = views[i].scrape(page.text, page.dom)
      }
      // Stamped once the reads are done, so scraped_at describes the data
      // rather than the moment the tab finished loading, ~8s earlier.
      body = capture.combine(collected, new Date().toISOString())
    } else {
      // Charts/tables render after load; give them a moment.
      await new Promise((r) => setTimeout(r, SCRAPE_SETTLE_MS))
      const page = await readPage(tab.id, capture.source)
      assertLoggedIn({ url: page.url, status: 200 }, page.html)
      body = capture.scrape(page.text, new Date().toISOString())
    }

    if (!body || body.__empty) {
      const error = new Error(`${capture.source}: nothing scraped from the page`)
      // Deliberately not fatal: an empty scrape is most often a slow render or
      // a route that had not painted yet within SCRAPE_SETTLE_MS — precisely
      // what the backoff exists for. A genuine markup change still fails all
      // three attempts and reports the same way, just later.
      error.diagnostics = { loadTimedOut }
      throw error
    }
    delete body.__empty

    const response = await postCapture(capture.path, body)
    if (!response.ok) {
      const error = new Error(`${capture.source}: API returned ${response.status}`)
      error.fatal = response.status === 401 || response.status === 422
      error.diagnostics = { path: capture.path, status: response.status, responseBody: response.data, sentPreview: previewBody(body) }
      throw error
    }

    const warnings = capture.warnings ? capture.warnings(body) : []
    const outcome = { strategy: 'scrape', status: response.status, id: response.data?.id ?? null }
    if (loadTimedOut) outcome.loadTimedOut = true
    if (warnings.length) outcome.warnings = warnings
    return outcome
  } finally {
    if (tab) await chrome.tabs.remove(tab.id).catch(() => {})
  }
}

async function runIntercept(capture) {
  const { captured, loadTimedOut } = await openAndCapture(capture)
  const probe = matchResponse(captured, capture.requiredKeys, capture.probeOptions ?? {})

  if (!probe.strategy) {
    const error = new Error(`${capture.source}: no API response matched`)
    // Deliberately not fatal: the usual cause is the data XHR landing after
    // CAPTURE_WINDOW_MS on a slow connection, which the next attempt fixes. A
    // real shape change fails all three attempts and reports the endpoint list
    // from the last one — the same diagnosis, one backoff later.
    error.diagnostics = { ...probe.diagnostics, loadTimedOut }
    throw error
  }

  const scrapedAt = new Date().toISOString()
  const body = capture.normalize(probe.data, scrapedAt)
  const response = await postCapture(capture.path, body)

  if (!response.ok) {
    const error = new Error(`${capture.source}: API returned ${response.status}`)
    error.fatal = response.status === 401 || response.status === 422
    // A 422 is the single most informative failure this system can hit on a
    // first run — it means a normalize() shape assumption is wrong. Without
    // this, it surfaces as nothing more than a status code.
    error.diagnostics = {
      strategy: probe.strategy,
      path: capture.path,
      status: response.status,
      responseBody: response.data,
      sentPreview: previewBody(body),
    }
    throw error
  }

  const warnings = capture.warnings ? capture.warnings(body) : []
  const outcome = { strategy: probe.strategy, status: response.status, id: response.data?.id ?? null }
  if (loadTimedOut) outcome.loadTimedOut = true
  if (warnings.length) outcome.warnings = warnings
  return outcome
}

// Guards against overlapping runs: a second click, or the alarm firing
// mid-run, would otherwise race the `lastRun` write with whichever run
// finishes last silently winning.
let running = false
// Triggers that arrived mid-run. Dropping them meant an hourly bid alarm
// landing inside a long daily run vanished with no record, and a toolbar click
// during a run did nothing at all — indistinguishable, from the outside, from
// a broken extension. They are collected and run once the current run ends,
// unioned so two triggers for the same capture still produce a single run.
let queuedFilters = []

async function captureAll(filter = () => true) {
  if (running) {
    queuedFilters.push(filter)
    return
  }
  running = true

  try {
    let next = filter
    while (next) {
      try {
        await runCaptures(next)
      } catch (error) {
        // Per-capture failures are already recorded inside runCaptures, so
        // reaching here means the run scaffolding itself failed (storage, badge,
        // notifications). Clear the in-progress badge and drain the queue anyway
        // rather than leaving it stuck on '...' forever.
        console.error('[capture] run failed', error)
        await setBadge('!', '#CC0000').catch(() => {})
      }
      const pending = queuedFilters
      queuedFilters = []
      next = pending.length ? (capture) => pending.some((f) => f(capture)) : null
    }
  } finally {
    running = false
  }
}

async function runCaptures(filter) {
  const captures = CAPTURES.filter(filter)
  await setBadge('...', '#666666')

  // Merge into the previous run's results rather than overwrite: an hourly
  // bid run must not erase the last daily gamification/insights results from
  // the report the options page shows. Because carried-over entries sit next
  // to this run's startedAt/finishedAt, each result carries its own `at` —
  // without it a result from three days ago reads as part of this run.
  const prev = (await chrome.storage.local.get({ lastRun: null })).lastRun
  const report = {
    startedAt: new Date().toISOString(),
    results: { ...(prev && prev.results ? prev.results : {}) },
  }
  const ranSources = []

  for (const capture of captures) {
    ranSources.push(capture.source)
    try {
      const outcome = await withRetry(() => runOne(capture))
      report.results[capture.source] = { ok: true, at: new Date().toISOString(), ...outcome }
    } catch (error) {
      report.results[capture.source] = {
        ok: false,
        at: new Date().toISOString(),
        error: error.message,
        kind: error instanceof LoggedOutError ? 'logged_out'
          : error instanceof MissingConfigError ? 'not_configured'
          : 'failed',
        diagnostics: error.diagnostics ?? null,
      }
      console.error(`[capture] ${capture.source}`, error, error.diagnostics ?? '')
    }
  }

  report.finishedAt = new Date().toISOString()
  await chrome.storage.local.set({ lastRun: report })

  // Badge and notification reflect only the captures that ran this cycle.
  const thisRun = ranSources.map((s) => report.results[s])
  const failed = thisRun.filter((r) => !r.ok)
  const warned = thisRun.filter((r) => r.ok && r.warnings?.length)
  await setBadge(failed.length ? String(failed.length) : 'ok', failed.length ? '#CC0000' : '#0A7F27')

  const titleParts = [failed.length ? `${failed.length}/${ranSources.length} captures failed` : 'All captures posted']
  if (warned.length) titleParts.push(`${warned.length} with warnings`)

  notify(titleParts.join(', '), summarize(report, ranSources))
}

function summarize(report, sources) {
  const keys = sources ?? Object.keys(report.results)
  return keys
    .map((source) => {
      const r = report.results[source]
      if (!r) return null
      if (!r.ok) return `${source}: ${r.kind}`
      const warn = r.warnings?.length ? ` (warnings: ${r.warnings.length})` : ''
      return `${source}: ok via ${r.strategy}${warn}`
    })
    .filter(Boolean)
    .join('\n')
}

async function setBadge(text, color) {
  await chrome.action.setBadgeText({ text })
  await chrome.action.setBadgeBackgroundColor({ color })
}

function notify(title, message) {
  chrome.notifications.create({
    type: 'basic',
    iconUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    title,
    message: message || '(no detail)',
  })
}
