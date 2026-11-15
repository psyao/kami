# Broken

An example that throws, as #587's did: a RichText Field refuses HTML.

<!-- kami:example -->
```php
use App\Content\Article;
use Psyao\Kami\Content\Actions\SaveEntry;

resolve(SaveEntry::class)->handle(Article::class, ['title' => 'Hello', 'author' => 'Ada', 'body' => '<p>Hello</p>']);
```
