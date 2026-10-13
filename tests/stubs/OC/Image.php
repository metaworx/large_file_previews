<?php

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 *
 * For Psalm only, never loaded at run time: OCP\Image, the public way to
 * make an image, extends this server class, which the nextcloud/ocp package
 * does not ship, so without it `new \OCP\Image()` is a MissingDependency.
 * Only what the app relies on is declared: the constructor, and IImage,
 * which the server's class implements.
 */

namespace OC;

use OCP\IImage;

abstract class Image
    implements
    IImage
{

//  constructor

	public function __construct()
	{
	}
}
