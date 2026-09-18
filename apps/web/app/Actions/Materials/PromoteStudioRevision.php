<?php

namespace App\Actions\Materials;

use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Library\FileStore;
use App\Library\Studio\DraftStore;
use App\Models\Material;
use App\Models\Representation;
use App\Models\StudioDraft;
use App\Models\StudioRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PromoteStudioRevision
{
    /** @param array<string, mixed> $destination */
    public function handle(StudioRevision $revision, array $destination, User $actor): Representation
    {
        abort_unless($actor->can('materials.contribute') && StudioDraft::query()->owned()->whereKey($revision->studio_draft_id)->exists(), 403);
        $data = Validator::make($destination, [
            'mode' => 'required|in:new,colourway,improve',
            'name' => 'required|string|max:120',
            'category_id' => 'required_if:mode,new|nullable|exists:categories,id',
            'material_id' => 'required_unless:mode,new|nullable|integer',
            'variant_id' => 'required_if:mode,improve|nullable|integer',
            'colourway' => 'required_if:mode,colourway|nullable|string|max:120',
        ])->validate();
        Validator::make($revision->document, ['width_mm' => 'required|numeric|gt:0|max:100000', 'height_mm' => 'required|numeric|gt:0|max:100000'])->validate();
        abort_unless(isset($revision->artifacts['base_color']), 422, 'Generate or import material maps first.');

        return DB::transaction(function () use ($revision, $data, $actor): Representation {
            $locked = StudioRevision::query()->lockForUpdate()->findOrFail($revision->id);
            if ($locked->representation_id !== null) {
                return Representation::query()->findOrFail($locked->representation_id);
            }
            $draft = StudioDraft::query()->lockForUpdate()->findOrFail($locked->studio_draft_id);
            abort_unless($draft->state === 'active' && $draft->head_id === $locked->id, 409, 'Reopen the current draft before saving to the library.');
            if ($data['mode'] === 'new') {
                $material = app(CreateMaterial::class)->handle([
                    'name' => $data['name'], 'category_id' => $data['category_id'],
                    'tile_width_mm' => $locked->document['width_mm'], 'tile_height_mm' => $locked->document['height_mm'],
                    'contributed_by_tenant_id' => $draft->tenant_id, 'contributed_by_user_id' => $actor->id,
                ], [['attributes' => []]]);
                $variant = $material->variants()->firstOrFail();
            } else {
                $material = Material::query()->visibleTo($actor)->findOrFail((int) $data['material_id']);
                $variant = $data['mode'] === 'colourway'
                    ? app(AddVariant::class)->handle($material, ['colourway' => ['value' => $data['colourway']]], overrides: ['tile_width_mm' => $locked->document['width_mm'], 'tile_height_mm' => $locked->document['height_mm']])
                    : $material->variants()->findOrFail((int) $data['variant_id']);
            }
            $files = [];
            foreach (DraftStore::MAPS as $role) {
                if (isset($locked->artifacts[$role])) {
                    $files[$role] = app(FileStore::class)->store(app(DraftStore::class)->bytes($locked->artifacts[$role]), $role.'.png', 'image/png');
                }
            }
            $representation = app(CreateRepresentation::class)->handle($variant, 'pbr', (int) $locked->artifacts['base_color']['width'], $files, createdBy: $actor, metadata: [
                'normal_convention' => $locked->document['normal_convention'] ?? 'opengl',
                'studio_destination' => $data['mode'], 'studio_revision_id' => $locked->id, 'source_representation_id' => $draft->source_representation_id,
                'tile_width_mm' => $locked->document['width_mm'], 'tile_height_mm' => $locked->document['height_mm'],
            ], notes: 'Studio candidate. Review material estimates before publishing.');
            foreach ($representation->representationFiles()->with('role')->get() as $link) {
                $link->update(['colour_space' => $link->role->slug === 'base_color' ? 'srgb' : 'linear']);
            }
            app(RecordProvenance::class)->handle($representation, 'generated', $actor, outputs: array_values($files), tool: 'opal-studio', model: $locked->run !== null ? strtoupper($locked->run->manifest['model'] ?? 'chord') : null, parameters: ['revision_id' => $locked->id, 'source_sha256' => $locked->document['source']['sha256'] ?? null, 'document' => array_diff_key($locked->document, array_flip(['source', 'original'])), 'execution' => $locked->run?->manifest], jobId: $locked->run?->uuid);
            $locked->update(['representation_id' => $representation->id]);
            $draft->update(['state' => 'promoted']);

            return $representation;
        });
    }
}
