<?php

// PROTOTYPE, variant B, does not run. The same Project as sites/aguet-dev, declared
// as typed properties with attributes. The class is the declaration AND the typed
// read model ($project->featured is a bool to Larastan without generated stubs).

namespace App\Content;

use App\Content\Tag;
use Illuminate\Support\Collection;
use Psyao\Kami\Content\Attributes as K;
use Psyao\Kami\Content\Entry;

#[K\Orderable]
final class Project extends Entry
{
    #[K\Text]
    public ?string $client;

    #[K\Text]
    public ?string $role;

    #[K\Textarea(inlineMarkdown: true)]
    public ?string $summary;

    #[K\Url, K\Shared]
    public ?string $url;

    #[K\Boolean, K\Shared, K\Filterable]
    public bool $featured = false;

    /** @var Collection<int, Tag> */
    #[K\Entries(of: Tag::class, creatable: true)]
    public Collection $tags;

    public function host(): ?string
    {
        return $this->url ? preg_replace('/^www\./', '', parse_url($this->url, PHP_URL_HOST)) : null;
    }
}

// Where it hurts:
// - Tabs, sections and visibleWhen have no natural home on properties
//   (#[K\Tab('hero')] on every property, or a separate layout() method anyway).
// - Translatable fields: $home->hero_title is a string in one Locale, so the type lies
//   whenever code needs another Locale.
// - Eloquent models do not declare attributes as real properties: declaring them shadows
//   __get(), so every one needs Kami magic underneath. Fights Laravel (principle 8).
// - Reusable fieldsets need traits; a trait used twice cannot prefix its properties.
