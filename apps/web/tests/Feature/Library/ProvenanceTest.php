<?php

use App\Actions\Provenance\RecordProvenance;
use App\Enums\ActorType;
use App\Library\Provenance\Lineage;
use App\Models\File;
use App\Models\Material;
use App\Models\ProvenanceAction;
use App\Models\ProvenanceEvent;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
});

test('a chain of upload, edit and upscale is recorded and walkable', function () {
    $matt = User::factory()->create(['name' => 'Matt']);
    $harrison = User::factory()->create(['name' => 'Harrison']);
    $tarkett = Supplier::factory()->create(['name' => 'Tarkett']);
    $tarkettSite = Source::factory()->create(['name' => 'Tarkett website', 'kind' => 'supplier', 'supplier_id' => $tarkett->getKey()]);
    $variant = Variant::factory()->create();
    $record = app(RecordProvenance::class);

    $bumpA = File::factory()->create(['original_name' => 'bump_v1.png']);
    $bumpB = File::factory()->create(['original_name' => 'bump_v2.png']);
    $bumpC = File::factory()->create(['original_name' => 'bump_v3.png']);

    $upload = $record->handle($variant, 'uploaded', $matt, outputs: [[$bumpA, 'bump']], source: $tarkettSite, sourceUrl: 'https://tarkett.example/academix', occurredAt: now()->subDays(3));
    $edit = $record->handle($variant, 'edited', $harrison, inputs: [[$bumpA, 'bump']], outputs: [[$bumpB, 'bump']], tool: 'Photoshop', toolVersion: '26', notes: 'softened the bump', parent: $upload, occurredAt: now()->subDays(2));
    $upscale = $record->handle($variant, 'upscaled', 'App\\Jobs\\UpscaleTexture', inputs: [[$bumpB, 'bump']], outputs: [[$bumpC, 'bump']], tool: 'opal upscale worker', toolVersion: '1.2', model: 'gemini-2.5-flash-image', parameters: ['scale' => 2], jobId: 'job-123', parent: $edit, occurredAt: now()->subDay());

    expect($upload->actor_type)->toBe(ActorType::User)
        ->and($upscale->actor_type)->toBe(ActorType::Worker)
        ->and($upscale->actor_name)->toBe('App\\Jobs\\UpscaleTexture')
        ->and($upscale->parent?->is($edit))->toBeTrue()
        ->and($edit->inputs->first()?->is($bumpA))->toBeTrue()
        ->and($edit->outputs->first()?->pivot?->role)->toBe('bump');

    $lineage = $bumpC->lineage();

    expect($lineage->map->action->all())->toBe(['upscaled', 'edited', 'uploaded'])
        ->and($lineage->map(fn (ProvenanceEvent $event): string => $event->describe())->all())->toBe([
            'App\\Jobs\\UpscaleTexture upscaled with opal upscale worker 1.2, gemini-2.5-flash-image',
            'Harrison edited with Photoshop 26',
            'Matt uploaded',
        ])
        ->and($bumpC->ancestors()->map->original_name->all())->toBe(['bump_v2.png', 'bump_v1.png'])
        ->and($bumpC->sources()->map->name->all())->toBe(['Tarkett website'])
        ->and($bumpA->lineage()->map->action->all())->toBe(['uploaded'])
        ->and($bumpA->consumedBy()->count())->toBe(1)
        ->and($bumpC->producedBy()->sole()->is($upscale))->toBeTrue();

    $timeline = app(Lineage::class)->timelineFor($variant);

    expect($timeline->map->action->all())->toBe(['uploaded', 'edited', 'upscaled'])
        ->and($variant->provenanceEvents()->count())->toBe(3);
});

test('lineage crosses materials when a file is derived from another material', function () {
    $poliigon = Source::factory()->create(['name' => 'Poliigon', 'kind' => 'platform']);
    $donor = Variant::factory()->create();
    $recipient = Variant::factory()->create();
    $record = app(RecordProvenance::class);

    $donorAlbedo = File::factory()->create();
    $derived = File::factory()->create();

    $record->handle($donor, 'downloaded', null, outputs: [$donorAlbedo], source: $poliigon, sourceUrl: 'https://poliigon.example/x');
    $record->handle($recipient, 'generated', 'App\\Jobs\\TransferMicrostructure', inputs: [[$donorAlbedo, 'donor']], outputs: [[$derived, 'base_color']], model: 'gpt-image-2');

    expect($derived->sources()->map->name->all())->toBe(['Poliigon'])
        ->and($derived->lineage()->first()?->subject?->is($recipient))->toBeTrue()
        ->and($derived->lineage()->last()?->subject?->is($donor))->toBeTrue()
        ->and($derived->lineage()->last()?->actor_type)->toBe(ActorType::External);
});

test('only registered actions are accepted and the registry is data', function () {
    $material = Material::factory()->create();
    $file = File::factory()->create();

    expect(fn () => app(RecordProvenance::class)->handle($material, 'teleported', outputs: [$file]))
        ->toThrow(LogicException::class);

    ProvenanceAction::query()->create(['slug' => 'teleported', 'name' => 'Teleported']);

    $event = app(RecordProvenance::class)->handle($material, 'teleported', outputs: [$file]);

    expect($event->exists)->toBeTrue()
        ->and(ProvenanceAction::query()->count())->toBe(count(LibrarySeeder::PROVENANCE_ACTIONS) + 1);
});

test('a file can be an output of one event and an input of many, and the walk terminates on cycles', function () {
    $variant = Variant::factory()->create();
    $record = app(RecordProvenance::class);
    $a = File::factory()->create();
    $b = File::factory()->create();

    $record->handle($variant, 'uploaded', outputs: [$a]);
    $record->handle($variant, 'edited', inputs: [$a], outputs: [$b]);
    $record->handle($variant, 'edited', inputs: [$b], outputs: [$a], notes: 'a pathological loop');

    expect($a->lineage()->count())->toBe(3)
        ->and($b->lineage()->count())->toBe(3)
        ->and($a->consumedBy()->count())->toBe(1)
        ->and($a->producedBy()->count())->toBe(2);
});
