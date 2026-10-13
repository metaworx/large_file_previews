import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LimitsSection from './LimitsSection.vue'
import NumberField from './NumberField.vue'
import { testState } from './testState'

function section(state = testState()) {
	return mount(LimitsSection, { props: { state, saving: false } })
}

describe('LimitsSection', () => {
	it('shows each limit with its value and range', () => {
		const field = section().find('[data-limit="timeout_enabled"]').findAllComponents({ name: 'NumberField' })[0]

		expect(field.props('value')).toBe(30)
		expect(field.props('min')).toBe(1)
		expect(field.props('max')).toBe(600)
	})

	it('switches a limit off', async () => {
		const wrapper = section()

		await wrapper.find('[data-limit="prlimit_enabled"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { prlimit_enabled: false } }]])
	})

	it('saves a value under its own key', async () => {
		const wrapper = section()

		await wrapper.find('[data-limit="choom_enabled"]').findComponent({ name: 'NumberField' }).vm.$emit('save', 500)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { choom_adjust: 500 } }]])
	})

	it('leaves the values of a limit switched off alone', () => {
		const state = testState()
		state.scalars.timeout_enabled.value = false
		const fields = section(state).find('[data-limit="timeout_enabled"]').findAllComponents(NumberField)

		expect(fields).toHaveLength(2)
		for (const field of fields) {
			expect(field.props('disabled')).toBe(true)
		}
	})
})
