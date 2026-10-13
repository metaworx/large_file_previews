module.exports = {
	// Stop the eslintrc cascade here: nothing above this directory is part of
	// the project, so a stray config in a parent must not reach these files.
	root: true,
	// The Vue 3 + TypeScript preset. The bare '@nextcloud' shorthand resolves
	// to the plain-JS/Vue 2 one, whose parser cannot read a
	// `<script setup lang="ts">` block at all.
	extends: [
		'@nextcloud/eslint-config/vue3',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		// The TypeScript signature already names and types each parameter;
		// a tag per parameter could only restate it. A tag someone did write
		// must say something.
		'jsdoc/require-param': 'off',
		'jsdoc/require-returns': 'off',
		'jsdoc/require-param-description': 'warn',
		'vue/first-attribute-linebreak': 'off',
		// Every text a person reads goes through t() (the contract, 3.4). The
		// allowlist is punctuation and the glyphs shown as they are; the
		// attributes are the ones a text arrives in.
		'vue/no-bare-strings-in-template': ['error', {
			allowlist: [
				'(', ')', ',', '.', '&', '+', '-', '=', '*', '/', '#', '%', '!', '?', ':', '[', ']', '{', '}', '<', '>',
				'·', '•', '‐', '–', '—', '−', '|',
				'…', '✓', '✗', '←', '→',
			],
			attributes: {
				'/.+/': [
					'title', 'aria-label', 'aria-placeholder', 'aria-roledescription', 'aria-valuetext',
					'placeholder', 'name', 'description', 'empty-text', 'input-label', 'text',
				],
				'/^(?!NcSelect$).+/': ['label'],
				img: ['alt'],
			},
		}],
		'no-restricted-syntax': [
			'error',
			// toastSaved('Saved.'): the same bare text by another door.
			{
				selector: 'CallExpression[callee.name=/^toast(Saved|Error)$/][arguments.0.type=/^(Literal|TemplateLiteral)$/]',
				message: 'Pass the toast a translated text: t(\'large_file_previews\', \'…\').',
			},
		],
	},
	overrides: [
		{
			// A spec mounts the real Nextcloud components; the runner inlines
			// the library (vitest.config.ts, vitest.setup.ts). A stand-in tests
			// its author's idea of the control. A mock of the server or of the
			// page - @nextcloud/router, @nextcloud/dialogs - stays fair game.
			files: ['src/**/*.spec.ts'],
			rules: {
				'no-restricted-syntax': [
					'error',
					{
						selector: 'CallExpression[callee.object.name="vi"][callee.property.name=/^(mock|doMock)$/] > Literal[value=/^@nextcloud\\u002fvue(\\u002f|$)/]',
						message: 'Mount the real Nextcloud component; the runner can load it. See vitest.setup.ts.',
					},
					{
						selector: 'Property[key.name="stubs"] > ObjectExpression > Property[key.name=/^Nc[A-Z]/]',
						message: 'Do not stub a Nextcloud component; mount it. See vitest.setup.ts.',
					},
				],
			},
		},
	],
}
