import { CAPTURES } from './captures/index.js'
import { findPageData } from './lib/probe.js'
import { postCapture, MissingConfigError } from './lib/http.js'
import { assertLoggedIn, LoggedOutError } from './lib/session.js'
import { withRetry } from './lib/retry.js'

const ALARM = 'capture-all'
const PERIOD_MINUTES = 24 * 60
const SETTLE_MS = 2500

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create(ALARM, { periodInMinutes: PERIOD_MINUTES, delayInMinutes: 1 })
})

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === ALARM) captureAll()
})

chrome.action.onClicked.addListener(() => captureAll())

async function openAndProbe(capture) {
  const tab = await chrome.tabs.create({ url: capture.url, active: false })

  try {
    await new Promise((resolve) => {
      const listener = (tabId, info) => {
        if (tabId === tab.id && info.status === 'complete') {
          chrome.tabs.onUpdated.removeListener(listener)
          resolve()
        }
      }
      chrome.tabs.onUpdated.addListener(listener)
    })

    await new Promise((resolve) => setTimeout(resolve, SETTLE_MS))

    // Guard before probing: a login page has no data, and its diagnostics would
    // be a confusing red herring in the report.
    const [{ result: pageInfo }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: () => ({ url: location.href, html: document.documentElement.outerHTML.slice(0, 4000) }),
    })
    assertLoggedIn({ url: pageInfo.url, status: 200 }, pageInfo.html)

    // MAIN world is required: an isolated content script cannot see page globals.
    const [{ result }] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      world: 'MAIN',
      func: findPageData,
      args: [capture.requiredKeys],
    })

    return result
  } finally {
    await chrome.tabs.remove(tab.id)
  }
}

async function runOne(capture) {
  const probe = await openAndProbe(capture)

  if (!probe || !probe.strategy) {
    const error = new Error(`${capture.source}: page data not found`)
    error.fatal = true
    error.diagnostics = probe?.diagnostics ?? null
    throw error
  }

  const scrapedAt = new Date().toISOString()
  const body = capture.normalize(probe.data, scrapedAt)
  const response = await postCapture(capture.path, body)

  if (!response.ok) {
    const error = new Error(`${capture.source}: API returned ${response.status}`)
    error.fatal = response.status === 401 || response.status === 422
    throw error
  }

  return { strategy: probe.strategy, status: response.status, id: response.data?.id ?? null }
}

async function captureAll() {
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

  const failed = Object.values(report.results).filter((r) => !r.ok)
  await setBadge(failed.length ? String(failed.length) : 'ok', failed.length ? '#CC0000' : '#0A7F27')

  notify(
    failed.length ? `${failed.length}/${CAPTURES.length} captures failed` : 'All captures posted',
    summarize(report)
  )
}

function summarize(report) {
  return Object.entries(report.results)
    .map(([source, r]) => (r.ok ? `${source}: ok via ${r.strategy}` : `${source}: ${r.kind}`))
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
