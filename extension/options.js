import { getConfig, setConfig } from './lib/config.js'

const apiBaseUrl = document.getElementById('apiBaseUrl')
const token = document.getElementById('token')
const status = document.getElementById('status')

const current = await getConfig()
apiBaseUrl.value = current.apiBaseUrl
token.value = current.token

document.getElementById('save').addEventListener('click', async () => {
  await setConfig({ apiBaseUrl: apiBaseUrl.value, token: token.value })
  status.textContent = 'Saved.'
  setTimeout(() => (status.textContent = ''), 2000)
})
