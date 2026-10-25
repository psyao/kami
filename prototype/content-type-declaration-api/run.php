<?php

// PROTOTYPE. php run.php [aguet-dev|psyao-ch|steve-et-aline-ch]
// Prints what Kami would derive from each site's declarations: the kami_type_<handle>
// table (ADR 0006), the References fields (ADR 0005), validation and the admin layout.

use Psyao\Kami\Content\Field;
use Psyao\Kami\Content\Layout;

require __DIR__ . '/kami.php';

// What config/kami.php would hold in each site: an explicit list, no directory scan.
$sites = [
    'aguet-dev' => ['AguetDev\Content\Home', 'AguetDev\Content\Project', 'AguetDev\Content\SkillGroup', 'AguetDev\Content\Tag'],
    'psyao-ch' => ['PsyaoCh\Content\Post', 'PsyaoCh\Content\Page', 'PsyaoCh\Content\Category', 'PsyaoCh\Content\Tag'],
    'steve-et-aline-ch' => ['SteveEtAlineCh\Content\Gallery'],
];

foreach (array_intersect_key($sites, array_flip(array_slice($argv, 1) ?: array_keys($sites))) as $site => $types) {
    require __DIR__ . "/sites/{$site}/types.php";
    echo "\n######## {$site}\n";

    foreach ($types as $class) {
        $type = new $class;
        $fields = [];
        $layout = render($type->fields(), $fields);

        echo "\n== {$type->handle()} (" . implode(', ', array_map(
            fn ($k, $v) => "{$k}: " . var_export($v, true),
            array_keys($type->describe()), $type->describe(),
        )) . ")\n";

        $stored = array_filter($fields, fn (Field $f) => $f->describe()['column'] ?? null);
        echo $stored ? "  table kami_type_{$type->handle()} (entry_id, locale ['*' | fr | en], ...)\n" : "  no table (no stored Field)\n";
        foreach ($fields as $f) {
            $d = $f->describe();
            $where = isset($d['column']) ? "column {$d['column']}" : 'kami_references';
            unset($d['column']);
            echo sprintf("    %-24s %-18s %s\n", $f->handle, $where, json_encode($d, JSON_UNESCAPED_SLASHES));
        }
        if ($layout) {
            echo "  admin layout:\n{$layout}";
        }
    }
}

function render(array $items, array &$fields, int $depth = 2): string
{
    $out = '';
    foreach ($items as $item) {
        if ($item instanceof Layout) {
            $out .= str_repeat('  ', $depth) . (new ReflectionClass($item))->getShortName() . " {$item->key}\n";
            $out .= render($item->fields, $fields, $depth + 1);
            continue;
        }
        isset($fields[$item->handle]) && throw new LogicException("Duplicate field handle {$item->handle}");
        $fields[$item->handle] = $item;
        $out .= str_repeat('  ', $depth) . "- {$item->handle}\n";
    }

    return $depth === 2 && ! array_filter($items, fn ($i) => $i instanceof Layout) ? '' : $out;
}
