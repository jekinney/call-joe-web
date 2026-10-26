<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment provider (Braintree) failed or refused: bad keys, provider down, card declined. The message is safe to
 * show the user.
 */
class PaymentException extends RuntimeException {}
