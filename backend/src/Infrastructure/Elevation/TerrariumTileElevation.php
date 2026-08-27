<?php

declare(strict_types=1);

namespace App\Infrastructure\Elevation;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Terrain\ElevationSampler;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Elevation from the AWS "terrarium" open dataset (Mapzen heritage): no API key, and
 * roughly 13 m per pixel at zoom 13 in the Alps.
 *
 * Tiles are decoded once into compact uint16 decimetre rasters on disk, then memory
 * mapped as binary strings so sampling stays fast without large PHP arrays.
 */
final class TerrariumTileElevation implements ElevationSampler
{
    private const TILE_SIZE = 256;
    private const STORED_OFFSET_METERS = 5000;
    private const VOID_THRESHOLD = -32767.0;

    /** @var array<string, string|false> */
    private array $rasters = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDirectory,
        private readonly int $zoom = 13,
    ) {
    }

    public function prepare(BoundingBox $bounds): int
    {
        [$firstX, $firstY] = $this->tileAt(new Coordinates($bounds->north, $bounds->west));
        [$lastX, $lastY] = $this->tileAt(new Coordinates($bounds->south, $bounds->east));

        $requests = [];
        $paths = [];
        $count = 0;

        for ($x = $firstX; $x <= $lastX; $x++) {
            for ($y = $firstY; $y <= $lastY; $y++) {
                $count++;
                if (is_file($this->rasterPath($x, $y))) {
                    continue;
                }
                $pngPath = $this->tilePath($x, $y);
                if (is_file($pngPath)) {
                    $this->decode($pngPath, $this->rasterPath($x, $y));
                    continue;
                }
                $requests[] = $this->httpClient->request('GET', $this->tileUrl($x, $y), ['timeout' => 60]);
                $paths[] = [$pngPath, $this->rasterPath($x, $y)];
            }
        }

        foreach ($requests as $index => $response) {
            [$pngPath, $rasterPath] = $paths[$index];
            try {
                if ($response->getStatusCode() !== 200) {
                    continue;
                }
                $content = $response->getContent(false);
            } catch (\Throwable $exception) {
                $this->logger->warning('Tuile DEM indisponible', ['error' => $exception->getMessage()]);
                continue;
            }

            if ($content === '') {
                continue;
            }

            $this->write($pngPath, $content);
            $this->decode($pngPath, $rasterPath);
        }

        return $count;
    }

    public function elevationAt(Coordinates $point): ?float
    {
        return $this->elevationAtLatLng($point->latitude, $point->longitude);
    }

    public function elevationAtLatLng(float $latitude, float $longitude): ?float
    {
        [$pixelX, $pixelY] = $this->globalPixel($latitude, $longitude);

        $baseX = (int) floor($pixelX - 0.5);
        $baseY = (int) floor($pixelY - 0.5);
        $ratioX = ($pixelX - 0.5) - $baseX;
        $ratioY = ($pixelY - 0.5) - $baseY;

        $topLeft = $this->pixelElevation($baseX, $baseY);
        if ($topLeft === null) {
            return null;
        }

        $topRight = $this->pixelElevation($baseX + 1, $baseY) ?? $topLeft;
        $bottomLeft = $this->pixelElevation($baseX, $baseY + 1) ?? $topLeft;
        $bottomRight = $this->pixelElevation($baseX + 1, $baseY + 1) ?? $topLeft;

        $top = $topLeft + ($topRight - $topLeft) * $ratioX;
        $bottom = $bottomLeft + ($bottomRight - $bottomLeft) * $ratioX;

        return $top + ($bottom - $top) * $ratioY;
    }

    private function pixelElevation(int $globalX, int $globalY): ?float
    {
        $worldSize = self::TILE_SIZE * (2 ** $this->zoom);
        if ($globalY < 0 || $globalY >= $worldSize) {
            return null;
        }
        $globalX = (int) fmod($globalX + $worldSize, $worldSize);

        $raster = $this->raster(intdiv($globalX, self::TILE_SIZE), intdiv($globalY, self::TILE_SIZE));
        if ($raster === null) {
            return null;
        }

        $offset = (($globalY % self::TILE_SIZE) * self::TILE_SIZE + ($globalX % self::TILE_SIZE)) * 2;
        $chunk = substr($raster, $offset, 2);
        if (\strlen($chunk) !== 2) {
            return null;
        }

        return (unpack('n', $chunk)[1] / 10) - self::STORED_OFFSET_METERS;
    }

    private function raster(int $tileX, int $tileY): ?string
    {
        $key = $tileX . ':' . $tileY;

        if (!\array_key_exists($key, $this->rasters)) {
            $path = $this->rasterPath($tileX, $tileY);
            if (!is_file($path)) {
                $pngPath = $this->tilePath($tileX, $tileY);
                if (is_file($pngPath)) {
                    $this->decode($pngPath, $path);
                }
            }
            $content = is_file($path) ? file_get_contents($path) : false;
            $this->rasters[$key] = $content !== false && $content !== '' ? $content : false;
        }

        $raster = $this->rasters[$key];

        return $raster === false ? null : $raster;
    }

    private function decode(string $pngPath, string $rasterPath): void
    {
        $image = @imagecreatefrompng($pngPath);
        if ($image === false) {
            $this->logger->warning('Tuile DEM illisible', ['path' => $pngPath]);

            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $values = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $elevation = ((($rgb >> 16) & 0xFF) * 256 + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF) / 256) - 32768;
                if ($elevation <= self::VOID_THRESHOLD) {
                    $elevation = 0.0;
                }
                $values[] = max(0, min(65535, (int) round(($elevation + self::STORED_OFFSET_METERS) * 10)));
            }
        }

        imagedestroy($image);
        $this->write($rasterPath, pack('n*', ...$values));
    }

    /** @return array{0: int, 1: int} */
    private function tileAt(Coordinates $point): array
    {
        [$pixelX, $pixelY] = $this->globalPixel($point->latitude, $point->longitude);

        return [
            intdiv((int) floor($pixelX), self::TILE_SIZE),
            intdiv((int) floor($pixelY), self::TILE_SIZE),
        ];
    }

    /** @return array{0: float, 1: float} */
    private function globalPixel(float $latitude, float $longitude): array
    {
        $worldSize = self::TILE_SIZE * (2 ** $this->zoom);
        $latitude = max(-85.05112878, min(85.05112878, $latitude));
        $latitudeRadians = deg2rad($latitude);

        return [
            ($longitude + 180) / 360 * $worldSize,
            (1 - log(tan($latitudeRadians) + 1 / cos($latitudeRadians)) / M_PI) / 2 * $worldSize,
        ];
    }

    private function tileUrl(int $x, int $y): string
    {
        return sprintf(
            'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/%d/%d/%d.png',
            $this->zoom,
            $x,
            $y,
        );
    }

    private function tilePath(int $x, int $y): string
    {
        return sprintf('%s/png/%d/%d/%d.png', $this->cacheDirectory, $this->zoom, $x, $y);
    }

    private function rasterPath(int $x, int $y): string
    {
        return sprintf('%s/raster/%d/%d/%d.bin', $this->cacheDirectory, $this->zoom, $x, $y);
    }

    private function write(string $path, string $content): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer %s', $directory));
        }

        file_put_contents($path, $content);
    }
}
