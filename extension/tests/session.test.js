import { test } from 'node:test'
import assert from 'node:assert/strict'
import { assertLoggedIn, LoggedOutError } from '../lib/session.js'

test('passes through a normal JSON response', () => {
  const response = { url: 'https://www.freelancer.com/api/insights', status: 200 }
  assert.doesNotThrow(() => assertLoggedIn(response, '{"data":1}'))
})

test('throws when redirected to a login URL', () => {
  const response = { url: 'https://www.freelancer.com/login?next=/insights', status: 200 }
  assert.throws(() => assertLoggedIn(response, '{}'), LoggedOutError)
})

test('throws when redirected to a signup URL', () => {
  const response = { url: 'https://www.freelancer.com/signup', status: 200 }
  assert.throws(() => assertLoggedIn(response, '{}'), LoggedOutError)
})

test('throws on a 401 status', () => {
  const response = { url: 'https://www.freelancer.com/api/insights', status: 401 }
  assert.throws(() => assertLoggedIn(response, '{}'), LoggedOutError)
})

test('throws on a 403 status', () => {
  const response = { url: 'https://www.freelancer.com/api/insights', status: 403 }
  assert.throws(() => assertLoggedIn(response, '{}'), LoggedOutError)
})

test('throws when the body is a login form instead of data', () => {
  const response = { url: 'https://www.freelancer.com/users/game/', status: 200 }
  const html = '<html><body><form action="/login"><input name="password"></form></body></html>'
  assert.throws(() => assertLoggedIn(response, html), LoggedOutError)
})

test('does not false-positive on data mentioning the word login', () => {
  const response = { url: 'https://www.freelancer.com/api/insights', status: 200 }
  assert.doesNotThrow(() => assertLoggedIn(response, '{"last_login":"2026-07-20"}'))
})

// The discriminating case for path-anchoring: the OLD raw-URL regex matched
// "freelancer.com/login" anywhere in the string, including inside a query
// parameter, and wrongly flagged this authenticated request as logged out.
// (A bare "?next=/login" does NOT discriminate — the old regex required
// "freelancer.com/" immediately followed by "login", so it never matched that.)
test('does not false-positive on a login URL embedded in a query parameter', () => {
  const response = {
    url: 'https://www.freelancer.com/api/insights?redirect=https://www.freelancer.com/login',
    status: 200,
  }
  assert.doesNotThrow(() => assertLoggedIn(response, '{"data":1}'))
})

test('throws when redirected to a signin URL', () => {
  const response = { url: 'https://www.freelancer.com/signin', status: 200 }
  assert.throws(() => assertLoggedIn(response, '{}'), LoggedOutError)
})

test('tolerates a missing url or body without throwing a TypeError', () => {
  assert.doesNotThrow(() => assertLoggedIn({ status: 200 }, undefined))
})
