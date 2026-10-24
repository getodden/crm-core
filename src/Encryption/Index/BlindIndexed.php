<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

/**
 * A model whose encrypted columns are found again through the search index (see HasBlindIndexes).
 */
interface BlindIndexed
{
    /**
     * What this record is indexed under: index field => kind => the plain tokens.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function blindIndexEntries(): array;

    /**
     * Whether the column can be looked up by its whole value.
     */
    public function hasExactBlindIndex(string $column): bool;
}
