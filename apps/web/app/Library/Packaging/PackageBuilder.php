<?php

namespace App\Library\Packaging;

/**
 * Builds a USDZ from a variant's canonical files.
 *
 * Implemented by the materials toolbox. The interface exists ahead of it so the
 * pipeline either works or fails loudly, rather than being wired up later in a
 * hurry against whatever shape the binary happens to have.
 */
interface PackageBuilder
{
    public function name(): string;

    public function version(): string;

    /**
     * Whether this builder can run right now. False is a normal state — the
     * toolbox may simply not be installed — and callers should say so plainly
     * rather than failing as though something broke.
     */
    public function available(): bool;

    public function build(BuildRequest $request): BuiltPackage;
}
