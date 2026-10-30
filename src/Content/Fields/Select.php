<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Fields;

use Illuminate\Validation\Rule;

/** One option out of a list of keys; labels come from `kami-types.php`, by key. */
final class Select extends Field
{
    #[\Override]
    protected bool $translatable = false;

    /** @var array<string> */
    private array $options = [];

    /** @param array<string> $options  Keys only: `kami-types.php` holds their labels. */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @internal
     *
     * @return array<string>
     */
    public function optionKeys(): array
    {
        return $this->options;
    }

    #[\Override]
    protected function baseRules(): array
    {
        return ['string', Rule::in($this->options)];
    }
}
