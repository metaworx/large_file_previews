import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
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

	it('follows the value the server answers', async () => {
		const { wrapper, input } = field()

		await wrapper.setProps({ value: 45 })

		expect(input().props('modelValue')).toBe('45')
	})
})
