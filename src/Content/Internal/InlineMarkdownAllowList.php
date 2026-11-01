<?php

declare(strict_types=1);

namespace Psyao\Kami\Content\Internal;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Reduces to their content what CommonMark keeps and ADR 0016 does not: an image, and a link outside `http`, `https` and `mailto`. Its `allow_unsafe_links` lets `tel`, `ftp` and relative links through.
 *
 * @internal
 */
final class InlineMarkdownAllowList implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach (iterator_to_array($event->getDocument()->iterator(), false) as $node) {
                if ($node instanceof Image || ($node instanceof Link && preg_match('/^(https?|mailto):/i', $node->getUrl()) !== 1)) {
                    foreach (iterator_to_array($node->children(), false) as $child) {
                        $node->insertBefore($child);
                    }

                    $node->detach();
                }
            }
        });
    }
}
