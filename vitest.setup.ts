/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * What the runner's DOM must not do for the real Nextcloud components.
 *
 * happy-dom ships a ResizeObserver whose callbacks fire on their own clock.
 * NcSelect positions its menu through floating-ui, which subscribes to one;
 * in a spec the menu is closed, or the page unmounted, long before that
 * clock ticks, and the promise it leaves then rejects on a ref that is gone.
 * No spec asserts on where a menu is placed, so the observer here observes
 * nothing.
 */
import { afterEach } from 'vitest'
import { config, enableAutoUnmount } from '@vue/test-utils'

class QuietResizeObserver {
	observe(): void {}

	unobserve(): void {}

	disconnect(): void {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
	configurable: true,
	writable: true,
	value: QuietResizeObserver,
})

// A dialog's content is teleported to the body, where `wrapper.find()` does
// not look; rendered in place, it is found where the spec mounted it.
config.global.stubs = { ...config.global.stubs, teleport: true }

// Every wrapper a spec mounts is unmounted after its test: the real
// components register document listeners, and a page left mounted keeps
// answering events the next test raises.
enableAutoUnmount(afterEach)
