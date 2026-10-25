<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The picture provider couldn't make a picture (not set up, refused, or down).
 */
class ImageException extends RuntimeException {}
