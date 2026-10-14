import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StatusSection from './StatusSection.vue'
import { testState } from './testState'

describe('StatusSection', () => {
	it('says when everything needed is there', () => {
		const notes = mount(StatusSection, { props: { state: testState() } }).findAllComponents({ name: 'NcNoteCard' })

		expect(notes.map((note) => note.props('type'))).toEqual(['success'])
	})

	it('says when something needed is missing, and lists it', () => {
		const state = testState()
		state.ready = false
		state.prerequisites[0].status = 'missing'
		const wrapper = mount(StatusSection, { props: { state } })

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).props('type')).toBe('error')
		expect(wrapper.find('.lfp-status--missing').text()).toContain('pdftoppm')
	})

	it('names the last failure and what is set but not used', () => {
		const state = testState()
		state.lastFailure = { time: 1760000000, mime: 'application/pdf', outcome: 'timed_out', reason: 'timed out (exit status 124)', exit_status: 124 }
		state.problems = ['extensions.TIF: not a lower-case extension; ignored']
		const text = mount(StatusSection, { props: { state } }).text()

		expect(text).toContain('was a file of type application/pdf: timed out (exit status 124).')
		expect(text).toContain('Set but not used: extensions.TIF')
	})

	it('says where a program\'s path is set, and that it is not here', () => {
		expect(mount(StatusSection, { props: { state: testState() } }).text())
			.toContain('set in system config only, never here')
	})
})
