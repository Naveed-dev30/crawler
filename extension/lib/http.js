import { getConfig } from './config.js'

export class MissingConfigError extends Error {
  constructor() {
    super('API base URL and ingest token must be set in the extension options.')
    this.name = 'MissingConfigError'
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
