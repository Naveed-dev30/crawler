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

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create(DAILY_ALARM, { periodInMinutes: DAILY_MINUTES, delayInMinutes: 1 })
  chrome.alarms.create(HOURLY_ALARM, { periodInMinutes: HOURLY_MINUTES, delayInMinutes: 1 })
})

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
    const cleanup = () => {
      chrome.tabs.onUpdated.removeListener(listener)
      clearTimeout(timer)
    }
    const listener = (updatedTabId, info) => {
      if (updatedTabId === tabId && info.status === 'complete') {
        cleanup()
        resolve({ timedOut: false })
      }
    }
    chrome.tabs.onUpdated.addListener(listener)
    timer = setTimeout(() => {
      cleanup()
      resolve({ timedOut: true })
    }, timeoutMs)
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

async function readCaptured(tabId) {
  try {
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId },
      world: 'MAIN',
      func: () => window.__flCapture || [],
    })
    return result || []
  } catch (e) {
    return []
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

    const [{ result: pageInfo }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: (limit) => ({ url: location.href, html: document.documentElement.outerHTML.slice(0, limit) }),
      args: [LOGIN_GUARD_HTML_LIMIT],
    })
    assertLoggedIn({ url: pageInfo.url, status: 200 }, pageInfo.html)

    // The SPA fires its data XHR shortly after load. Poll until a response
    // matches, or the window closes — whichever first. readCaptured can
    // transiently return `[]` (its own executeScript read failing while the
    // tab is mid-navigation) — a shrinking read must never clobber a larger
    // capture already seen, so only accept a fresh read that is at least as
    // large as what's retained, and match against the retained value.
    const deadline = Date.now() + CAPTURE_WINDOW_MS
    let captured = []
    while (Date.now() < deadline) {
      const fresh = await readCaptured(tab.id)
      if (fresh.length >= captured.length) captured = fresh
      if (matchResponse(captured, capture.requiredKeys, capture.probeOptions ?? {}).strategy) break
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
function previewBody(body) {
  const json = JSON.stringify(body)
  return {
    keys: Object.keys(body ?? {}),
    json: json.length > 500 ? json.slice(0, 500) + '…' : json,
  }
}

async function runOne(capture) {
  return capture.mode === 'scrape' ? runScrape(capture) : runIntercept(capture)
}

async function readPage(tabId) {
  // MAIN world so the reader can reach page globals (window.Chart) for the
  // canvas charts; the DOM (innerText, attributes) is shared across worlds, so
  // the text and attribute reads work here too.
  const [{ result }] = await chrome.scripting.executeScript({
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
              // any tooltip repeating it) cannot supply a direction token.
              const scope = Array.from(row.querySelectorAll('*'))
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
  })
  return result
}

async function runScrape(capture) {
  let tab = null
  try {
    const views = capture.views || null
    tab = await chrome.tabs.create({ url: views ? views[0].url : capture.url, active: capture.activeTab === true })
    const { timedOut: loadTimedOut } = await waitForTabComplete(tab.id, LOAD_TIMEOUT_MS)
    const scrapedAt = new Date().toISOString()

    let body
    if (views) {
      const collected = {}
      for (let i = 0; i < views.length; i++) {
        // Subsequent views are hash-route switches on the same SPA — no full
        // reload fires, so just change the hash and let it render.
        if (i > 0) await chrome.tabs.update(tab.id, { url: views[i].url })
        await new Promise((r) => setTimeout(r, SCRAPE_SETTLE_MS))
        const page = await readPage(tab.id)
        assertLoggedIn({ url: page.url, status: 200 }, page.html)
        collected[views[i].key] = views[i].scrape(page.text, page.dom)
      }
      body = capture.combine(collected, scrapedAt)
    } else {
      // Charts/tables render after load; give them a moment.
      await new Promise((r) => setTimeout(r, SCRAPE_SETTLE_MS))
      const page = await readPage(tab.id)
      assertLoggedIn({ url: page.url, status: 200 }, page.html)
      body = capture.scrape(page.text, scrapedAt)
    }

    if (!body || body.__empty) {
      const error = new Error(`${capture.source}: nothing scraped from the page`)
      error.fatal = true
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
    error.fatal = true
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

  // A bids capture that "succeeds" with zero records may be legitimate (no
  // bids yet) or may mean the probe matched the wrong object — either way it
  // must be visible, not silently indistinguishable from a real success.
  if (capture.source === 'insights_bids' && Array.isArray(body.bids) && body.bids.length === 0) {
    warnings.push('Captured zero bids — this may be legitimate, but verify the probe matched the right object.')
  }

  const outcome = { strategy: probe.strategy, status: response.status, id: response.data?.id ?? null }
  if (loadTimedOut) outcome.loadTimedOut = true
  if (warnings.length) outcome.warnings = warnings
  return outcome
}

// Guards against overlapping runs: a second click, or the alarm firing
// mid-run, would otherwise race the `lastRun` write with whichever run
// finishes last silently winning.
let running = false

async function captureAll(filter = () => true) {
  if (running) return
  running = true

  try {
    const captures = CAPTURES.filter(filter)
    await setBadge('...', '#666666')

    // Merge into the previous run's results rather than overwrite: an hourly
    // bid run must not erase the last daily gamification/insights results from
    // the report the options page shows.
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
        report.results[capture.source] = { ok: true, ...outcome }
      } catch (error) {
        report.results[capture.source] = {
          ok: false,
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
  } finally {
    running = false
  }
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
