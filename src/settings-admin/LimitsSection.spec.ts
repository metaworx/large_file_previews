import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import HelpPopover from './HelpPopover.vue'
import LimitsSection from './LimitsSection.vue'
import NumberField from './NumberField.vue'
import { testState } from './testState'

function section(state = testState()) {
	return mount(LimitsSection, { props: { state, saving: false } })
}

describe('LimitsSection', () => {
	it('shows each limit with its range, empty and its default the placeholder until it is set', () => {
		const field = section().find('[data-limit="timeout_enabled"]').findAllComponents({ name: 'NumberField' })[0]

		expect(field.props('value')).toBeNull()
		expect(field.props('placeholder')).toBe('30 (default)')
		expect(field.props('clearable')).toBe(true)
		expect(field.props('min')).toBe(1)
		expect(field.props('max')).toBe(600)

		const state = testState()
		state.scalars.timeout_seconds = { ...state.scalars.timeout_seconds, value: 45, stored: true }

		expect(section(state).find('[data-limit="timeout_enabled"]').findAllComponents({ name: 'NumberField' })[0].props('value')).toBe(45)
	})

	it('returns a limit to its default by removing its value', async () => {
		const state = testState()
		state.scalars.timeout_seconds = { ...state.scalars.timeout_seconds, value: 45, stored: true }
		const wrapper = section(state)
		const timeout = wrapper.find('[data-limit="timeout_enabled"]')

		expect(timeout.text()).toContain('occ lfp:config:set timeout_seconds 45')

		await timeout.findAllComponents({ name: 'NumberField' })[0].vm.$emit('save', null)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { timeout_seconds: null } }]])
		expect(section().find('[data-limit="timeout_enabled"]').text()).toContain('occ lfp:config:unset timeout_seconds')
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
		expect(caps.text()).toContain('occ lfp:config:unset prlimit_fsize_mb')

		await caps.findAllComponents(NumberField)[2].vm.$emit('save', 2048)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { prlimit_fsize_mb: 2048 } }]])
	})

	it('keeps what a limit does behind its help button, its fields labelled in MiB', () => {
		const caps = section().find('[data-limit="prlimit_enabled"]')

		expect(caps.findComponent(HelpPopover).props('label')).toBe('Address-space and file-size caps')
		expect(caps.text()).not.toContain('runaway allocation')
		const labels: unknown[] = []
		for (const field of caps.findAllComponents(NumberField)) {
			labels.push(field.props('label'))
		}

		expect(labels).toEqual(['Address space, MiB', 'libvips, MiB', 'Largest file, MiB'])
	})

	it('sets how long a file that failed is left alone', async () => {
		const wrapper = section()
		const retry = wrapper.find('[data-setting="failure_retry_minutes"]')

		expect(retry.findComponent(NumberField).props('placeholder')).toBe('60 (default)')
		expect(retry.text()).toContain('occ lfp:config:unset failure_retry_minutes')

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
