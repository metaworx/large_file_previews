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

	it('names the caps as what they cap, the file-size cap among them', async () => {
		const wrapper = section()
		const caps = wrapper.find('[data-limit="prlimit_enabled"]')

		expect(caps.text()).toContain('Address-space and file-size caps')
		expect(caps.text()).toContain('occ lfp:config:set prlimit_fsize_mb 1024')

		await caps.findAllComponents(NumberField)[2].vm.$emit('save', 2048)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { prlimit_fsize_mb: 2048 } }]])
	})

	it('sets how long a file that failed is left alone', async () => {
		const wrapper = section()
		const retry = wrapper.find('[data-setting="failure_retry_minutes"]')

		expect(retry.findComponent(NumberField).props('value')).toBe(60)
		expect(retry.text()).toContain('occ lfp:config:set failure_retry_minutes 60')

		await retry.findComponent(NumberField).vm.$emit('save', 0)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { failure_retry_minutes: 0 } }]])
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
