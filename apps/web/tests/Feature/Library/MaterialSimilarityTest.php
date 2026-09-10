<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Jobs\EmbedMaterial;
use App\Library\Embeddings\EmbeddingInput;
use App\Library\Embeddings\EmbeddingProvider;
use App\Library\Embeddings\MaterialSimilarity;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\Embedding;
use App\Models\Material;
use App\Models\Representation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

final class FakeMaterialEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<EmbeddingInput> */
    public array $inputs = [];

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'material-test-v1';
    }

    public function dimensions(): int
    {
        return 1024;
    }

    public function embed(EmbeddingInput $input): array
    {
        $this->inputs[] = $input;
        $text = strtolower($input->text);

        [$x, $y] = match (true) {
            str_contains($text, 'material: walnut') => [0.95, 0.05],
            str_contains($text, 'material: carpet') => [0.0, 1.0],
            str_contains($text, 'material: secret') => [1.0, 0.0],
            str_contains($text, 'oak'), str_contains($text, 'timber'), str_contains($text, 'wood') => [1.0, 0.0],
            default => [0.5, 0.5],
        };

        return [$x, $y, ...array_fill(0, 1022, 0.0)];
    }
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    config([
        'opal.embeddings.enabled' => true,
        'opal.embeddings.provider' => 'fake',
        'opal.embeddings.model' => 'material-test-v1',
        'opal.embeddings.dimensions' => 1024,
    ]);
    $this->provider = new FakeMaterialEmbeddingProvider;
    app()->instance(EmbeddingProvider::class, $this->provider);

    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->tenant->makeCurrent();

    $timber = Category::query()->where('code', 'TMB')->sole();
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->oak = Material::factory()->inHouse()->create(['name' => 'Natural Oak', 'category_id' => $timber, 'description' => 'Warm pale timber']);
    $this->walnut = Material::factory()->inHouse()->create(['name' => 'Walnut veneer', 'category_id' => $timber, 'description' => 'Dark natural wood']);
    $this->carpet = Material::factory()->inHouse()->create(['name' => 'Carpet tile', 'category_id' => $carpet, 'description' => 'Soft blue textile flooring']);
    $this->secret = Material::factory()->inHouse()->create(['name' => 'Secret oak', 'category_id' => $timber, 'visibility' => Visibility::Restricted]);

    foreach ([$this->oak, $this->walnut, $this->carpet, $this->secret] as $material) {
        app(AddVariant::class)->handle($material, ['colourway' => $material->name]);
    }

    $png = imagecreatetruecolor(256, 256);
    ob_start();
    imagepng($png);
    $preview = app(FileStore::class)->store((string) ob_get_clean(), 'oak-preview.png', 'image/png');
    $representation = app(CreateRepresentation::class)->handle(
        $this->oak->variants()->firstOrFail(),
        'preview',
        'preview',
        ['render' => $preview],
        Representation::KIND_IMAGE,
    );
    app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved);

    foreach ([$this->oak, $this->walnut, $this->carpet, $this->secret] as $material) {
        EmbedMaterial::forMaterialHere($material);
    }
});

afterEach(fn () => Tenant::forgetCurrent());

test('materials are indexed from metadata and an approved rendered preview', function () {
    $embedding = Embedding::query()->whereMorphedTo('embeddable', $this->oak)->sole();

    expect(Embedding::query()->count())->toBe(4)
        ->and($embedding->provider)->toBe('fake')
        ->and($embedding->model)->toBe('material-test-v1')
        ->and($embedding->dimensions)->toBe(1024)
        ->and($embedding->source_text)->toContain('Material: Natural Oak', 'Category: Timber')
        ->and($embedding->image_file_id)->not->toBeNull()
        ->and($this->provider->inputs[0]->image)->not->toBeNull()
        ->and(EmbedMaterial::isCurrent($this->oak))->toBeTrue();

    EmbedMaterial::forMaterialHere($this->oak);
    expect($this->provider->inputs)->toHaveCount(4)
        ->and(Embedding::query()->count())->toBe(4);
});

test('semantic and material similarity ranking respect visibility', function () {
    $semantic = app(MaterialSimilarity::class)
        ->toText(Material::query()->visibleTo($this->viewer), 'warm natural timber')
        ->pluck('materials.name')
        ->all();
    $similar = app(MaterialSimilarity::class)
        ->toMaterial(Material::query()->visibleTo($this->viewer), $this->oak)
        ->pluck('materials.name')
        ->all();

    expect($semantic)->toBe(['Natural Oak', 'Walnut veneer', 'Carpet tile'])
        ->and($similar)->toBe(['Walnut veneer', 'Carpet tile'])
        ->and($semantic)->not->toContain('Secret oak');
});

test('the web library and API expose both similarity paths', function () {
    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->assertSee('Meaning')
        ->set('mode', 'semantic')
        ->set('search', 'warm natural timber')
        ->assertSeeInOrder(['Natural Oak', 'Walnut veneer', 'Carpet tile'])
        ->set('similar', $this->oak->code)
        ->assertSee('Materials similar to Natural Oak')
        ->assertDontSee('Secret oak');

    Sanctum::actingAs($this->viewer);
    $this->getJson('/api/v1/materials?mode=semantic&q=warm%20natural%20timber')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Natural Oak')
        ->assertJsonPath('data.0.similarity', 1)
        ->assertJsonMissing(['name' => 'Secret oak']);

    $this->getJson('/api/v1/materials?similar_to='.$this->oak->code)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Walnut veneer')
        ->assertJsonMissing(['name' => 'Natural Oak'])
        ->assertJsonMissing(['name' => 'Secret oak']);
});

test('the embedding command skips current material vectors', function () {
    $this->artisan('opal:embeddings:index --stale --sync')
        ->expectsOutputToContain('Current')
        ->assertSuccessful();

    expect($this->provider->inputs)->toHaveCount(4);
});
