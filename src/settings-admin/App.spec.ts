import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'
import { testState } from './testState'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => url,
}))

vi.mock('../toast', () => ({
	toastSaved: vi.fn(),
	toastError: vi.fn(),
}))

const fetchMock = vi.fn()

beforeEach(() => {
	fetchMock.mockReset()
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('App', () => {
	it('shows the three sections once the settings are loaded', async () => {
		fetchMock.mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve(testState()) })
		const wrapper = mount(App)

		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		await flushPromises()

		expect(wrapper.findAllComponents({ name: 'NcSettingsSection' })).toHaveLength(3)
	})

	it('says so when they cannot be loaded', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) })
		const wrapper = mount(App)
		await flushPromises()

		expect(wrapper.text()).toContain('The settings could not be loaded.')
		expect(wrapper.findAllComponents({ name: 'NcSettingsSection' })).toHaveLength(0)
	})
})
