<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The voice-call provider failed (bad key, provider down, bad request).
 */
class VoiceException extends RuntimeException {}
