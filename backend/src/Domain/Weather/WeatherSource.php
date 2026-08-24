<?php

declare(strict_types=1);

namespace App\Domain\Weather;

use App\Domain\Geo\BoundingBox;

interface WeatherSource
{
    /**
     * Spatially varying weather for $bounds, as seen from $asOf (Europe/Paris calendar day).
     * Forecast days use Open-Meteo predictions; past days use the observed archive in the
     * same response.
     */
    public function fieldFor(BoundingBox $bounds, ?\DateTimeImmutable $asOf = null): WeatherField;
}
