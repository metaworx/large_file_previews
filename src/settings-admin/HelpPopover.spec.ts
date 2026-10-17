/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import HelpPopover from './HelpPopover.vue'

/**
 * The real NcPopover renders its content only once opened, and puts it in
 * the body on the next turn of the queue - where a person reads it after a
 * click on the trigger, and where this spec reads it too.
 */
async function openHelp(wrapper: VueWrapper): Promise<HTMLElement | null> {
	await wrapper.find('button').trigger('click')
	await flushPromises()
	await new Promise((resolve) => setTimeout(resolve, 0))
	return document.body.querySelector<HTMLElement>('.lfp-help-body')
}

describe('HelpPopover', () => {
	let wrapper: VueWrapper | null = null

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
	})

	it('renders a labelled trigger, and the help text once opened', async () => {
		wrapper = mount(HelpPopover, { props: { text: 'What this does.', label: 'Timeout' } })
		expect(wrapper.find('button').attributes('aria-label')).toBe('Help: Timeout')
		expect(document.body.querySelector('.lfp-help-body')).toBeNull()

		const body = await openHelp(wrapper)
		expect(body?.textContent).toContain('What this does.')
	})

	it('shows what its slot holds after the text, the text then as wide', async () => {
		wrapper = mount(HelpPopover, { props: { text: 'What is stored:', label: 'Previews' }, slots: { default: '<table class="probe"><tr><td>JPEG</td></tr></table>' } })
		const body = await openHelp(wrapper)

		expect(body?.classList.contains('lfp-help-body--wide')).toBe(true)
		expect(body?.nextElementSibling?.classList.contains('lfp-help-more')).toBe(true)
		expect(body?.nextElementSibling?.querySelector('.probe td')?.textContent).toBe('JPEG')
	})

	it('renders nothing without a help text', () => {
		wrapper = mount(HelpPopover, { props: { label: 'Timeout' } })
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('escapes the help text instead of treating it as markup', async () => {
		wrapper = mount(HelpPopover, { props: { text: '<b>bold</b>', label: 'Path' } })
		const body = await openHelp(wrapper)
		expect(body).not.toBeNull()
		expect(body?.childElementCount).toBe(0)
		expect(body?.textContent).toBe('<b>bold</b>')
	})
})
