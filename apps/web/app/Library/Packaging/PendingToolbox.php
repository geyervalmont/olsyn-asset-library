<?php

namespace App\Library\Packaging;

use RuntimeException;

/**
 * The builder in use until usd-toolbox is installed.
 *
 * It exists so the pipeline around it is real, exercised and tested now, and
 * the toolbox becomes a configuration change rather than an integration. When
 * asked to build it says exactly what is missing instead of failing obscurely.
 */
class PendingToolbox implements PackageBuilder
{
    public function name(): string
    {
        return 'pending';
    }

    public function version(): string
    {
        return '0.0.0';
    }

    public function available(): bool
    {
        return false;
    }

    public function build(BuildRequest $request): BuiltPackage
    {
        throw new RuntimeException(
            'No USD toolbox is installed, so ['.$request->variantCode.'] cannot be packaged. '
            .'Set OPAL_TOOLBOX_BIN to the built binary.'
        );
    }
}
