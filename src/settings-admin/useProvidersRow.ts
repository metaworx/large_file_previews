/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * Whether the MIME types' providers each take a row of their own, the
 * width of the list, rather than the second column beside the name: all of
 * them at once, as soon as the longest chain no longer fits that column on
 * one line, so that no type has them on the right and the next beneath.
 * CSS cannot compare one item's content with the others'; this measures,
 * whenever the list is resized and whenever what it lists changes.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Ref, WatchSource } from 'vue'

/**
 * Whether the providers need a row of their own: where the rules are in
 * two columns, and the widest chain is wider than the second.
 */
export function needsOwnRow(widths: number[], column: number, columns: number): boolean {
	return columns > 1 && widths.some((width) => width > column)
}

/** Each chain's width on one line, in the list given. */
function chainWidths(list: HTMLElement): number[] {
	const widths: number[] = []
	for (const element of list.querySelectorAll<HTMLElement>('.lfp-rule-providers')) {
		element.style.width = 'max-content'
		widths.push(element.getBoundingClientRect().width)
		element.style.width = ''
	}
	return widths
}

/** The second column's width, and how many columns there are. */
function columns(list: HTMLElement): [number, number] {
	const rule = list.querySelector<HTMLElement>('.lfp-rule')
	const sizes = list.querySelectorAll<HTMLElement>('.lfp-rule__size')
	if (rule === null || sizes.length < 2) {
		return [0, 1]
	}
	return [sizes[1].getBoundingClientRect().width, getComputedStyle(rule).gridTemplateColumns.split(' ').length]
}

export function useProvidersRow(list: Ref<HTMLElement | null>, content: WatchSource): Ref<boolean> {
	const ownRow = ref(false)

	function measure(): void {
		if (list.value === null) {
			return
		}
		const [column, count] = columns(list.value)
		ownRow.value = needsOwnRow(chainWidths(list.value), column, count)
	}

	let observer: ResizeObserver | null = null
	onMounted(() => {
		if (typeof ResizeObserver !== 'undefined' && list.value !== null) {
			observer = new ResizeObserver(measure)
			observer.observe(list.value)
		}
		measure()
	})
	onBeforeUnmount(() => observer?.disconnect())
	watch(content, measure, { flush: 'post', deep: true })

	return ownRow
}
