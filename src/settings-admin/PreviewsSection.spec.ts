import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import OccCommand from './OccCommand.vue'
import PreviewsSection from './PreviewsSection.vue'
import { testState } from './testState'

function section(state = testState()) {
	return mount(PreviewsSection, { props: { state, saving: false } })
}

describe('PreviewsSection', () => {
	it('offers each format, the one set chosen', () => {
		const radios = section().find('[data-setting="preview_format"]').findAllComponents(NcCheckboxRadioSwitch)
		const values: unknown[] = []
		for (const radio of radios) {
			values.push(radio.props('value'))
		}

		expect(values).toEqual(['nextcloud', 'png', 'jpeg', 'webp'])
		expect(radios[0].props('modelValue')).toBe('nextcloud')
		// Short; what each means is behind the heading's help button.
		expect(radios[0].text()).toBe('As Nextcloud\'s preview_format')
		expect(radios[3].text()).toBe('WebP')
	})

	it('offers the choices the server sends, one it has no words for as it is', () => {
		const state = testState()
		state.scalars.preview_format.choices = ['nextcloud', 'avif']
		const radios = section(state).find('[data-setting="preview_format"]').findAllComponents(NcCheckboxRadioSwitch)

		expect(radios).toHaveLength(2)
		expect(radios[1].text()).toBe('avif')
	})

	it('saves the format chosen', async () => {
		const wrapper = section()

		await wrapper.find('[data-setting="preview_format"]').findAllComponents({ name: 'NcCheckboxRadioSwitch' })[3].vm.$emit('update:modelValue', 'webp')

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { preview_format: 'webp' } }]])
	})

	it('shows the command for each setting, Nextcloud\'s own among them', () => {
		const commands = section().findAllComponents(OccCommand).map((command) => command.props('command'))

		expect(commands).toEqual([
			'occ lfp:config:set preview_format nextcloud',
			'occ lfp:config:set preview_background white',
			'occ config:system:set preview_format --value=jpeg',
			'occ config:app:set preview jpeg_quality --value=80',
			'occ config:app:set preview webp_quality --value=80',
		])
	})

	it('shows Nextcloud\'s own preview settings, read only', () => {
		const state = testState()
		state.nextcloud = { preview_format: 'webp', jpeg_quality: 90, webp_quality: 65 }
		const own = section(state).find('[data-setting="nextcloud"]')

		expect(own.find('[data-nextcloud="preview_format"]').text()).toContain('Preview format: webp')
		expect(own.find('[data-nextcloud="webp_quality"]').text()).toContain('WebP quality: 65')
		expect(own.find('input').exists()).toBe(false)
	})
})
