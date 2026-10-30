<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        // DATETIME columns carry no timezone: keep every stored timestamp in UTC so that the
        // duration of a round is never distorted by a daylight-saving change.
        date_default_timezone_set('UTC');

        parent::__construct($environment, $debug);
    }
}
