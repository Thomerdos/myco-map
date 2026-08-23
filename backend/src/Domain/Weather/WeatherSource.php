<?php

declare(strict_types=1);

namespace App\Domain\Weather;

use App\Domain\Geo\BoundingBox;

interface WeatherSource
{
    public function fieldFor(BoundingBox $bounds): WeatherField;
}
