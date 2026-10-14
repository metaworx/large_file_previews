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
	})

	it('saves the format chosen', async () => {
		const wrapper = section()

		await wrapper.find('[data-setting="preview_format"]').findAllComponents({ name: 'NcCheckboxRadioSwitch' })[3].vm.$emit('update:modelValue', 'webp')

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { preview_format: 'webp' } }]])
	})

	it('shows the command for each setting', () => {
		const commands = section().findAllComponents(OccCommand).map((command) => command.props('command'))

		expect(commands).toEqual(['occ lfp:config:set preview_format nextcloud', 'occ lfp:config:set preview_background white'])
	})
})
