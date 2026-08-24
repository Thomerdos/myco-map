<?php

declare(strict_types=1);

namespace App\Infrastructure\Mycology;

use App\Domain\Mycology\AltitudeBand;
use App\Domain\Mycology\EdgeAffinity;
use App\Domain\Mycology\HarvestWindow;
use App\Domain\Mycology\SlopeBand;
use App\Domain\Mycology\Species;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\HostTree;
use App\Domain\Terrain\Substrate;

/**
 * Habitat profiles for the species targeted around Grenoble, calibrated on the
 * Chartreuse, Belledonne and Vercors ranges.
 */
final class InMemorySpeciesCatalog implements SpeciesCatalog
{
    /** @var array<string, Species> */
    private array $species;

    public function __construct()
    {
        $this->species = [];

        foreach ($this->definitions() as $species) {
            $this->species[$species->id] = $species;
        }
    }

    public function all(): array
    {
        return array_values($this->species);
    }

    public function get(string $id): Species
    {
        return $this->species[$id]
            ?? throw new \InvalidArgumentException(sprintf('Espèce inconnue : %s', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->species[$id]);
    }

    /** @return list<Species> */
    private function definitions(): array
    {
        return [
            new Species(
                id: 'cepe',
                commonName: 'Cèpe',
                scientificName: 'Boletus edulis, aereus, pinophilus',
                summary: 'Hêtraies et chênaies fraîches en moyenne montagne, pessières et sapinières plus haut. Sort 10 à 15 jours après un épisode pluvieux marqué, surtout sur les versants qui gardent l\'humidité.',
                hostTrees: 'Hêtre, chêne, épicéa, sapin, châtaignier',
                harvestWindows: [
                    new HarvestWindow('08-15', '11-15', true, 'Mi-août à mi-novembre, pic septembre-octobre'),
                    new HarvestWindow('06-01', '07-20', false, 'Poussée d\'été en altitude après les orages'),
                ],
                altitude: new AltitudeBand(350, 700, 1500, 1850),
                slope: new SlopeBand(25),
                coolPreference: 0.68,
                coverAffinity: [
                    ForestCover::Broadleaf->value => 0.95,
                    ForestCover::Mixed->value => 0.92,
                    ForestCover::Conifer->value => 0.80,
                    ForestCover::Undetermined->value => 0.66,
                    ForestCover::Open->value => 0.06,
                ],
                edgeAffinity: EdgeAffinity::Indifferent,
                moisturePreference: 0.62,
                flushDelayMinDays: 8,
                flushDelayPeakDays: 12,
                flushDelayMaxDays: 16,
                flushPersistDays: 4,
                hostAffinity: [
                    HostTree::Beech->value => 1.00,
                    HostTree::Oak->value => 0.98,
                    HostTree::SpruceFir->value => 0.95,
                    HostTree::Chestnut->value => 0.82,
                    HostTree::Pine->value => 0.68,
                    HostTree::OtherBroadleaf->value => 0.62,
                    HostTree::OtherConifer->value => 0.58,
                    HostTree::Larch->value => 0.55,
                    HostTree::Douglas->value => 0.52,
                    HostTree::Poplar->value => 0.28,
                    HostTree::Robinia->value => 0.22,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.72,
                    Substrate::Mixed->value => 0.78,
                    Substrate::Siliceous->value => 0.70,
                    Substrate::Unknown->value => 0.55,
                ],
            ),
            new Species(
                id: 'trompette',
                commonName: 'Trompette de la mort',
                scientificName: 'Craterellus cornucopioides',
                summary: 'Pousse en troupes serrées dans les hêtraies sur calcaire, au fond des combes ombragées et dans la litière épaisse. Discrète : il faut chercher les replats humides à l\'intérieur des massifs.',
                hostTrees: 'Hêtre surtout, parfois charme et chêne',
                harvestWindows: [
                    new HarvestWindow('08-20', '11-30', true, 'Fin août à fin novembre, pic septembre-octobre'),
                ],
                altitude: new AltitudeBand(300, 600, 1300, 1600),
                slope: new SlopeBand(22),
                coolPreference: 0.85,
                coverAffinity: [
                    ForestCover::Broadleaf->value => 0.98,
                    ForestCover::Mixed->value => 0.80,
                    ForestCover::Conifer->value => 0.42,
                    ForestCover::Undetermined->value => 0.62,
                    ForestCover::Open->value => 0.04,
                ],
                edgeAffinity: EdgeAffinity::Interior,
                moisturePreference: 0.85,
                flushDelayMinDays: 9,
                flushDelayPeakDays: 13,
                flushDelayMaxDays: 18,
                flushPersistDays: 6,
                hostAffinity: [
                    HostTree::Beech->value => 1.00,
                    HostTree::Oak->value => 0.82,
                    HostTree::OtherBroadleaf->value => 0.58,
                    HostTree::Chestnut->value => 0.50,
                    HostTree::Poplar->value => 0.30,
                    HostTree::SpruceFir->value => 0.28,
                    HostTree::Robinia->value => 0.22,
                    HostTree::OtherConifer->value => 0.22,
                    HostTree::Pine->value => 0.20,
                    HostTree::Larch->value => 0.18,
                    HostTree::Douglas->value => 0.16,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.98,
                    Substrate::Mixed->value => 0.82,
                    Substrate::Siliceous->value => 0.32,
                    Substrate::Unknown->value => 0.55,
                ],
            ),
            new Species(
                id: 'chanterelle',
                commonName: 'Chanterelles',
                scientificName: 'Cantharellus spp., Craterellus tubaeformis',
                summary: 'Toutes les chanterelles des massifs : améthyste, pâle, ferrugineuse, cendrée et en tube. Elles suivent les mousses humides, les talus de sentiers et les fonds de combe, du collinéen au subalpin.',
                hostTrees: 'Hêtre, chêne, sapin, épicéa, bouleau',
                harvestWindows: [
                    new HarvestWindow('06-15', '11-30', true, 'Mi-juin à fin novembre, pic juillet-octobre'),
                    new HarvestWindow('12-01', '12-20', false, 'Chanterelle en tube jusqu\'aux premières gelées'),
                ],
                altitude: new AltitudeBand(350, 700, 1700, 2000),
                slope: new SlopeBand(28),
                coolPreference: 0.76,
                coverAffinity: [
                    ForestCover::Mixed->value => 0.95,
                    ForestCover::Conifer->value => 0.93,
                    ForestCover::Broadleaf->value => 0.85,
                    ForestCover::Undetermined->value => 0.68,
                    ForestCover::Open->value => 0.07,
                ],
                edgeAffinity: EdgeAffinity::Indifferent,
                moisturePreference: 0.88,
                flushDelayMinDays: 7,
                flushDelayPeakDays: 11,
                flushDelayMaxDays: 16,
                flushPersistDays: 5,
                hostAffinity: [
                    HostTree::Beech->value => 0.96,
                    HostTree::SpruceFir->value => 0.95,
                    HostTree::Oak->value => 0.82,
                    HostTree::Chestnut->value => 0.72,
                    HostTree::OtherBroadleaf->value => 0.70,
                    HostTree::OtherConifer->value => 0.70,
                    HostTree::Pine->value => 0.68,
                    HostTree::Larch->value => 0.58,
                    HostTree::Douglas->value => 0.55,
                    HostTree::Poplar->value => 0.35,
                    HostTree::Robinia->value => 0.28,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.62,
                    Substrate::Mixed->value => 0.75,
                    Substrate::Siliceous->value => 0.78,
                    Substrate::Unknown->value => 0.55,
                ],
            ),
            new Species(
                id: 'girolle',
                commonName: 'Girolle',
                scientificName: 'Cantharellus cibarius',
                summary: 'La girolle vraie, souvent alignée le long des talus et des sentiers, sur sol acide et moussu. Réagit vite aux orages d\'été. Les talus qui la portent sont des micro-lisières internes, pas des bordures de massif.',
                hostTrees: 'Hêtre, chêne, châtaignier, épicéa',
                harvestWindows: [
                    new HarvestWindow('06-01', '10-31', true, 'Juin à octobre, pic juillet-septembre'),
                ],
                altitude: new AltitudeBand(350, 650, 1600, 1900),
                slope: new SlopeBand(30),
                coolPreference: 0.70,
                coverAffinity: [
                    ForestCover::Mixed->value => 0.95,
                    ForestCover::Broadleaf->value => 0.90,
                    ForestCover::Conifer->value => 0.88,
                    ForestCover::Undetermined->value => 0.68,
                    ForestCover::Open->value => 0.08,
                ],
                edgeAffinity: EdgeAffinity::Indifferent,
                moisturePreference: 0.75,
                flushDelayMinDays: 5,
                flushDelayPeakDays: 8,
                flushDelayMaxDays: 13,
                flushPersistDays: 5,
                hostAffinity: [
                    HostTree::Beech->value => 0.98,
                    HostTree::Oak->value => 0.94,
                    HostTree::SpruceFir->value => 0.92,
                    HostTree::Chestnut->value => 0.90,
                    HostTree::OtherBroadleaf->value => 0.68,
                    HostTree::OtherConifer->value => 0.65,
                    HostTree::Pine->value => 0.62,
                    HostTree::Larch->value => 0.50,
                    HostTree::Douglas->value => 0.48,
                    HostTree::Poplar->value => 0.32,
                    HostTree::Robinia->value => 0.26,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.42,
                    Substrate::Mixed->value => 0.68,
                    Substrate::Siliceous->value => 0.95,
                    Substrate::Unknown->value => 0.55,
                ],
            ),
            new Species(
                id: 'pied_mouton',
                commonName: 'Pied de mouton',
                scientificName: 'Hydnum repandum, rufescens',
                summary: 'Espèce robuste et tardive, en cercles ou en lignes sous les conifères et les hêtres, dans les mousses des versants frais. Tient jusqu\'aux gelées.',
                hostTrees: 'Sapin, épicéa, hêtre',
                harvestWindows: [
                    new HarvestWindow('08-20', '12-15', true, 'Fin août à mi-décembre, pic octobre-novembre'),
                ],
                altitude: new AltitudeBand(350, 700, 1600, 1900),
                slope: new SlopeBand(28),
                coolPreference: 0.80,
                coverAffinity: [
                    ForestCover::Conifer->value => 0.95,
                    ForestCover::Mixed->value => 0.92,
                    ForestCover::Broadleaf->value => 0.78,
                    ForestCover::Undetermined->value => 0.66,
                    ForestCover::Open->value => 0.05,
                ],
                edgeAffinity: EdgeAffinity::Interior,
                moisturePreference: 0.80,
                flushDelayMinDays: 10,
                flushDelayPeakDays: 14,
                flushDelayMaxDays: 20,
                flushPersistDays: 5,
                hostAffinity: [
                    HostTree::SpruceFir->value => 1.00,
                    HostTree::Beech->value => 0.82,
                    HostTree::OtherConifer->value => 0.72,
                    HostTree::Oak->value => 0.58,
                    HostTree::Pine->value => 0.55,
                    HostTree::Larch->value => 0.50,
                    HostTree::Douglas->value => 0.48,
                    HostTree::OtherBroadleaf->value => 0.48,
                    HostTree::Chestnut->value => 0.42,
                    HostTree::Poplar->value => 0.22,
                    HostTree::Robinia->value => 0.18,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.58,
                    Substrate::Mixed->value => 0.72,
                    Substrate::Siliceous->value => 0.82,
                    Substrate::Unknown->value => 0.55,
                ],
            ),
            new Species(
                id: 'morille',
                commonName: 'Morille',
                scientificName: 'Morchella esculenta, conica',
                summary: 'Printanière et capricieuse : sols calcaires perturbés, frênaies, ripisylves, anciens vergers et zones de crue. Cherche les versants qui se réchauffent vite après la fonte des neiges.',
                hostTrees: 'Frêne, peuplier, aubépine, pommier, épicéa pour la conique',
                harvestWindows: [
                    new HarvestWindow('03-10', '05-31', true, 'Mi-mars à fin mai, pic avril'),
                ],
                altitude: new AltitudeBand(180, 300, 1100, 1500),
                slope: new SlopeBand(20),
                coolPreference: 0.28,
                coverAffinity: [
                    ForestCover::Broadleaf->value => 0.88,
                    ForestCover::Mixed->value => 0.76,
                    ForestCover::Undetermined->value => 0.60,
                    ForestCover::Conifer->value => 0.44,
                    ForestCover::Open->value => 0.52,
                ],
                edgeAffinity: EdgeAffinity::Edge,
                moisturePreference: 0.85,
                requiresForest: false,
                flushDelayMinDays: 5,
                flushDelayPeakDays: 8,
                flushDelayMaxDays: 12,
                flushPersistDays: 3,
                hostAffinity: [
                    HostTree::Poplar->value => 1.00,
                    HostTree::OtherBroadleaf->value => 0.95,
                    HostTree::Robinia->value => 0.70,
                    HostTree::SpruceFir->value => 0.62,
                    HostTree::Oak->value => 0.55,
                    HostTree::Beech->value => 0.42,
                    HostTree::Chestnut->value => 0.40,
                    HostTree::Pine->value => 0.32,
                    HostTree::OtherConifer->value => 0.30,
                    HostTree::Larch->value => 0.28,
                    HostTree::Douglas->value => 0.26,
                ],
                standDensityAffinity: [
                    CanopyClosure::Open->value => 0.95,
                    CanopyClosure::Closed->value => 0.70,
                    CanopyClosure::Unknown->value => 0.55,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.96,
                    Substrate::Mixed->value => 0.78,
                    Substrate::Siliceous->value => 0.38,
                    Substrate::Unknown->value => 0.55,
                ],
            ),
        ];
    }
}
