<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

enum Criterion: string
{
    case Season = 'season';
    case Weather = 'weather';
    case Altitude = 'altitude';
    case Exposure = 'exposure';
    case Cover = 'cover';
    case StandDensity = 'stand_density';
    case Geology = 'geology';
    case Moisture = 'moisture';
    case Edge = 'edge';
    case Slope = 'slope';

    public function label(): string
    {
        return match ($this) {
            self::Season => 'Saison',
            self::Weather => 'Météo / rythme de pousse',
            self::Altitude => 'Altitude',
            self::Exposure => 'Exposition',
            self::Cover => 'Couvert forestier',
            self::StandDensity => 'Densité du peuplement',
            self::Geology => 'Géologie / substrat',
            self::Moisture => 'Humidité topographique',
            self::Edge => 'Position lisière',
            self::Slope => 'Pente',
        };
    }

    public function rationale(): string
    {
        return match ($this) {
            self::Season => 'Chaque espèce ne fructifie que dans une fenêtre annuelle : hors saison, le score est plafonné (garde-fou), ce n’est plus un poids du classement.',
            self::Weather => 'La pluie déclenche le mycélium, mais les carpophores sortent seulement après un délai (souvent 7 à 15 jours selon l’espèce). Trop tôt après l’orage, il n’y a encore rien à cueillir.',
            self::Altitude => 'Température, durée d’enneigement et cortège d’arbres hôtes changent avec l’altitude : chaque espèce a sa tranche.',
            self::Exposure => 'Un versant nord garde l’humidité et la fraîcheur ; un adret chauffe et assèche. En montagne la préférence bascule aussi avec l’altitude.',
            self::Cover => 'La quasi-totalité des espèces suivies sont mycorhiziennes : sans l’arbre hôte, le score s’effondre. L’essence dominante affine le feuillu / conifère brut.',
            self::StandDensity => 'La surface terrière est le prédicteur de peuplement le plus corrélé aux récoltes (optimum souvent vers 15–20 m²/ha). On lit le taux de couvert Copernicus (0–100 %), en repli FO/FF si le raster manque.',
            self::Geology => 'Le substrat oriente le cortège : trompette et morille recherchent le calcaire, la girolle les sols plus acides. Proxy Charm-50 BRGM, pas un pH mesuré.',
            self::Moisture => 'Combres, thalwegs et proximité de l’eau retiennent l’humidité du sol après la pluie ; les croupes convexes sèchent en deux jours.',
            self::Edge => 'La fructification chute nettement dans la bande de lisière : le vent et le soleil y déstabilisent température et humidité du sol. Le cœur de massif reste la référence, sauf pour les espèces liées aux milieux perturbés.',
            self::Slope => 'Plus la pente est forte, plus le sol est mince et plus l’eau et la matière organique dévalent : les suivis de récolte montrent un rendement qui baisse régulièrement quand la pente augmente. La pente pèse peu seule (2 %), mais elle affine le choix entre deux boisements voisins.',
        };
    }

    /**
     * Expert-elicited priors. Season is a cap, not a weight (must not be in the 1.0 sum).
     * Redistributed août 2026 from season 13 % into density / geology / edge. See AGENTS.md.
     */
    public function weight(): float
    {
        return match ($this) {
            self::Season => 0.0,
            self::Weather => 0.16,
            self::Altitude => 0.13,
            self::Exposure => 0.13,
            self::Cover => 0.14,
            self::StandDensity => 0.16,
            self::Geology => 0.10,
            self::Moisture => 0.09,
            self::Edge => 0.07,
            self::Slope => 0.02,
        };
    }
}
