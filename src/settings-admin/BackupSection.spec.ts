import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import BackupSection from './BackupSection.vue'
import { testState } from './testState'
import type { ImportAnswer } from './types'

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string) => '/ocs/v2.php' + url,
}))

const toastSaved = vi.fn()
vi.mock('../toast', () => ({
	toastSaved: (...args: unknown[]) => toastSaved(...args),
	toastError: vi.fn(),
}))

const fetchMock = vi.fn()

function response(body: unknown, status = 200): Response {
	return { ok: status < 400, status, json: () => Promise.resolve(body) } as unknown as Response
}

function answer(extra: Partial<ImportAnswer> = {}): ImportAnswer {
	return {
		exported: { version: '0.1.0', date: '2026-10-17T02:18:00+00:00' },
		warnings: [{ kind: 'setting', name: 'speed' }],
		changes: [
			{ part: 'settings', name: 'timeout_seconds', before: null, after: 60 },
			{ part: 'mimetypes', name: 'image/tiff', before: { enabled: true }, after: { enabled: true, min_size_mb: 800 } },
			{ part: 'system', name: 'binaries.pdftoppm', before: null, after: '/opt/poppler/bin/pdftoppm' },
		],
		applied: false,
		...extra,
	}
}

/** The section, a file chosen in it, and what the server was asked. */
async function chosen(text = '{"app":"large_file_previews","format":1}') {
	const wrapper = mount(BackupSection, { props: { saving: false } })
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: [{ name: 'backup.json', text: () => Promise.resolve(text) }], configurable: true })
	await input.trigger('change')
	await flushPromises()
	return wrapper
}

function sent(call: number): Record<string, unknown> {
	return JSON.parse(fetchMock.mock.calls[call][1].body as string) as Record<string, unknown>
}

beforeEach(() => {
	document.head.dataset.requesttoken = 'token'
	toastSaved.mockReset()
	fetchMock.mockReset()
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('BackupSection', () => {
	it('downloads what is stored, named after the server and the day', async () => {
		fetchMock.mockResolvedValue(response({ app: 'large_file_previews', format: 1 }))
		vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL: vi.fn() })
		const names: string[] = []
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function(this: HTMLAnchorElement) {
			names.push(this.download)
		})
		const wrapper = mount(BackupSection, { props: { saving: false } })

		await wrapper.find('.lfp-backup__download').trigger('click')
		await flushPromises()

		expect(fetchMock.mock.calls[0][0]).toBe('/ocs/v2.php/apps/large_file_previews/settings/export')
		expect(names).toHaveLength(1)
		expect(names[0]).toMatch(/^large_file_previews-config\.[^.]+.*\.\d{4}-\d{2}-\d{2}\.json$/)
	})

	it('shows what a file would change, what it leaves out, and config.php\'s commands, and writes nothing', async () => {
		fetchMock.mockResolvedValue(response(answer()))
		const wrapper = await chosen()

		expect(sent(0)).toEqual({ document: '{"app":"large_file_previews","format":1}', replace: false, apply: false })
		expect(wrapper.text()).toContain('Written by version 0.1.0 on 2026-10-17T02:18:00+00:00.')
		expect(wrapper.find('.lfp-backup__warnings').text()).toBe('Setting speed: not one this app has; left out.')

		const rows = wrapper.findAll('.lfp-backup__changes tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
		expect(rows).toEqual([
			['Setting timeout_seconds', 'default', '60'],
			['MIME type image/tiff', 'enabled: true', 'enabled: true, min_size_mb: 800'],
		])

		const commands: unknown[] = []
		for (const command of wrapper.findAllComponents({ name: 'OccCommand' })) {
			commands.push(command.props('command'))
		}
		expect(commands).toContain('occ lfp:config:set binaries.pdftoppm /opt/poppler/bin/pdftoppm')
	})

	it('reads it again to replace, and applies it on Apply', async () => {
		fetchMock.mockResolvedValue(response(answer()))
		const wrapper = await chosen()

		await wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(sent(1)).toMatchObject({ replace: true, apply: false })

		fetchMock.mockResolvedValue(response(answer({ applied: true, state: testState() })))
		await wrapper.find('.lfp-backup__apply').trigger('click')
		await flushPromises()

		expect(sent(2)).toMatchObject({ replace: true, apply: true })
		expect(toastSaved).toHaveBeenCalledWith('Configuration imported.')
		expect(wrapper.find('.lfp-backup__read').exists()).toBe(false)
	})

	it('says why a file is refused', async () => {
		fetchMock.mockResolvedValue(response({ error: 'The file is not JSON.' }, 400))
		const wrapper = await chosen('{')

		expect(wrapper.find('.lfp-backup__refused').text()).toBe('backup.json: The file is not JSON.')
		expect(wrapper.find('.lfp-backup__read').exists()).toBe(false)
	})

	it('offers Apply only where the file changes the app\'s settings', async () => {
		fetchMock.mockResolvedValue(response(answer({ warnings: [], changes: [{ part: 'system', name: 'scratch_directory', before: null, after: '/var/cache/lfp' }] })))
		const wrapper = await chosen()

		expect(wrapper.find('.lfp-backup__nothing').exists()).toBe(true)
		expect(wrapper.find('button.lfp-backup__apply').attributes('disabled')).toBeDefined()
	})
})
