<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Service;

/**
 * The distribution the server runs, from `/etc/os-release`, and the package
 * that brings each program the app runs: what an administrator installs.
 *
 * Five families, each package checked against the distribution's own index
 * on 2026-10-14: Debian and Ubuntu, Fedora, RHEL and its rebuilds, Alpine,
 * Arch. libvips is packaged for neither RHEL nor EPEL, and RHEL's
 * libtiff-tools is in the CRB repository. A distribution not among them,
 * or no os-release, gives no hint rather than a guess.
 */
class Platform
{

//  constants

	public const DEBIAN = 'debian';

	public const FEDORA = 'fedora';

	public const RHEL = 'rhel';

	public const ALPINE = 'alpine';

	public const ARCH = 'arch';

	/** Each family's name, and how it installs a package. */
	private const FAMILIES
		 = [
			self::DEBIAN => [ 'Debian or Ubuntu', 'apt install %s' ],
			self::FEDORA => [ 'Fedora', 'dnf install %s' ],
			self::RHEL   => [ 'RHEL or a rebuild', 'dnf install %s' ],
			self::ALPINE => [ 'Alpine', 'apk add %s' ],
			self::ARCH   => [ 'Arch', 'pacman -S %s' ],
		];

	/**
	 * The package each program comes in, by family; null where none brings
	 * it, with the note to show instead.
	 *
	 * @var array<string, array<string, string|null>>
	 */
	private const PACKAGES
		 = [
			self::DEBIAN => [
				'poppler'  => 'poppler-utils',
				'vips'     => 'libvips-tools',
				'tiff'     => 'libtiff-tools',
				'core'     => 'coreutils',
				'util'     => 'util-linux',
			],
			self::FEDORA => [
				'poppler'  => 'poppler-utils',
				'vips'     => 'vips-tools',
				'tiff'     => 'libtiff-tools',
				'core'     => 'coreutils',
				'util'     => 'util-linux',
			],
			self::RHEL   => [
				'poppler'  => 'poppler-utils',
				'vips'     => null,
				'tiff'     => 'libtiff-tools',
				'core'     => 'coreutils',
				'util'     => 'util-linux',
			],
			self::ALPINE => [
				'poppler'  => 'poppler-utils',
				'vips'     => 'vips-tools',
				'tiff'     => 'tiff-tools',
				'core'     => 'coreutils',
				'util'     => 'util-linux-misc',
			],
			self::ARCH   => [
				'poppler'  => 'poppler',
				'vips'     => 'libvips',
				'tiff'     => 'libtiff',
				'core'     => 'coreutils',
				'util'     => 'util-linux',
			],
		];

	/** Which source package each program is in. */
	private const SOURCES
		 = [
			'pdftoppm'      => 'poppler',
			'pdfinfo'       => 'poppler',
			'pdfimages'     => 'poppler',
			'vipsthumbnail' => 'vips',
			'vipsheader'    => 'vips',
			'vips'          => 'vips',
			'tiffcp'        => 'tiff',
			'timeout'       => 'core',
			'prlimit'       => 'util',
			'choom'         => 'util',
		];

	/**
	 * What a family says where its package needs more than the command:
	 * a code, which the settings page translates and text() spells out.
	 */
	private const NOTES
		 = [
			self::RHEL   => [
				'vips' => 'not-packaged',
				'tiff' => 'crb',
			],
			self::ALPINE => [
				'vips' => 'community',
			],
		];

	/** Each note in English, for a terminal or a log. */
	private const NOTE_TEXT
		 = [
			'not-packaged' => 'packaged for neither RHEL nor EPEL',
			'crb'          => 'from the CRB repository',
			'community'    => 'from the community repository',
		];


//  private properties

	private ?string $family = null;

	private bool $read = false;


//  constructor

	public function __construct(
		private readonly string $osRelease = '/etc/os-release',
	)
	{
	}


//  other non-static methods

	/**
	 * The family, or null where it cannot be told.
	 */
	public function family(): ?string
	{
		if ( ! $this->read )
		{
			$this->read   = true;
			$text         = @file_get_contents( $this->osRelease );
			$this->family = is_string( $text ) ? self::familyOf( $text ) : null;
		}

		return $this->family;
	}

	/**
	 * The family's name, for a person, or null.
	 */
	public function name(): ?string
	{
		$family = $this->family();

		return $family === null ? null : self::FAMILIES[ $family ][0];
	}

	/**
	 * What installs a program here: the command, null where no package
	 * brings it, and a note's code where one is due (`not-packaged`, `crb`,
	 * `community`); null where the platform or the program is not known.
	 *
	 * @return array{command: ?string, note: ?string}|null
	 */
	public function install( string $program ): ?array
	{
		$family = $this->family();
		$source = self::SOURCES[ $program ] ?? null;

		if ( $family === null || $source === null )
		{
			return null;
		}

		$package = self::PACKAGES[ $family ][ $source ] ?? null;

		return [
			'command' => $package === null ? null : sprintf( self::FAMILIES[ $family ][1], $package ),
			'note'    => self::NOTES[ $family ][ $source ] ?? null,
		];
	}


//  static methods

	/**
	 * An install hint as one line, for a terminal or a log.
	 *
	 * @param array{command: ?string, note: ?string}|null $install
	 */
	public static function text( ?array $install ): string
	{
		$note = $install['note'] ?? null;

		return implode( ', ', array_filter( [ $install['command'] ?? null, $note === null ? null : self::NOTE_TEXT[ $note ] ?? $note ], is_string( ... ) ) );
	}

	/**
	 * The family an os-release names by its ID, or else by ID_LIKE.
	 */
	public static function familyOf( string $osRelease ): ?string
	{
		$fields = [];

		foreach ( explode( "\n", $osRelease ) as $line )
		{
			if ( preg_match( '/^(ID|ID_LIKE)=(.*)$/', trim( $line ), $match ) === 1 )
			{
				$fields[ $match[1] ] = strtolower( trim( $match[2], " \t\"'" ) );
			}
		}

		$ids = [ $fields['ID'] ?? '', ...explode( ' ', $fields['ID_LIKE'] ?? '' ) ];

		foreach ( $ids as $id )
		{
			$family = match ( $id )
			{
				'debian', 'ubuntu'                                  => self::DEBIAN,
				'fedora'                                            => self::FEDORA,
				'rhel', 'centos', 'rocky', 'almalinux', 'ol'        => self::RHEL,
				'alpine'                                            => self::ALPINE,
				'arch', 'manjaro', 'endeavouros'                    => self::ARCH,
				default                                             => null,
			};

			if ( $family !== null )
			{
				return $family;
			}
		}

		return null;
	}
}
