import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AdvancedSection from './AdvancedSection.vue'
import OccCommand from './OccCommand.vue'
import { testState } from './testState'

function section(state = testState()) {
	return mount(AdvancedSection, { props: { state, saving: false } })
}

describe('AdvancedSection', () => {
	it('switches the untrusted decoders\' block', async () => {
		const wrapper = section()

		await wrapper.find('[data-setting="vips_block_untrusted"]').findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { vips_block_untrusted: false } }]])
	})

	it('shows each program\'s path, version and the command that sets it', () => {
		const row = section().find('[data-program="pdftoppm"]')

		expect(row.text()).toContain('/usr/bin/pdftoppm')
		expect(row.text()).toContain('pdftoppm version 24.02.0')
		expect(row.findComponent(OccCommand).props('command')).toBe('occ lfp:config:set binaries.pdftoppm /usr/bin/pdftoppm')
	})

	it('says a program is not found, and still how to set it', () => {
		const row = section().find('[data-program="vipsthumbnail"]')

		expect(row.text()).toContain('not found')
		expect(row.findComponent(OccCommand).props('command')).toBe('occ lfp:config:set binaries.vipsthumbnail /path/to/vipsthumbnail')
	})

	it('shows the scratch directory, read only', () => {
		const wrapper = section()

		expect(wrapper.find('[data-setting="scratch_directory"]').text()).toContain('/var/tmp/nextcloud (Nextcloud\'s temporary directory)')
		expect(wrapper.findAll('input:not([type="checkbox"])').map((input) => input.attributes('id'))).toEqual(['lfp-vips-concurrency'])
	})

	it('asks for the programs to be found again', async () => {
		const wrapper = section()

		await wrapper.find('.lfp-refresh').trigger('click')

		expect(wrapper.emitted('refresh')).toHaveLength(1)
	})
})

describe('OccCommand', () => {
	it('copies its command', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })

		const wrapper = mount(OccCommand, { props: { command: 'occ lfp:config:set timeout_seconds 30' } })
		await wrapper.find('button').trigger('click')

		expect(wrapper.text()).toContain('occ lfp:config:set timeout_seconds 30')
		expect(writeText).toHaveBeenCalledWith('occ lfp:config:set timeout_seconds 30')
	})
})
