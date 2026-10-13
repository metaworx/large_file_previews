import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TypesSection from './TypesSection.vue'
import { testState } from './testState'
import type { SettingsState } from './types'

function section(state: SettingsState = testState()) {
	return mount(TypesSection, { props: { state, saving: false } })
}

function row(wrapper: ReturnType<typeof section>, selector: string) {
	return wrapper.find(selector)
}

describe('TypesSection', () => {
	it('switches a MIME type on, that field alone', async () => {
		const wrapper = section()

		await row(wrapper, '[data-mime="image/tiff"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)

		expect(wrapper.emitted('save')).toEqual([[{ mimetypes: { 'image/tiff': { enabled: true } } }]])
	})

	it('shows a size not set at this level as empty, with what applies instead', () => {
		const field = row(section(), '[data-mime="application/pdf"]').findAllComponents({ name: 'NumberField' })[0]

		expect(field.props('value')).toBeNull()
		expect(field.props('placeholder')).toBe('0 (from the default)')
	})

	it('clears a size with null', async () => {
		const wrapper = section()

		await row(wrapper, '[data-mime="image/tiff"]').findAllComponents({ name: 'NumberField' })[0].vm.$emit('save', null)

		expect(wrapper.emitted('save')).toEqual([[{ mimetypes: { 'image/tiff': { min_size_mb: null } } }]])
	})

	it('offers a reset only where a type differs from what it ships with', async () => {
		const state = testState()
		state.mimetypes[1].rule = { enabled: true, min_size_mb: 50 }
		const wrapper = section(state)

		expect(row(wrapper, '[data-mime="application/pdf"] .lfp-reset').exists()).toBe(false)

		await row(wrapper, '[data-mime="image/tiff"] button.lfp-reset').trigger('click')
		expect(wrapper.emitted('save')).toEqual([[{ mimetypes: { 'image/tiff': null } }]])
	})

	it('says which types are not rendered yet', () => {
		const wrapper = section()

		expect(row(wrapper, '[data-mime="image/tiff"]').text()).toContain('not rendered yet')
		expect(row(wrapper, '[data-mime="application/pdf"]').text()).not.toContain('not rendered yet')
	})

	it('adds an extension, lower-cased and without its dot, switched on', async () => {
		const wrapper = section()

		await wrapper.find('#lfp-new-extension').setValue('.TIF')
		await wrapper.find('form').trigger('submit')

		expect(wrapper.emitted('save')).toEqual([[{ extensions: { tif: { enabled: true } } }]])
	})

	it('removes an extension with null', async () => {
		const state = testState()
		state.extensions = [{
			extension: 'tif',
			rule: { min_size_mb: 200 },
			resolved: { ...state.mimetypes[1].resolved, extension: 'tif', min_size_mb: { value: 200, source: 'ext' } },
		}]
		const wrapper = section(state)
		const extension = row(wrapper, '[data-extension="tif"]')

		expect(extension.findAllComponents({ name: 'NumberField' })[0].props('value')).toBe(200)

		await extension.find('button.lfp-remove').trigger('click')
		expect(wrapper.emitted('save')).toEqual([[{ extensions: { tif: null } }]])
	})
})
