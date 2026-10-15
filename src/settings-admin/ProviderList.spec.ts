import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProviderList from './ProviderList.vue'
import { OURS } from './testState'
import type { Provider } from './types'

function provider(name: string, before = false): Provider {
	return { name, class: 'OC\\Preview\\' + name, ours: false, before }
}

describe('ProviderList', () => {
	it('names the providers in their order, each with its class as the tooltip', () => {
		const names = mount(ProviderList, { props: { providers: [{ ...OURS }, provider('PNG')] } }).findAll('.lfp-provider')

		expect(names.map((name) => name.text())).toEqual(['Large File Previews', 'PNG'])
		expect(names.map((name) => name.attributes('title'))).toEqual(['OCA\\LargeFilePreviews\\Preview\\LargeFileProvider', 'OC\\Preview\\PNG'])
		expect(names[0].classes()).toContain('lfp-provider--ours')
	})

	it('marks in amber a provider asked before this app\'s, and only that one', () => {
		const names = mount(ProviderList, { props: { providers: [provider('ImaginaryPDF', true), { ...OURS }, provider('PDF')] } }).findAll('.lfp-provider')

		expect(names.map((name) => name.classes().includes('lfp-provider--before'))).toEqual([true, false, false])
	})

	it('says so where no provider is registered', () => {
		expect(mount(ProviderList, { props: { providers: [] } }).text()).toBe('-')
	})
})
