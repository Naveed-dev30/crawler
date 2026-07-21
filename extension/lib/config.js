const DEFAULTS = { apiBaseUrl: '', token: '' }

export async function getConfig() {
  const stored = await chrome.storage.local.get(DEFAULTS)
  return { apiBaseUrl: stored.apiBaseUrl ?? '', token: stored.token ?? '' }
}

export async function setConfig({ apiBaseUrl, token }) {
  await chrome.storage.local.set({
    apiBaseUrl: String(apiBaseUrl ?? '').replace(/\/+$/, ''),
    token: String(token ?? ''),
  })
}
