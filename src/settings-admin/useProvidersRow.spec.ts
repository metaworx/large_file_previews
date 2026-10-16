import { describe, expect, it } from 'vitest'
import { needsOwnRow } from './useProvidersRow'

describe('needsOwnRow', () => {
	it('keeps the providers beside the name while the longest fits the column', () => {
		expect(needsOwnRow([180, 240], 300, 2)).toBe(false)
		expect(needsOwnRow([300], 300, 2)).toBe(false)
	})

	it('gives every type\'s providers a row of their own once one chain does not fit', () => {
		expect(needsOwnRow([180, 420, 240], 300, 2)).toBe(true)
	})

	it('changes nothing in one column, where they have a row of their own already', () => {
		expect(needsOwnRow([420], 300, 1)).toBe(false)
	})

	it('changes nothing without a type', () => {
		expect(needsOwnRow([], 300, 2)).toBe(false)
	})
})
