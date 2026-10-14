import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import HelpPopover from './HelpPopover.vue'
import NumberField from './NumberField.vue'

function field(props: Record<string, unknown> = {}) {
	const wrapper = mount(NumberField, { props: { id: 'f', label: 'Seconds', value: 30, ...props } })
	const input = () => wrapper.findComponent({ name: 'NcTextField' })
	const button = () => wrapper.findComponent({ name: 'NcButton' })
	return { wrapper, input, button }
}

describe('NumberField', () => {
	it('can be saved only once the number changes', async () => {
		const { wrapper, input, button } = field()

		expect(button().props('disabled')).toBe(true)

		await input().vm.$emit('update:modelValue', '60')
		expect(button().props('disabled')).toBe(false)

		await button().vm.$emit('click')
		expect(wrapper.emitted('save')).toEqual([[60]])
	})

	it('does not save what is not a whole number, and marks it', async () => {
		const { wrapper, input, button } = field()

		await input().vm.$emit('update:modelValue', '1.5')

		expect(button().props('disabled')).toBe(true)
		expect(input().props('error')).toBe(true)
		await button().vm.$emit('click')
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('saves an emptied field as null where it may be cleared', async () => {
		const { wrapper, input, button } = field({ clearable: true, value: 50 })

		await input().vm.$emit('update:modelValue', '')
		await button().vm.$emit('click')

		expect(wrapper.emitted('save')).toEqual([[null]])
	})

	it('does not let an empty field through where it may not be cleared', async () => {
		const { input, button } = field()

		await input().vm.$emit('update:modelValue', '')

		expect(button().props('disabled')).toBe(true)
	})

	it('shows what applies where it is empty as its placeholder, and its help after the button', () => {
		const { wrapper, input } = field({ value: null, clearable: true, placeholder: '50', help: 'What it does.' })

		expect(input().props('modelValue')).toBe('')
		expect(input().props('placeholder')).toBe('50')

		const help = wrapper.findComponent(HelpPopover)
		expect(help.props('text')).toBe('What it does.')
		expect(help.props('label')).toBe('Seconds')
		// After the Save button, in the field's own row.
		expect(wrapper.element.lastElementChild?.classList.contains('lfp-help')).toBe(true)
	})

	it('has no help button without a help text', () => {
		expect(field().wrapper.findComponent(HelpPopover).exists()).toBe(false)
	})

	it('follows the value the server answers', async () => {
		const { wrapper, input } = field()

		await wrapper.setProps({ value: 45 })

		expect(input().props('modelValue')).toBe('45')
	})
})
