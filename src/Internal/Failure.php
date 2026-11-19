<?php

declare(strict_types=1);

namespace Alxarafe\Arp\Dictionary\Internal;

/** Only expected contract rejections use this exception. Resource/programming errors propagate. */
final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
