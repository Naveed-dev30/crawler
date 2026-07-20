import { CAPTURES } from './captures/index.js'
import { findPageData } from './lib/probe.js'
import { postCapture, MissingConfigError } from './lib/http.js'
import { assertLoggedIn, LoggedOutError } from './lib/session.js'
import { withRetry } from './lib/retry.js'

const ALARM = 'capture-all'
const PERIOD_MINUTES = 24 * 60
const SETTLE_MS = 2500
// A stalled navigation or redirect loop must never hang the run forever —
// silence is the worst possible outcome for a system whose whole point is to
// report back what happened on the first real click.
const LOAD_TIMEOUT_MS = 30000
// A login page's <head> (analytics bootstrapping, inline bundles) can run
// well past a few KB; truncating too early lets a login page evade the guard.
const LOGIN_GUARD_HTML_LIMIT = 50000

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create(ALARM, { periodInMinutes: PERIOD_MINUTES, delayInMinutes: 1 })
})

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === ALARM) captureAll()
})

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

async function openAndProbe(capture) {
  const tab = await chrome.tabs.create({ url: capture.url, active: false })

  try {
    // A page that never reports 'complete' (stalled navigation, redirect
    // loop) may still have usable data by now — proceed to probe either way,
    // but remember the timeout so it shows up in the report rather than
    // silently vanishing.
    const { timedOut: loadTimedOut } = await waitForTabComplete(tab.id, LOAD_TIMEOUT_MS)

    await new Promise((resolve) => setTimeout(resolve, SETTLE_MS))

    // Guard before probing: a login page has no data, and its diagnostics
    // would be a confusing red herring in the report.
    const [{ result: pageInfo }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: (limit) => ({ url: location.href, html: document.documentElement.outerHTML.slice(0, limit) }),
      args: [LOGIN_GUARD_HTML_LIMIT],
    })
    assertLoggedIn({ url: pageInfo.url, status: 200 }, pageInfo.html)

    // MAIN world is required: an isolated content script cannot see page globals.
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      world: 'MAIN',
      func: findPageData,
      args: [capture.requiredKeys, capture.probeOptions ?? {}],
    })

    return { result, loadTimedOut }
  } finally {
    await chrome.tabs.remove(tab.id)
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
  const { result: probe, loadTimedOut } = await openAndProbe(capture)

  if (!probe || !probe.strategy) {
    const error = new Error(`${capture.source}: page data not found`)
    error.fatal = true
    error.diagnostics = { ...(probe?.diagnostics ?? {}), loadTimedOut }
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

async function captureAll() {
  if (running) return
  running = true

  try {
    await setBadge('...', '#666666')
    const report = { startedAt: new Date().toISOString(), results: {} }

    for (const capture of CAPTURES) {
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

    const results = Object.values(report.results)
    const failed = results.filter((r) => !r.ok)
    const warned = results.filter((r) => r.ok && r.warnings?.length)
    await setBadge(failed.length ? String(failed.length) : 'ok', failed.length ? '#CC0000' : '#0A7F27')

    const titleParts = [failed.length ? `${failed.length}/${CAPTURES.length} captures failed` : 'All captures posted']
    if (warned.length) titleParts.push(`${warned.length} with warnings`)

    notify(titleParts.join(', '), summarize(report))
  } finally {
    running = false
  }
}

function summarize(report) {
  return Object.entries(report.results)
    .map(([source, r]) => {
      if (!r.ok) return `${source}: ${r.kind}`
      const warn = r.warnings?.length ? ` (warnings: ${r.warnings.length})` : ''
      return `${source}: ok via ${r.strategy}${warn}`
    })
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
