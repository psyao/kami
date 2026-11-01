<?php

declare(strict_types=1);

namespace Psyao\Kami\Tests\Volume;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entries by batched inserts rather than `SaveEntry`, to stay fast, in the shape `SaveEntry` stores: two Locales, Shared values, relations in pick order.
 * Nearly all are one orderable type whose Positions tie, the worst case a site can reach: a type made orderable after the fact.
 */
final class Dataset
{
    public const int ENTRIES = 30_000;

    private const int BATCH = 500;

    private const array LOCALES = ['fr', 'en'];

    /** @var array<string, int> */
    private array $counts = ['tag' => 0, 'article' => 0, 'project' => 0];

    /** @var list<string> */
    private array $tags = [];

    /** @var list<string> */
    private array $articles = [];

    /** Up to this many Entries: one in sixty is a Tag, one in sixty an Article, the rest Projects. */
    public function fill(int $entries): void
    {
        $this->add('tag', (int) ($entries / 60));
        $this->add('article', (int) ($entries / 60));
        $this->add('project', $entries - (int) ($entries / 60) * 2);
    }

    private function add(string $type, int $upTo): void
    {
        foreach (array_chunk($upTo > $this->counts[$type] ? range($this->counts[$type] + 1, $upTo) : [], self::BATCH) as $numbers) {
            $tables = ['kami_entries' => [], 'kami_translations' => [], 'kami_paths' => [], "kami_type_{$type}" => [], 'kami_references' => []];

            foreach ($numbers as $number) {
                $this->entry($type, $number, $tables);
            }

            foreach ($tables as $table => $rows) {
                foreach (array_chunk($rows, self::BATCH) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        }

        $this->counts[$type] = $upTo;
    }

    /** @param array<string, list<array<string, mixed>>> $tables */
    private function entry(string $type, int $number, array &$tables): void
    {
        $id = (string) Str::ulid();
        $now = Carbon::now('UTC');
        $tables['kami_entries'][] = ['id' => $id, 'site' => 'default', 'type' => $type, 'position' => 0, 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now];
        $translation = ['status' => 'published', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'entry_id' => $id];

        if ($type === 'tag') {
            $this->tags[] = $id;
            $tables['kami_translations'][] = [...$translation, 'id' => (string) Str::ulid(), 'locale' => '*', 'title' => "Tag {$number}", 'slug' => null];
            unset($tables['kami_type_tag']);

            return;
        }

        if ($type === 'article') {
            $this->articles[] = $id;
        }

        $tables["kami_type_{$type}"][] = ['entry_id' => $id, 'locale' => '*', ...($type === 'article' ? ['author' => "Author {$number}", 'summary' => null] : ['client' => "Client {$number}", 'story' => null])];
        $prefix = $type === 'article' ? 'articles' : 'project';

        foreach (self::LOCALES as $locale) {
            $slug = "{$type}-{$number}";
            $tables['kami_translations'][] = [...$translation, 'id' => (string) Str::ulid(), 'locale' => $locale, 'title' => Str::headline($slug), 'slug' => $slug];
            $tables['kami_paths'][] = ['id' => (string) Str::ulid(), 'site' => 'default', 'locale' => $locale, 'path' => "{$prefix}/{$slug}", 'entry_id' => $id, 'status' => 200];
            $tables["kami_type_{$type}"][] = ['entry_id' => $id, 'locale' => $locale, ...($type === 'article' ? ['author' => null, 'summary' => "Summary {$number}"] : ['client' => null, 'story' => "Story {$number}"])];

        }

        if ($type === 'project') {
            $targets = ['tags' => [$this->tags[$number % count($this->tags)], $this->tags[($number + 1) % count($this->tags)], $this->tags[($number + 2) % count($this->tags)]], 'related' => [$this->articles[$number % count($this->articles)]]];

            foreach ($targets as $field => $ids) {
                foreach ($ids as $position => $target) {
                    $tables['kami_references'][] = ['id' => (string) Str::ulid(), 'source_entry_id' => $id, 'field' => $field, 'locale' => null, 'target_entry_id' => $target, 'target_media_id' => null, 'position' => $position];
                }
            }
        }
    }
}
