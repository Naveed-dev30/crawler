import { getConfig, setConfig } from './lib/config.js'

const apiBaseUrl = document.getElementById('apiBaseUrl')
const token = document.getElementById('token')
const status = document.getElementById('status')

const current = await getConfig()
apiBaseUrl.value = current.apiBaseUrl
token.value = current.token

document.getElementById('save').addEventListener('click', async () => {
  const url = apiBaseUrl.value.trim().replace(/\/+$/, '')

  let origin
  try {
    origin = new URL(url).origin + '/*'
  } catch (e) {
    status.textContent = 'Enter a full URL, e.g. https://crawler.example.com'
    return
  }

  // The API host is not in host_permissions — request it at save time.
  const granted = await chrome.permissions.request({ origins: [origin] })
  if (!granted) {
    status.textContent = 'Access to ' + origin + ' denied — captures cannot be posted.'
    return
  }

  await setConfig({ apiBaseUrl: url, token: token.value })
  status.textContent = 'Saved.'
  setTimeout(() => (status.textContent = ''), 2000)
})

const lastRunEl = document.getElementById('lastRun')
const { lastRun } = await chrome.storage.local.get({ lastRun: null })
lastRunEl.textContent = lastRun ? JSON.stringify(lastRun, null, 2) : 'Never run.'
