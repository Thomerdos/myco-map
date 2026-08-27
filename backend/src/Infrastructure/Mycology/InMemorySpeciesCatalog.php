<?php

declare(strict_types=1);

namespace App\Infrastructure\Mycology;

use App\Domain\Mycology\AltitudeBand;
use App\Domain\Mycology\CanopyDensityBand;
use App\Domain\Mycology\EdgeAffinity;
use App\Domain\Mycology\HarvestWindow;
use App\Domain\Mycology\PhBand;
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
 *
 * Excellence squeeze (août 2026): narrower TCD optima, lower closed floors, and
 * compressed cover/host/geology ceilings so only rare intersections clear 90.
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
                    ForestCover::Broadleaf->value => 0.88,
                    ForestCover::Mixed->value => 0.85,
                    ForestCover::Conifer->value => 0.74,
                    ForestCover::Undetermined->value => 0.62,
                    ForestCover::Open->value => 0.06,
                ],
                edgeAffinity: EdgeAffinity::Indifferent,
                moisturePreference: 0.62,
                flushDelayMinDays: 8,
                flushDelayPeakDays: 12,
                flushDelayMaxDays: 16,
                flushPersistDays: 4,
                hostAffinity: [
                    HostTree::Beech->value => 0.94,
                    HostTree::Oak->value => 0.90,
                    HostTree::SpruceFir->value => 0.86,
                    HostTree::Chestnut->value => 0.76,
                    HostTree::Pine->value => 0.64,
                    HostTree::OtherBroadleaf->value => 0.58,
                    HostTree::OtherConifer->value => 0.54,
                    HostTree::Larch->value => 0.52,
                    HostTree::Douglas->value => 0.50,
                    HostTree::Poplar->value => 0.28,
                    HostTree::Robinia->value => 0.22,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.68,
                    Substrate::Mixed->value => 0.72,
                    Substrate::Siliceous->value => 0.66,
                    Substrate::Unknown->value => 0.55,
                ],
                canopyDensity: new CanopyDensityBand(55, 64, 0.62, 0.20),
                phOptimum: new PhBand(5.2, 6.8, 0.32),
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
                    ForestCover::Broadleaf->value => 0.90,
                    ForestCover::Mixed->value => 0.74,
                    ForestCover::Conifer->value => 0.40,
                    ForestCover::Undetermined->value => 0.58,
                    ForestCover::Open->value => 0.04,
                ],
                edgeAffinity: EdgeAffinity::Interior,
                moisturePreference: 0.85,
                flushDelayMinDays: 9,
                flushDelayPeakDays: 13,
                flushDelayMaxDays: 18,
                flushPersistDays: 6,
                hostAffinity: [
                    HostTree::Beech->value => 0.94,
                    HostTree::Oak->value => 0.76,
                    HostTree::OtherBroadleaf->value => 0.55,
                    HostTree::Chestnut->value => 0.48,
                    HostTree::Poplar->value => 0.30,
                    HostTree::SpruceFir->value => 0.28,
                    HostTree::Robinia->value => 0.22,
                    HostTree::OtherConifer->value => 0.22,
                    HostTree::Pine->value => 0.20,
                    HostTree::Larch->value => 0.18,
                    HostTree::Douglas->value => 0.16,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.90,
                    Substrate::Mixed->value => 0.76,
                    Substrate::Siliceous->value => 0.32,
                    Substrate::Unknown->value => 0.55,
                ],
                canopyDensity: new CanopyDensityBand(58, 70, 0.66, 0.16),
                phOptimum: new PhBand(6.8, 7.8, 0.18),
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
                    ForestCover::Mixed->value => 0.88,
                    ForestCover::Conifer->value => 0.86,
                    ForestCover::Broadleaf->value => 0.80,
                    ForestCover::Undetermined->value => 0.64,
                    ForestCover::Open->value => 0.07,
                ],
                edgeAffinity: EdgeAffinity::Indifferent,
                moisturePreference: 0.88,
                flushDelayMinDays: 7,
                flushDelayPeakDays: 11,
                flushDelayMaxDays: 16,
                flushPersistDays: 5,
                hostAffinity: [
                    HostTree::Beech->value => 0.90,
                    HostTree::SpruceFir->value => 0.88,
                    HostTree::Oak->value => 0.78,
                    HostTree::Chestnut->value => 0.68,
                    HostTree::OtherBroadleaf->value => 0.66,
                    HostTree::OtherConifer->value => 0.66,
                    HostTree::Pine->value => 0.64,
                    HostTree::Larch->value => 0.55,
                    HostTree::Douglas->value => 0.52,
                    HostTree::Poplar->value => 0.35,
                    HostTree::Robinia->value => 0.28,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.58,
                    Substrate::Mixed->value => 0.70,
                    Substrate::Siliceous->value => 0.72,
                    Substrate::Unknown->value => 0.55,
                ],
                canopyDensity: new CanopyDensityBand(55, 64, 0.62, 0.20),
                phOptimum: new PhBand(5.0, 6.5, 0.25),
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
                    ForestCover::Mixed->value => 0.88,
                    ForestCover::Broadleaf->value => 0.84,
                    ForestCover::Conifer->value => 0.82,
                    ForestCover::Undetermined->value => 0.64,
                    ForestCover::Open->value => 0.08,
                ],
                edgeAffinity: EdgeAffinity::Indifferent,
                moisturePreference: 0.75,
                flushDelayMinDays: 5,
                flushDelayPeakDays: 8,
                flushDelayMaxDays: 13,
                flushPersistDays: 5,
                hostAffinity: [
                    HostTree::Beech->value => 0.92,
                    HostTree::Oak->value => 0.88,
                    HostTree::SpruceFir->value => 0.86,
                    HostTree::Chestnut->value => 0.84,
                    HostTree::OtherBroadleaf->value => 0.64,
                    HostTree::OtherConifer->value => 0.62,
                    HostTree::Pine->value => 0.58,
                    HostTree::Larch->value => 0.48,
                    HostTree::Douglas->value => 0.46,
                    HostTree::Poplar->value => 0.32,
                    HostTree::Robinia->value => 0.26,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.40,
                    Substrate::Mixed->value => 0.64,
                    Substrate::Siliceous->value => 0.88,
                    Substrate::Unknown->value => 0.55,
                ],
                canopyDensity: new CanopyDensityBand(48, 58, 0.60, 0.22),
                phOptimum: new PhBand(4.8, 6.2, 0.18),
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
                    ForestCover::Conifer->value => 0.88,
                    ForestCover::Mixed->value => 0.85,
                    ForestCover::Broadleaf->value => 0.74,
                    ForestCover::Undetermined->value => 0.62,
                    ForestCover::Open->value => 0.05,
                ],
                edgeAffinity: EdgeAffinity::Interior,
                moisturePreference: 0.80,
                flushDelayMinDays: 10,
                flushDelayPeakDays: 14,
                flushDelayMaxDays: 20,
                flushPersistDays: 5,
                hostAffinity: [
                    HostTree::SpruceFir->value => 0.94,
                    HostTree::Beech->value => 0.78,
                    HostTree::OtherConifer->value => 0.68,
                    HostTree::Oak->value => 0.55,
                    HostTree::Pine->value => 0.52,
                    HostTree::Larch->value => 0.48,
                    HostTree::Douglas->value => 0.46,
                    HostTree::OtherBroadleaf->value => 0.46,
                    HostTree::Chestnut->value => 0.40,
                    HostTree::Poplar->value => 0.22,
                    HostTree::Robinia->value => 0.18,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.55,
                    Substrate::Mixed->value => 0.68,
                    Substrate::Siliceous->value => 0.76,
                    Substrate::Unknown->value => 0.55,
                ],
                canopyDensity: new CanopyDensityBand(55, 65, 0.62, 0.20),
                phOptimum: new PhBand(5.0, 6.5, 0.25),
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
                    ForestCover::Broadleaf->value => 0.82,
                    ForestCover::Mixed->value => 0.72,
                    ForestCover::Undetermined->value => 0.58,
                    ForestCover::Conifer->value => 0.42,
                    ForestCover::Open->value => 0.50,
                ],
                edgeAffinity: EdgeAffinity::Edge,
                moisturePreference: 0.85,
                requiresForest: false,
                flushDelayMinDays: 5,
                flushDelayPeakDays: 8,
                flushDelayMaxDays: 12,
                flushPersistDays: 3,
                hostAffinity: [
                    HostTree::Poplar->value => 0.94,
                    HostTree::OtherBroadleaf->value => 0.88,
                    HostTree::Robinia->value => 0.66,
                    HostTree::SpruceFir->value => 0.58,
                    HostTree::Oak->value => 0.52,
                    HostTree::Beech->value => 0.40,
                    HostTree::Chestnut->value => 0.38,
                    HostTree::Pine->value => 0.32,
                    HostTree::OtherConifer->value => 0.30,
                    HostTree::Larch->value => 0.28,
                    HostTree::Douglas->value => 0.26,
                ],
                standDensityAffinity: [
                    CanopyClosure::Open->value => 0.88,
                    CanopyClosure::Closed->value => 0.62,
                    CanopyClosure::Unknown->value => 0.55,
                ],
                geologyAffinity: [
                    Substrate::Calcareous->value => 0.90,
                    Substrate::Mixed->value => 0.72,
                    Substrate::Siliceous->value => 0.38,
                    Substrate::Unknown->value => 0.55,
                ],
                canopyDensity: new CanopyDensityBand(20, 38, 0.42, 0.68),
                phOptimum: new PhBand(6.8, 7.8, 0.18),
            ),
        ];
    }
}
