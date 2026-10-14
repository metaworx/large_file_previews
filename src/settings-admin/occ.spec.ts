import { describe, expect, it } from 'vitest'
import { binaryCommand, ruleCommand, scalarCommand, scratchCommand, untrustedCommand } from './occ'

describe('occ commands', () => {
	it('sets a setting to the value it has', () => {
		expect(scalarCommand('timeout_seconds', 30)).toBe('occ lfp:config:set timeout_seconds 30')
		expect(scalarCommand('choom_enabled', false)).toBe('occ lfp:config:set choom_enabled false')
		expect(scalarCommand('preview_format', 'webp')).toBe('occ lfp:config:set preview_format webp')
		expect(scalarCommand('max_size_mb', -1)).toBe('occ lfp:config:set max_size_mb -1')
	})

	it('sets a rule\'s fields, or clears a rule that sets none', () => {
		expect(ruleCommand('mime', 'image/tiff', { enabled: true, min_size_mb: 50 }))
			.toBe('occ lfp:config:set --mime image/tiff --enabled=yes --min=50')
		expect(ruleCommand('ext', 'tif', { enabled: false, max_size_mb: -1 }))
			.toBe('occ lfp:config:set --ext tif --enabled=no --max=-1')
		expect(ruleCommand('ext', 'tif', {})).toBe('occ lfp:config:unset --ext tif')
	})

	it('allows untrusted decoders, or blocks them again', () => {
		expect(untrustedCommand(false)).toBe('occ lfp:config:set vips_allow_untrusted true')
		expect(untrustedCommand(true)).toBe('occ lfp:config:unset vips_allow_untrusted')
	})

	it('quotes a path the shell would split', () => {
		expect(binaryCommand('pdftoppm', '/opt/poppler/bin/pdftoppm')).toBe('occ lfp:config:set binaries.pdftoppm /opt/poppler/bin/pdftoppm')
		expect(scratchCommand('/srv/scratch dir/it\'s')).toBe('occ lfp:config:set scratch_directory \'/srv/scratch dir/it\'\\\'\'s\'')
	})
})
