<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\LargeFilePreviews\Preview;

use BadMethodCallException;
use GdImage;
use OCP\IImage;
use OCP\IStreamImage;

/**
 * A rendered preview handed to Nextcloud as the file it is: a stream, its
 * MIME type and its size. Nextcloud stores a stream image as it is
 * (Generator::savePreview(), Nextcloud 24 and later), so the largest
 * preview keeps the renderer's encoding and quality, and PHP never decodes
 * it; the smaller sizes Nextcloud makes from the stored file, as ever.
 *
 * The stream is open on the rendered file, which may be unlinked while it
 * is: its descriptor keeps it readable. An image to change it is not -
 * every method that would change or draw it refuses, as Nextcloud's own
 * stream image's do.
 */
final class RenderedPreview
    implements
    IStreamImage
{

//  constants

	/** The types a renderer writes, which Nextcloud stores as they are. */
	public const TYPES = [ 'image/png', 'image/jpeg', 'image/webp' ];


//  constructor

	/**
	 * @param resource $stream
	 */
	public function __construct(
		private $stream,
		private readonly string $mimeType,
		private readonly int    $width,
		private readonly int    $height,
	)
	{
	}


//  other non-static methods

	#[\Override]
	public function valid(): bool
	{
		return is_resource( $this->stream );
	}

	#[\Override]
	public function mimeType(): ?string
	{
		return $this->mimeType;
	}

	#[\Override]
	public function dataMimeType(): ?string
	{
		return $this->mimeType;
	}

	#[\Override]
	public function width(): int
	{
		return $this->width;
	}

	#[\Override]
	public function height(): int
	{
		return $this->height;
	}

	#[\Override]
	public function widthTopLeft(): int
	{
		return $this->width;
	}

	#[\Override]
	public function heightTopLeft(): int
	{
		return $this->height;
	}


//  getters / setters / is* / has*

	#[\Override]
	public function getOrientation(): int
	{
		return 1;
	}

	/**
	 * @return resource
	 */
	#[\Override]
	public function resource()
	{
		return $this->stream;
	}

	/**
	 * The file's bytes, for whoever reads the image rather than its stream;
	 * the stream is left where it was found.
	 */
	#[\Override]
	public function data(): ?string
	{
		if ( ! $this->valid() )
		{
			return null;
		}

		$position = ftell( $this->stream );
		rewind( $this->stream );
		$data = stream_get_contents( $this->stream );

		if ( $position !== false )
		{
			fseek( $this->stream, $position );
		}

		return $data === false ? null : $data;
	}

	#[\Override]
	public function show( ?string $mimeType = null ): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function save(
		?string $filePath = null,
		?string $mimeType = null,
	): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function fixOrientation(): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function resize( int $maxSize ): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function preciseResize(
		int $width,
		int $height,
	): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function centerCrop( int $size = 0 ): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function crop(
		int $x,
		int $y,
		int $w,
		int $h,
	): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function fitIn(
		int $maxWidth,
		int $maxHeight,
	): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function scaleDownToFit(
		int $maxWidth,
		int $maxHeight,
	): bool
	{
		throw self::fixed();
	}

	#[\Override]
	public function copy(): IImage
	{
		throw self::fixed();
	}

	#[\Override]
	public function cropCopy(
		int $x,
		int $y,
		int $w,
		int $h,
	): IImage
	{
		throw self::fixed();
	}

	#[\Override]
	public function preciseResizeCopy(
		int $width,
		int $height,
	): IImage
	{
		throw self::fixed();
	}

	#[\Override]
	public function resizeCopy( int $maxSize ): IImage
	{
		throw self::fixed();
	}

	#[\Override]
	public function loadFromData( string $str ): GdImage|false
	{
		throw self::fixed();
	}

	#[\Override]
	public function readExif( string $data ): void
	{
		throw self::fixed();
	}


//  static methods

	/**
	 * A rendered file as a stream image, or null where it is not one of the
	 * types a renderer writes.
	 */
	public static function open( string $path ): ?self
	{
		$size = @getimagesize( $path );

		if ( ! is_array( $size ) || $size[0] <= 0 || $size[1] <= 0 || ! in_array( $size['mime'], self::TYPES, true ) )
		{
			return null;
		}

		$stream = @fopen( $path, 'rb' );

		return $stream === false
			? null
			: new self( $stream, $size['mime'], $size[0], $size[1] );
	}

	private static function fixed(): BadMethodCallException
	{
		return new BadMethodCallException( 'A rendered preview is stored as it is; it is not an image to change.' );
	}
}
