<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * Coarse substrate class for mycological affinity: limestone vs crystalline / acidic
 * bedrock. Derived from BRGM Charm-50 formation descriptions, not measured pH.
 */
enum Substrate: int
{
    case Unknown = 0;
    case Calcareous = 1;
    case Siliceous = 2;
    case Mixed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Substrat indéterminé',
            self::Calcareous => 'Calcaire / dolomie',
            self::Siliceous => 'Siliceux / cristallin',
            self::Mixed => 'Marneux / mixte',
        };
    }

    /**
     * Keyword rules on BD Charm-50 DESCR (French). Order matters: carbonate markers
     * win over generic "grès", marls are mixed, crystalline keywords are siliceous.
     */
    public static function fromDescription(?string $description): self
    {
        $text = mb_strtolower(trim((string) $description));
        if ($text === '') {
            return self::Unknown;
        }

        if (self::containsAny($text, [
            'calcaire', 'calcaires', 'calcar', 'dolomie', 'dolomies', 'dolomit',
            'craie', 'urgonien', 'cargneule', 'calcschiste', 'lumachelle',
        ])) {
            return self::Calcareous;
        }

        if (self::containsAny($text, [
            'marne', 'marnes', 'marno', 'marneux', 'terres noires',
        ])) {
            return self::Mixed;
        }

        if (self::containsAny($text, [
            'granite', 'granites', 'gneiss', 'schiste', 'schistes', 'quartzite',
            'micaschiste', 'amphibolite', 'migmatite', 'basalte', 'andésite', 'andesite',
            'rhyolite', 'spilite', 'cristal', 'cristallo', 'leptynite', 'arkose',
            'grès', 'gres', 'gréseux', 'greseux', 'sable', 'sables', 'sablo',
            'conglomérat', 'conglomerat', 'molasse', 'flysch',
        ])) {
            return self::Siliceous;
        }

        return self::Unknown;
    }

    /** @param list<string> $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
