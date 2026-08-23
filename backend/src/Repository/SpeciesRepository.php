<?php

namespace App\Repository;

use App\Model\Species;

final class SpeciesRepository
{
    /** @var array<string, Species> */
    private array $species;

    public function __construct()
    {
        $this->species = [
            'cepe' => new Species(
                id: 'cepe',
                name: 'Cèpe',
                scientificName: 'Boletus edulis',
                description: 'Chênaies et hêtraies fraîches, sols acides à neutres, après pluies automnales.',
                harvestWindows: [
                    ['start' => '09-01', 'end' => '11-30', 'peak' => true, 'label' => 'Automne (pic sept.-nov.)'],
                    ['start' => '08-15', 'end' => '08-31', 'peak' => false, 'label' => 'Fin août (début de saison)'],
                ],
            ),
            'trompette' => new Species(
                id: 'trompette',
                name: 'Trompette de la mort',
                scientificName: 'Craterellus cornucopioides',
                description: 'Hêtraies humides, creux et ravins ombragés, sols riches en humus.',
                harvestWindows: [
                    ['start' => '08-01', 'end' => '11-15', 'peak' => true, 'label' => 'Fin été – automne (pic sept.-oct.)'],
                ],
            ),
            'chanterelle' => new Species(
                id: 'chanterelle',
                name: 'Chanterelle améthyste',
                scientificName: 'Cantharellus amethysteus',
                description: 'Forêts de conifères et mixtes, sols humides, souvent près des racines de sapin ou épicéa.',
                harvestWindows: [
                    ['start' => '07-01', 'end' => '10-31', 'peak' => true, 'label' => 'Été – automne (pic juil.-sept.)'],
                ],
            ),
            'girolle' => new Species(
                id: 'girolle',
                name: 'Girolle',
                scientificName: 'Cantharellus cibarius',
                description: 'Forêts mixtes et conifères, lisières et clairières humides, sols légèrement acides.',
                harvestWindows: [
                    ['start' => '06-15', 'end' => '10-31', 'peak' => true, 'label' => 'Été – automne (pic juil.-sept.)'],
                ],
            ),
            'pied_mouton' => new Species(
                id: 'pied_mouton',
                name: 'Pied de mouton',
                scientificName: 'Hydnum repandum',
                description: 'Sous conifères et feuillus, sols humides couverts de mousse, versants frais.',
                harvestWindows: [
                    ['start' => '08-01', 'end' => '11-30', 'peak' => true, 'label' => 'Automne (pic sept.-oct.)'],
                ],
            ),
            'morille' => new Species(
                id: 'morille',
                name: 'Morille',
                scientificName: 'Morchella esculenta',
                description: 'Bordures forestières, frênaies, vergers, talus calcaires au printemps après fonte des neiges.',
                harvestWindows: [
                    ['start' => '03-15', 'end' => '05-31', 'peak' => true, 'label' => 'Printemps (pic avril)'],
                ],
            ),
        ];
    }

    /** @return list<Species> */
    public function findAll(): array
    {
        return array_values($this->species);
    }

    public function find(string $id): ?Species
    {
        return $this->species[$id] ?? null;
    }
}
