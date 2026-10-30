<?php

declare(strict_types=1);

namespace App\Quiz;

/**
 * A stable identity of a theme item for the player's progress.
 *
 * The database id of an item changes with every re-import (the import rebuilds the theme) and the Excel row number
 * shifts when a row is deleted, so neither can carry a player's progress. The content can: the same film stays the
 * same film, whatever row it is on. Editing a row resets the progress of that row only.
 */
final class ItemKey
{
    public static function of(ItemRecord $item): string
    {
        $attrs = $item->attrs;
        ksort($attrs); // MySQL JSON does not keep the key order

        return sha1(json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
