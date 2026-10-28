<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Odden\Core\Models\Mention;

/**
 * Mentions of teammates in notes and comments: who can be picked with "@", and how a mention is shown.
 *
 * The people offered are the users of the host's user model, narrowed by the TenantContext (scopeUsers) when one is bound: a
 * multi-tenant host offers the active tenant's members only, a single-tenant install offers every user.
 */
final class Mentions
{
    /** How many people the "@" picker offers at once. */
    public const int PICKER_LIMIT = 20;

    /**
     * The people who can be mentioned whose name contains the search, by name.
     *
     * @return array<int, string> Names by user id.
     */
    public static function teammates(string $search = ''): array
    {
        $search = mb_strtolower(trim($search));
        $key = UserModel::make()->getKeyName();
        // "%" and "_" typed in the search are matched as themselves.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return UserModel::query()
            ->when($search !== '', fn ($query) => $query->whereRaw("lower(name) like ? escape '!'", [$pattern]))
            ->orderBy('name')
            ->orderBy($key)
            ->limit(self::PICKER_LIMIT)
            ->get()
            ->mapWithKeys(fn (Model $user): array => [(int) $user->getKey() => UserModel::displayName($user)])
            ->all();
    }

    /**
     * The names of these people, for those who can be mentioned; anyone else is left out.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array<int, string> Names by user id.
     */
    public static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        if ($ids === []) {
            return [];
        }

        return UserModel::query()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (Model $user): array => [(int) $user->getKey() => UserModel::displayName($user)])
            ->all();
    }

    /**
     * The names the people mentioned in a note or comment were written with (their labels), from its mentions relation when it
     * has one loaded. A person renamed since is still shown by the name in the text.
     *
     * @return list<string>
     */
    public static function mentionedNames(Model $mentionable): array
    {
        $mentions = $mentionable->relationLoaded('mentions')
            ? $mentionable->getRelation('mentions')
            : Mention::query()->where('mentionable_type', $mentionable->getMorphClass())->where('mentionable_id', $mentionable->getKey())->get();

        $names = [];

        foreach ($mentions as $mention) {
            if ($mention instanceof Mention) {
                $names[] = $mention->label;
            }
        }

        return $names;
    }

    /**
     * The text, escaped, with each "@Name" of the people mentioned in bold. Nothing else in the text becomes markup.
     *
     * @param  list<string>  $names
     */
    public static function highlight(?string $text, array $names): HtmlString
    {
        $escaped = e((string) $text);
        $names = array_values(array_unique(array_filter($names, fn (string $name): bool => $name !== '')));

        if ($escaped === '' || $names === []) {
            return new HtmlString($escaped);
        }

        // Longest first, so "@Jo Smith" is not cut short by "@Jo".
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/'.implode('|', array_map(fn (string $name): string => preg_quote(e('@'.$name), '/'), $names)).'/u';

        return new HtmlString((string) preg_replace_callback($pattern, fn (array $match): string => '<strong>'.$match[0].'</strong>', $escaped));
    }
}
