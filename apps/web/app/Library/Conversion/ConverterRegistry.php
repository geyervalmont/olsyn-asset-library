<?php

namespace App\Library\Conversion;

use App\Models\Target;
use RuntimeException;

class ConverterRegistry
{
    /** @var list<Converter> */
    private array $converters = [];

    public function register(Converter $converter): void
    {
        $this->converters[] = $converter;
    }

    public function for(Target $target): Converter
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($target)) {
                return $converter;
            }
        }

        throw new RuntimeException("No converter derives the [{$target->slug}] target.");
    }

    public function has(Target $target): bool
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($target)) {
                return true;
            }
        }

        return false;
    }
}
