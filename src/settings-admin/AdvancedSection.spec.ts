import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AdvancedSection from './AdvancedSection.vue'
import OccCommand from './OccCommand.vue'
import { cellLabels, testState } from './testState'

function section(state = testState()) {
	return mount(AdvancedSection, { props: { state, saving: false } })
}

describe('AdvancedSection', () => {
	it('labels each program\'s cells with their column, for a row stacked on a narrow page', () => {
		const { headers, rows } = cellLabels(section().find('.lfp-programs').element)

		expect(headers).toEqual([null, 'Path', 'Found by', 'Version'])
		expect(rows.length).toBeGreaterThan(0)
		for (const row of rows) {
			expect(row).toEqual(headers)
		}
	})

	it('shows the untrusted decoders read only, with the command that changes them', () => {
		const blocked = section().find('[data-setting="vips_allow_untrusted"]')

		expect(blocked.text()).toContain('Untrusted decoders: blocked')
		expect(blocked.find('input').exists()).toBe(false)
		expect(blocked.findComponent(OccCommand).props('command')).toBe('occ lfp:config:set vips_allow_untrusted true')

		const state = testState()
		state.untrusted = { allowed: true, blockable: true }
		const allowed = section(state).find('[data-setting="vips_allow_untrusted"]')

		expect(allowed.text()).toContain('Untrusted decoders: allowed')
		expect(allowed.findComponent(OccCommand).props('command')).toBe('occ lfp:config:unset vips_allow_untrusted')
	})

	it('warns where libvips cannot block them', () => {
		expect(section().find('[data-setting="vips_allow_untrusted"]').text()).not.toContain('older than 8.13')

		const state = testState()
		state.untrusted = { allowed: false, blockable: false }

		expect(section(state).find('[data-setting="vips_allow_untrusted"]').text()).toContain('older than 8.13')
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
		const commands: unknown[] = []
		for (const command of row.findAllComponents(OccCommand)) {
			commands.push(command.props('command'))
		}

		expect(commands).toEqual(['apt install libvips-tools', 'occ lfp:config:set binaries.vipsthumbnail /path/to/vipsthumbnail'])
	})

	it('shows the scratch directory, read only, and the largest copy it takes', async () => {
		const wrapper = section()

		expect(wrapper.find('[data-setting="scratch_directory"]').text()).toContain('/var/tmp/nextcloud (Nextcloud\'s temporary directory)')
		expect(wrapper.findAll('input:not([type="checkbox"])').map((input) => input.attributes('id'))).toEqual(['lfp-vips-concurrency', 'lfp-tiff-restrip-max'])

		const copy = wrapper.find('[data-setting="tiff_restrip_max_mb"]')

		expect(copy.findComponent({ name: 'NumberField' }).props('placeholder')).toBe('4096 (default)')
		expect(copy.text()).toContain('occ lfp:config:unset tiff_restrip_max_mb')

		await copy.findComponent({ name: 'NumberField' }).vm.$emit('save', 8192)

		expect(wrapper.emitted('save')).toEqual([[{ scalars: { tiff_restrip_max_mb: 8192 } }]])
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
