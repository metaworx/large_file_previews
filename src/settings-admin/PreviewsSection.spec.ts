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
			'occ config:app:delete preview jpeg_quality',
			'occ config:app:delete preview webp_quality',
		])
	})

	it('shows Nextcloud\'s preview format read only, and its quality as it is stored', () => {
		const state = testState()
		state.nextcloud = { ...state.nextcloud, preview_format: 'webp', jpeg_quality: 90, webp_quality: 80, quality_stored: { jpeg_quality: true, webp_quality: false } }
		const own = section(state).find('[data-setting="nextcloud"]')

		expect(own.find('[data-nextcloud="preview_format"]').text()).toContain('Preview format: webp')
		expect(own.find('[data-nextcloud="preview_format"] input').exists()).toBe(false)

		const [jpeg, webp] = own.findAllComponents({ name: 'NumberField' })
		expect(jpeg.props('value')).toBe(90)
		expect(webp.props('value')).toBeNull()
		expect(webp.props('placeholder')).toBe('80 (default)')
		expect(own.find('[data-nextcloud="jpeg_quality"]').text()).toContain('occ config:app:set preview jpeg_quality --value=90 --type=integer')
	})

	it('says in amber that Nextcloud\'s own settings apply to every preview, and names the format\'s values', () => {
		const wrapper = section()

		expect(wrapper.findAll('h4').map((heading) => heading.text())).toContain('Nextcloud\'s own settings')
		const note = wrapper.findComponent({ name: 'NcNoteCard' })
		expect(note.props('type')).toBe('warning')
		expect(note.text()).toBe('Nextcloud\'s own preview settings, which apply to every preview, not only this app\'s.')
		expect(wrapper.find('[data-nextcloud="preview_format"]').text()).toContain('Preview format: jpeg (jpeg or webp, in config.php)')
	})

	it('sets Nextcloud\'s quality, and clears it to its default', async () => {
		const wrapper = section()
		const [jpeg, webp] = wrapper.find('[data-setting="nextcloud"]').findAllComponents({ name: 'NumberField' })

		await jpeg.vm.$emit('save', 70)
		await webp.vm.$emit('save', null)

		expect(wrapper.emitted('save')).toEqual([
			[{ nextcloud: { jpeg_quality: 70 } }],
			[{ nextcloud: { webp_quality: null } }],
		])
	})
})
