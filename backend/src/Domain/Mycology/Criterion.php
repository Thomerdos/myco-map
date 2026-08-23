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
            self::Moisture => 'Humidité topographique',
            self::Edge => 'Position lisière',
            self::Slope => 'Pente',
        };
    }

    /**
     * Why this factor exists in the model — shown next to the site-specific reading
     * so a weight like "2 %" for slope does not look arbitrary.
     */
    public function rationale(): string
    {
        return match ($this) {
            self::Season => 'Chaque espèce ne fructifie que dans une fenêtre annuelle : hors saison, le terrain le plus parfait reste vain.',
            self::Weather => 'La pluie déclenche le mycélium, mais les carpophores sortent seulement après un délai (souvent 7 à 15 jours selon l’espèce). Trop tôt après l’orage, il n’y a encore rien à cueillir.',
            self::Altitude => 'Température, durée d’enneigement et cortège d’arbres hôtes changent avec l’altitude : chaque espèce a sa tranche.',
            self::Exposure => 'Un versant nord garde l’humidité et la fraîcheur ; un adret chauffe et assèche. En montagne la préférence bascule aussi avec l’altitude.',
            self::Cover => 'La quasi-totalité des espèces suivies sont mycorhiziennes : sans l’arbre hôte, le score s’effondre.',
            self::Moisture => 'Combres, thalwegs et proximité de l’eau retiennent l’humidité du sol après la pluie ; les croupes convexes sèchent en deux jours.',
            self::Edge => 'La fructification chute nettement dans la bande de lisière : le vent et le soleil y déstabilisent température et humidité du sol. Le cœur de massif reste la référence, sauf pour les espèces liées aux milieux perturbés.',
            self::Slope => 'Plus la pente est forte, plus le sol est mince et plus l’eau et la matière organique dévalent : les suivis de récolte montrent un rendement qui baisse régulièrement quand la pente augmente. La pente pèse peu seule (2 %), mais elle affine le choix entre deux boisements voisins.',
        };
    }

    /**
     * Expert-elicited priors, not coefficients fitted on harvest data. Published yield models
     * back the ranking and the direction of each effect but not the exact percentages — see
     * AGENTS.md for the sourced table, the known divergences and the recalibration procedure.
     * The weights must sum to 1.0.
     */
    public function weight(): float
    {
        return match ($this) {
            self::Season => 0.16,
            self::Weather => 0.22,
            self::Altitude => 0.15,
            self::Exposure => 0.15,
            self::Cover => 0.18,
            self::Moisture => 0.08,
            self::Edge => 0.04,
            self::Slope => 0.02,
        };
    }
}
