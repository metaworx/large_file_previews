import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TypesSection from './TypesSection.vue'
import { OURS, testState, withPdfOn } from './testState'
import type { ExtensionRow, SettingsState } from './types'

function section(state: SettingsState = testState()) {
	return mount(TypesSection, { props: { state, saving: false } })
}

function row(wrapper: ReturnType<typeof section>, selector: string) {
	return wrapper.find(selector)
}

/** The placeholders of a row's size fields, minimum first. */
function placeholders(wrapper: ReturnType<typeof section>, selector: string): unknown[] {
	const found: unknown[] = []
	for (const field of row(wrapper, selector).findAllComponents({ name: 'NumberField' })) {
		found.push(field.props('placeholder'))
	}
	return found
}

function tif(extra: Partial<ExtensionRow> = {}): ExtensionRow {
	return {
		extension: 'tif',
		mime: 'image/tiff',
		rule: { enabled: true, min_size_mb: 200 },
		renderer: { program: 'pdftoppm', available: true, unsupported: null },
		noEffect: false,
		...extra,
	}
}

describe('TypesSection', () => {
	it('gives a MIME type the command for what is stored for it, not the shipped default', () => {
		const command = (state: SettingsState) => row(section(state), '[data-mime="image/tiff"]').findComponent({ name: 'OccCommand' }).props('command')

		expect(command(testState())).toBe('occ lfp:config:unset --mime image/tiff')

		const state = testState()
		state.mimetypes[1].rule = { enabled: true, min_size_mb: 50 }
		state.mimetypes[1].stored = { enabled: true }

		expect(command(state)).toBe('occ lfp:config:set --mime image/tiff --enabled=yes')
	})

	it('shows in a MIME type\'s fields what is set for it, and what it follows where nothing is', () => {
		const state = testState()
		state.mimetypes[1].rule = { enabled: true, min_size_mb: 50, max_size_mb: 900 }
		state.mimetypes[1].stored = { enabled: true, max_size_mb: 900 }
		const fields = row(section(state), '[data-mime="image/tiff"]').findAllComponents({ name: 'NumberField' })

		expect(fields[0].props('value')).toBeNull()
		expect(fields[0].props('placeholder')).toBe('50 (inherited)')
		expect(fields[1].props('value')).toBe(900)
	})

	it('shows what a MIME type\'s empty fields inherit: what it ships with, else the default', () => {
		expect(placeholders(section(), '[data-mime="application/pdf"]')).toEqual(['0 (inherited)', 'no limit (inherited)'])
	})

	it('shows what an extension\'s empty fields inherit: its MIME type\'s size in force, else the default', () => {
		const state = testState()
		state.mimetypes[1].rule = { enabled: true, min_size_mb: 70 }
		state.extensions = [
			tif({ rule: { enabled: true } }),
			tif({ extension: '7z', mime: 'application/x-7z-compressed', rule: { enabled: true } }),
		]
		const wrapper = section(state)

		expect(placeholders(wrapper, '[data-extension="tif"]')).toEqual(['70 (inherited)', 'no limit (inherited)'])
		expect(placeholders(wrapper, '[data-extension="7z"]')).toEqual(['0 (inherited)', 'no limit (inherited)'])
	})

	it('keeps a rule\'s size labels off its fields, where they would cover the placeholder', () => {
		for (const field of row(section(), '[data-mime="application/pdf"]').findAllComponents({ name: 'NumberField' })) {
			expect(field.props('labelOutside')).toBe(true)
			expect(field.find('input').attributes('aria-label')).toBe(field.props('label'))
		}
	})

	it('gives an extension the command for its rule', () => {
		const state = testState()
		state.extensions = [tif()]

		expect(row(section(state), '[data-extension="tif"]').findComponent({ name: 'OccCommand' }).props('command'))
			.toBe('occ lfp:config:set --ext tif --enabled=yes --min=200')
	})

	it('has its title as an h3 and its parts as h4', () => {
		const wrapper = section()

		expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Which files'])
		expect(wrapper.findAll('h4').map((heading) => heading.text()))
			.toEqual(['What is processed', 'Defaults', 'PDF scanned pages', 'MIME types', 'Extensions'])
	})

	it('shows what is active, with each size\'s source, at the top', () => {
		const active = section(withPdfOn()).find('.lfp-active')

		expect(active.text()).toContain('application/pdf')
		expect(active.text()).toContain('pdftoppm')
		expect(active.text()).toContain('no limit (default)')
		expect(active.find('tbody tr').findComponent({ name: 'ProviderList' }).props('providers')).toEqual([OURS])
	})

	it('lists a MIME type\'s providers beneath its name, amber where one comes before this app', () => {
		const state = testState()
		state.mimetypes[0].providers = [
			{ name: 'ImaginaryPDF', class: 'OC\\Preview\\ImaginaryPDF', ours: false, before: true },
			{ ...OURS },
		]
		const providers = row(section(state), '[data-mime="application/pdf"] .lfp-rule-providers')

		expect(providers.text()).toContain('Providers:')
		expect(providers.findAll('.lfp-provider').map((name) => name.text())).toEqual(['ImaginaryPDF', 'Large File Previews'])
		expect(providers.find('.lfp-provider--before').attributes('title')).toBe('OC\\Preview\\ImaginaryPDF')
	})

	it('lists no providers for an extension: they are its MIME type\'s', () => {
		const state = testState()
		state.extensions = [tif()]

		expect(row(section(state), '[data-extension="tif"] .lfp-rule-providers').exists()).toBe(false)
	})

	it('names a missing renderer\'s program, with a badge saying it is not available', () => {
		const state = withPdfOn()
		state.active.push({
			mime: 'image/tiff',
			extension: 'tif',
			processing: 'enabled',
			renderer: { program: 'vipsthumbnail', available: false, unsupported: null },
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

	it('enables a MIME type, off as it ships, that field alone, and says so', async () => {
		const wrapper = section()
		const pdf = row(wrapper, '[data-mime="application/pdf"]')

		expect(pdf.text()).toContain('disabled')

		await pdf.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)

		expect(wrapper.emitted('save')).toEqual([[{ mimetypes: { 'application/pdf': { enabled: true } } }]])
		expect(row(section(withPdfOn()), '[data-mime="application/pdf"]').text()).toContain('enabled')
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

	it('says why libvips cannot decode a type, in its row and in the active table', () => {
		const state = withPdfOn()
		state.mimetypes[1].renderer = { program: 'vipsthumbnail', available: false, unsupported: { decoder: 'tiffload', problem: 'untrusted' } }
		state.active.push({
			mime: 'image/tiff',
			extension: null,
			processing: 'enabled',
			renderer: { program: 'vipsthumbnail', available: false, unsupported: { decoder: 'tiffload', problem: 'missing' } },
			min_size_mb: { value: 50, source: 'mime' },
			max_size_mb: { value: -1, source: 'default' },
		})
		const wrapper = section(state)

		expect(row(wrapper, '[data-mime="image/tiff"]').text()).toContain('Its decoder, tiffload, is untrusted, and untrusted decoders are blocked')
		expect(wrapper.findAll('.lfp-active tbody tr')[1].find('.lfp-badge').text()).toBe('no decoder')
	})

	it('says where libvips is too old to block untrusted decoders', () => {
		const state = withPdfOn()
		const unblockable = { program: 'vipsthumbnail', available: false, unsupported: { decoder: 'tiffload', problem: 'unblockable' as const } }
		state.mimetypes[1].renderer = unblockable
		state.active.push({
			mime: 'image/tiff',
			extension: null,
			processing: 'enabled',
			renderer: unblockable,
			min_size_mb: { value: 50, source: 'mime' },
			max_size_mb: { value: -1, source: 'default' },
		})
		const wrapper = section(state)

		expect(row(wrapper, '[data-mime="image/tiff"]').text()).toContain('older than 8.13, or did not report its version, and cannot block untrusted decoders')
		expect(wrapper.findAll('.lfp-active tbody tr')[1].find('.lfp-badge').text()).toBe('libvips too old')
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
		state.extensions = [tif({ extension: '7z', mime: 'application/x-7z-compressed', renderer: { program: null, available: false, unsupported: null } })]

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
