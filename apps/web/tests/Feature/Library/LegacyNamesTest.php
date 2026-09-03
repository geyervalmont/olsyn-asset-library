<?php

use App\Library\Legacy\LegacyNames;

test('legacy colourway names lose product names, codes, map suffixes and repeats', function (string $raw, string $product, ?string $code, ?string $supplier, string $expected) {
    expect(LegacyNames::colourway($raw, $product, $code, $supplier))->toBe($expected);
})->with([
    ['Grafito Grip Aspley Grafito Grip', 'Aspley', 'Aspley', 'Skheme', 'Grafito Grip'],
    ['Ashen 001', 'Academix', '001', 'Tarkett', 'Ashen'],
    ['2915 2915', 'DESSO Essence', '711446002', 'Tarkett', '2915'],
    ['12mm Solid PET Emboss Panel Celium - Zintra 12mm Solid PET - Bark', 'Emboss', 'Zintra', 'Baresque', 'Bark'],
    ['oakley-fr duckegg NRM', 'Oakley', '12891109', 'James Dunlop', 'Duckegg'],
    ['Oakley Duckegg', 'Oakley', null, 'James Dunlop', 'Duckegg'],
    ['Base in Bone + French Wash in Soapstone', 'French Wash', 'na', 'Porters Paint', 'Base in Bone + French Wash in Soapstone'],
    ['18325', 'Epoch 20 x 95mm', '18325', 'Academy Tiles', '18325'],
    ['Aged Oak', 'Acoustic Timber', 'na', 'Autex', 'Aged Oak'],
]);

test('an empty legacy name is null', function () {
    expect(LegacyNames::colourway('', 'Product'))->toBeNull()
        ->and(LegacyNames::colourway(null, 'Product'))->toBeNull();
});
