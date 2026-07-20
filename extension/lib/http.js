import { getConfig } from './config.js'

export class MissingConfigError extends Error {
  constructor() {
    super('API base URL and ingest token must be set in the extension options.')
    this.name = 'MissingConfigError'
    // Missing config can never resolve itself between retries — only the user
    // filling in the options page fixes it, so retrying just wastes 42s.
    this.fatal = true
  }
}

export async function postCapture(path, body) {
  const { apiBaseUrl, token } = await getConfig()

  if (!apiBaseUrl || !token) throw new MissingConfigError()

  const response = await fetch(apiBaseUrl + path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: 'Bearer ' + token,
    },
    body: JSON.stringify(body),
  })

  let data = null
  try {
    data = await response.json()
  } catch (e) {
    data = null
  }

  return { ok: response.ok, status: response.status, data }
}
