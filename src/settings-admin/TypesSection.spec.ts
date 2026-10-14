import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TypesSection from './TypesSection.vue'
import { testState } from './testState'
import type { ExtensionRow, SettingsState } from './types'

function section(state: SettingsState = testState()) {
	return mount(TypesSection, { props: { state, saving: false } })
}

function row(wrapper: ReturnType<typeof section>, selector: string) {
	return wrapper.find(selector)
}

function tif(extra: Partial<ExtensionRow> = {}): ExtensionRow {
	return {
		extension: 'tif',
		mime: 'image/tiff',
		rule: { enabled: true, min_size_mb: 200 },
		renderer: { program: 'pdftoppm', available: true },
		noEffect: false,
		...extra,
	}
}

describe('TypesSection', () => {
	it('has its title as an h3 and its parts as h4', () => {
		const wrapper = section()

		expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Which files'])
		expect(wrapper.findAll('h4').map((heading) => heading.text()))
			.toEqual(['Active rendering', 'Defaults', 'PDF scanned pages', 'MIME types', 'Extensions'])
	})

	it('shows what is active, with each size\'s source, at the top', () => {
		const active = section().find('.lfp-active')

		expect(active.text()).toContain('application/pdf')
		expect(active.text()).toContain('pdftoppm')
		expect(active.text()).toContain('no limit (default)')
	})

	it('names a missing renderer\'s program, with a badge saying it is not available', () => {
		const state = testState()
		state.active.push({
			mime: 'image/tiff',
			extension: 'tif',
			processing: 'enabled',
			renderer: { program: 'vipsthumbnail', available: false },
			min_size_mb: { value: 200, source: 'ext' },
			max_size_mb: { value: -1, source: 'default' },
		})
		const rows = section(state).findAll('.lfp-active tbody tr')

		expect(rows[0].find('.lfp-badge').exists()).toBe(false)
		expect(rows[1].text()).toContain('vipsthumbnail')
		expect(rows[1].find('.lfp-badge').text()).toBe('not available')
	})

	it('switches the scan sizing off, and sets its coverage', async () => {
		const wrapper = section()
		const scan = row(wrapper, '[data-setting="scan"]')

		await scan.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)
		await scan.findComponent({ name: 'NumberField' }).vm.$emit('save', 95)

		expect(wrapper.emitted('save')).toEqual([
			[{ scalars: { scan_native_enabled: false } }],
			[{ scalars: { scan_coverage_percent: 95 } }],
		])
	})

	it('enables a MIME type, that field alone, and says so', async () => {
		const wrapper = section()
		const pdf = row(wrapper, '[data-mime="application/pdf"]')

		expect(pdf.text()).toContain('enabled')

		await pdf.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)

		expect(wrapper.emitted('save')).toEqual([[{ mimetypes: { 'application/pdf': { enabled: false } } }]])
	})

	it('shows only what is set at the level, nothing inherited', () => {
		const fields = row(section(), '[data-mime="application/pdf"]').findAllComponents({ name: 'NumberField' })

		expect(fields).toHaveLength(2)
		for (const field of fields) {
			expect(field.props('value')).toBeNull()
		}
	})

	it('shows a type without a usable renderer, and does not let it change', () => {
		const tiff = row(section(), '[data-mime="image/tiff"]')

		expect(tiff.text()).toContain('No suitable renderer (vipsthumbnail)')
		expect(tiff.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('disabled')).toBe(true)
		for (const field of tiff.findAllComponents({ name: 'NumberField' })) {
			expect(field.props('disabled')).toBe(true)
		}
	})

	it('offers a reset only where a type differs from what it ships with', async () => {
		const state = testState()
		state.mimetypes[0].rule = { enabled: true, max_size_mb: 900 }
		const wrapper = section(state)

		expect(row(wrapper, '[data-mime="image/tiff"] .lfp-reset').exists()).toBe(false)

		await row(wrapper, '[data-mime="application/pdf"] button.lfp-reset').trigger('click')
		expect(wrapper.emitted('save')).toEqual([[{ mimetypes: { 'application/pdf': null } }]])
	})

	it('says an extension is enabled or excluded, or follows its MIME type', () => {
		const state = testState()
		state.extensions = [
			tif(),
			tif({ extension: 'tiff', rule: { enabled: false } }),
			tif({ extension: 'tf', rule: { max_size_mb: 10 } }),
		]
		const wrapper = section(state)

		expect(row(wrapper, '[data-extension="tif"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).text()).toBe('enabled')
		expect(row(wrapper, '[data-extension="tiff"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).text()).toBe('excluded')
		expect(row(wrapper, '[data-extension="tf"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).text()).toBe('as MIME type')
	})

	it('excludes an extension', async () => {
		const state = testState()
		state.extensions = [tif()]
		const wrapper = section(state)

		await row(wrapper, '[data-extension="tif"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)

		expect(wrapper.emitted('save')).toEqual([[{ extensions: { tif: { enabled: false } } }]])
	})

	it('says a type the app never handles is not one, not that it lacks a renderer', () => {
		const state = testState()
		state.extensions = [tif({ extension: '7z', mime: 'application/x-7z-compressed', renderer: { program: null, available: false } })]

		const row7z = row(section(state), '[data-extension="7z"]')

		expect(row7z.text()).toContain('Not a type this app handles')
		expect(row7z.text()).not.toContain('vipsthumbnail')
	})

	it('marks an extension that changes nothing', () => {
		const state = testState()
		state.extensions = [tif({ extension: 'pdf', mime: 'application/pdf', rule: { enabled: true }, noEffect: true })]

		expect(row(section(state), '[data-extension="pdf"] .lfp-no-effect').text())
			.toBe('No effect: its MIME type already selects it')
	})

	it('adds an extension, lower-cased and without its dot, enabled', async () => {
		const wrapper = section()

		await wrapper.find('#lfp-new-extension').setValue('.TIF')
		await wrapper.find('form').trigger('submit')

		expect(wrapper.emitted('save')).toEqual([[{ extensions: { tif: { enabled: true } } }]])
	})

	it('removes an extension with null', async () => {
		const state = testState()
		state.extensions = [tif()]
		const wrapper = section(state)
		const extension = row(wrapper, '[data-extension="tif"]')

		expect(extension.findAllComponents({ name: 'NumberField' })[0].props('value')).toBe(200)

		await extension.find('button.lfp-remove').trigger('click')
		expect(wrapper.emitted('save')).toEqual([[{ extensions: { tif: null } }]])
	})
})
