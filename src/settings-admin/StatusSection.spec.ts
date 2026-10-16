import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StatusSection from './StatusSection.vue'
import { cellLabels, testState, withPdfOn } from './testState'

describe('StatusSection', () => {
	it('names the app\'s version first', () => {
		const wrapper = mount(StatusSection, { props: { state: testState() } })

		expect(wrapper.find('.lfp-version').text()).toBe('Large File Previews, v0.1.0')
		expect(wrapper.find('h3 + p').classes()).toContain('lfp-version')
	})

	it('says when everything needed is there', () => {
		const notes = mount(StatusSection, { props: { state: withPdfOn() } }).findAllComponents({ name: 'NcNoteCard' })

		expect(notes.map((note) => note.props('type'))).toEqual(['success'])
	})

	it('warns in amber while nothing is processed, as installed', () => {
		const notes = mount(StatusSection, { props: { state: testState() } }).findAllComponents({ name: 'NcNoteCard' })

		expect(notes.map((note) => note.props('type'))).toEqual(['success', 'warning'])
		expect(notes[1].text()).toBe('Nothing is processed: every MIME type is disabled.')
	})

	it('counts an extension switched on alone as something processed', () => {
		const state = testState()
		state.active.push({
			mime: 'image/tiff',
			extension: 'tif',
			processing: 'enabled',
			renderer: { program: 'vipsthumbnail', available: true, unsupported: null },
			min_size_mb: { value: 50, source: 'mime' },
			max_size_mb: { value: -1, source: 'default' },
		})
		const wrapper = mount(StatusSection, { props: { state } })

		expect(wrapper.find('.lfp-nothing-processed').exists()).toBe(false)

		state.active[0].processing = 'excluded'
		expect(mount(StatusSection, { props: { state } }).find('.lfp-nothing-processed').exists()).toBe(true)
	})

	it('says when something needed is missing, and lists it', () => {
		const state = testState()
		state.ready = false
		state.prerequisites[0].status = 'missing'
		const wrapper = mount(StatusSection, { props: { state } })

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).props('type')).toBe('error')
		expect(wrapper.find('.lfp-status--missing').text()).toContain('pdftoppm')
		expect(wrapper.find('.lfp-status--missing').text()).toContain('apt install poppler-utils')
	})

	it('names what installs an optional program, and none for one that is there', () => {
		const wrapper = mount(StatusSection, { props: { state: testState() } })

		expect(wrapper.find('.lfp-status--optional').text()).toContain('optional')
		expect(wrapper.find('.lfp-status--optional').text()).toContain('apt install util-linux')
		expect(wrapper.find('.lfp-status--ok').text()).not.toContain('apt install')
		expect(wrapper.find('.lfp-platform').text()).toBe('The packages named are Debian\'s and Ubuntu\'s.')
	})

	it('says why nothing installs a program, and where the platform is unknown', () => {
		const state = testState()
		state.prerequisites[1].install = { command: null, note: 'not-packaged' }
		state.platform = 'rhel'
		const wrapper = mount(StatusSection, { props: { state } })

		expect(wrapper.find('.lfp-status--optional').text()).toContain('No package brings it on RHEL or EPEL.')
		expect(wrapper.find('.lfp-status--optional').text()).not.toContain('dnf')

		state.platform = null

		expect(mount(StatusSection, { props: { state } }).find('.lfp-platform').text()).toContain('not one the app knows packages for')
	})

	it('names the last failure and what is set but not used', () => {
		const state = testState()
		state.lastFailure = { time: 1760000000, mime: 'application/pdf', outcome: 'timed_out', reason: 'timed out (exit status 124)', exit_status: 124 }
		state.problems = ['extensions.TIF: not a lower-case extension; ignored']
		const text = mount(StatusSection, { props: { state } }).text()

		expect(text).toContain('was a file of type application/pdf: timed out (exit status 124).')
		expect(text).toContain('Set but not used: extensions.TIF')
	})

	it('labels each cell with its column, for a row stacked on a narrow page', () => {
		const { headers, rows } = cellLabels(mount(StatusSection, { props: { state: testState() } }).find('table').element)

		expect(headers).toEqual([null, 'Status', 'For', 'Detail'])
		expect(rows.length).toBeGreaterThan(0)
		for (const row of rows) {
			expect(row).toEqual(headers)
		}
	})

	it('says where a program\'s path is set, and that it is not here', () => {
		expect(mount(StatusSection, { props: { state: testState() } }).text())
			.toContain('set in system config only, never here')
	})
})
