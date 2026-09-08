<?php

namespace App\Library\Packaging;

/**
 * Everything the toolbox needs to build one variant's USDZ, in the shape it is
 * handed over as JSON.
 *
 * This class is the contract between the library and the toolbox. Two rules in
 * it are load-bearing and deliberately explicit rather than inferred:
 *
 * Colour space is stated per file, never guessed from the map role or the
 * filename. Base colour is sRGB and the rest are raw, but a corpus this old
 * contains exceptions, and guessing produces materials that look plausible and
 * are wrong everywhere.
 *
 * Normal convention travels with the file. The corpus holds both OpenGL and
 * DirectX normals; flipping green is lossless but only if you know it needs
 * doing.
 */
readonly class BuildRequest
{
    /**
     * @param  array<string, array<string, ChannelSource>>  $channels  role => tier => source
     * @param  array<string, mixed>  $tiling
     * @param  array<string, mixed>  $provenance
     * @param  list<string>  $requiredTiers
     */
    public function __construct(
        public string $variantCode,
        public string $name,
        public array $channels,
        public array $tiling = [],
        public array $provenance = [],
        public ?string $shadingModel = 'openpbr',
        public array $requiredTiers = ['preview', '1k', '2k', '4k'],
        public ?string $ingestedAt = null,
    ) {}

    /**
     * The tiers this request actually carries, across every channel.
     *
     * @return list<string>
     */
    public function tiers(): array
    {
        $tiers = [];

        foreach ($this->channels as $sources) {
            foreach (array_keys($sources) as $tier) {
                $tiers[$tier] = true;
            }
        }

        $found = array_keys($tiers);
        sort($found);

        return $found;
    }

    public function isEmpty(): bool
    {
        return $this->channels === [];
    }

    /**
     * The manifest handed to the toolbox. Key order is stable so an unchanged
     * variant produces an identical manifest, which is what lets the builder
     * skip work it has already done.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $channels = [];

        foreach ($this->channels as $role => $sources) {
            ksort($sources);
            $channels[$role] = array_map(fn (ChannelSource $source): array => $source->toArray(), $sources);
        }

        ksort($channels);

        return [
            'schema' => 1,
            'variant' => ['code' => $this->variantCode, 'name' => $this->name],
            'shading_model' => $this->shadingModel,
            // Which resolutions the package must end up holding. The toolbox
            // generates whatever is missing by downscaling, so this is a
            // statement of intent rather than of what was supplied.
            'required_tiers' => $this->requiredTiers,
            'tiling' => $this->tiling,
            'channels' => $channels,
            'provenance' => $this->provenance,
        ];
    }

    /**
     * A digest of the request, used to tell whether a variant needs rebuilding.
     * Derived from the manifest rather than from timestamps, so re-running the
     * pipeline over unchanged inputs is free.
     */
    public function digest(): string
    {
        // The ingest time is deliberately excluded: it is recorded in the
        // package's provenance but must not make an otherwise identical build
        // look like a change, or nothing would ever be skipped.
        return hash('sha256', (string) json_encode($this->toArray()));
    }

    /**
     * The manifest plus the things that describe this particular run rather
     * than the material. Kept out of the digest for that reason.
     *
     * @return array<string, mixed>
     */
    public function toManifest(): array
    {
        return [...$this->toArray(), 'ingested_at' => $this->ingestedAt ?? now()->toRfc3339String()];
    }
}
