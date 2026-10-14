import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettings } from './useSettings'
import { testState } from './testState'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => '/ocs/v2.php' + url,
}))

const toastSaved = vi.fn()
const toastError = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: (...args: unknown[]) => toastError(...args),
}))

const fetchMock = vi.fn()

function response(body: unknown, status = 200): Response {
	return { ok: status < 400, status, json: () => Promise.resolve(body) } as unknown as Response
}

beforeEach(() => {
	// Where @nextcloud/auth reads it, as Nextcloud's page sets it.
	document.head.dataset.requesttoken = 'token'
	toastSaved.mockReset()
	toastError.mockReset()
	fetchMock.mockReset()
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('useSettings', () => {
	it('loads the settings', async () => {
		fetchMock.mockResolvedValue(response(testState()))
		const { state, failed, load } = useSettings()

		await load()

		expect(fetchMock.mock.calls[0][0]).toBe('/ocs/v2.php/apps/large_file_previews/settings')
		expect(fetchMock.mock.calls[0][1].headers.requesttoken).toBe('token')
		expect(state.value?.scalars.timeout_seconds.value).toBe(30)
		expect(failed.value).toBe(false)
	})

	it('says when they could not be loaded', async () => {
		fetchMock.mockResolvedValue(response({}, 500))
		const { failed, load } = useSettings()

		await load()

		expect(failed.value).toBe(true)
	})

	it('sends one change with the request token, and shows what the server answers', async () => {
		const answered = testState()
		answered.scalars.timeout_seconds.value = 60
		fetchMock.mockResolvedValue(response(answered))
		const { state, save } = useSettings()

		expect(await save({ scalars: { timeout_seconds: 60 } })).toBe(true)

		const [, init] = fetchMock.mock.calls[0]
		expect(init.method).toBe('PUT')
		expect(init.headers.requesttoken).toBe('token')
		expect(JSON.parse(init.body)).toEqual({ scalars: { timeout_seconds: 60 } })
		expect(state.value?.scalars.timeout_seconds.value).toBe(60)
		expect(toastSaved).toHaveBeenCalledOnce()
	})

	it('shows the server\'s reason for a refusal, and keeps the state', async () => {
		fetchMock.mockResolvedValueOnce(response(testState()))
		const { state, load, save } = useSettings()
		await load()
		fetchMock.mockResolvedValueOnce(response({ error: 'timeout_seconds must be between 1 and 600.' }, 400))

		expect(await save({ scalars: { timeout_seconds: 900 } })).toBe(false)

		expect(toastError).toHaveBeenCalledWith('timeout_seconds must be between 1 and 600.')
		expect(state.value?.scalars.timeout_seconds.value).toBe(30)
	})

	it('shows the state a refusal carries, as the server has it', async () => {
		fetchMock.mockResolvedValueOnce(response(testState()))
		const { state, load, save } = useSettings()
		await load()
		const current = testState()
		current.scalars.timeout_seconds.value = 45
		fetchMock.mockResolvedValueOnce(response({ error: 'refused', state: current }, 400))

		expect(await save({ scalars: { timeout_seconds: 900 } })).toBe(false)

		expect(state.value?.scalars.timeout_seconds.value).toBe(45)
	})

	it('finds the programs again, and says the server\'s reason where it cannot', async () => {
		fetchMock.mockResolvedValueOnce(response(testState()))
		const { refresh } = useSettings()

		expect(await refresh()).toBe(true)
		expect(fetchMock.mock.calls[0][0]).toBe('/ocs/v2.php/apps/large_file_previews/settings/refresh')
		expect(fetchMock.mock.calls[0][1].method).toBe('POST')
		expect(toastSaved).toHaveBeenCalledWith('Programs found again.')

		fetchMock.mockResolvedValueOnce(response({ error: 'No permission.' }, 403))

		expect(await refresh()).toBe(false)
		expect(toastError).toHaveBeenCalledWith('No permission.')
	})

	it('says so when the server cannot be reached', async () => {
		fetchMock.mockRejectedValue(new Error('offline'))
		const { save, saving } = useSettings()

		expect(await save({ scalars: { timeout_seconds: 60 } })).toBe(false)
		expect(toastError).toHaveBeenCalledWith('The setting could not be saved.')
		expect(saving.value).toBe(false)
	})
})
