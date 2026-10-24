<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Odden\Core\Support\CacheKey;

/**
 * The one place that answers "may this person see this area (of this record)", and does the "View with a reason".
 *
 * Reading is decided by the host's AreaAccess. A person who does not hold an area can still open it for one record, for a short
 * time, if the host says they may reveal: they give a reason (at least MIN_REASON characters), an AreaRevealed event is dispatched for
 * the host to log, and the grant covers that record only.
 */
final class Areas
{
    public const int MIN_REASON = 10;

    public function __construct(private readonly AreaAccess $access, private readonly DataAreas $classification) {}

    /**
     * Whether the person holds the area. With nobody signed in (a job, a command) the answer is yes: see AreaAccess.
     */
    public function holds(DataArea $area, int|string|null $userId = null): bool
    {
        $userId ??= auth()->id();

        return $userId === null || $this->access->allows($area, $userId);
    }

    /**
     * Whether the person may see this area on this record: they hold it, or opened it for this record with a reason.
     */
    public function canSee(DataArea $area, ?Model $record = null, int|string|null $userId = null): bool
    {
        $userId ??= auth()->id();

        if ($this->holds($area, $userId)) {
            return true;
        }

        return $record !== null && $userId !== null && Cache::has($this->grantKey($userId, $area, $record));
    }

    /**
     * Whether a person who does not hold the area is allowed to ask to see it.
     */
    public function mayReveal(DataArea $area, int|string|null $userId = null): bool
    {
        $userId ??= auth()->id();

        return $userId !== null && ! $this->holds($area, $userId) && $this->access->mayReveal($area, $userId);
    }

    /**
     * The areas of a record's encrypted attributes that this person cannot see, in the order they come.
     *
     * @return list<DataArea>
     */
    public function hiddenOn(Model $record, int|string|null $userId = null): array
    {
        $hidden = [];

        foreach ($this->classification->encryptedAttributesOf($record) as $area) {
            if (! in_array($area, $hidden, true) && ! $this->canSee($area, $record, $userId)) {
                $hidden[] = $area;
            }
        }

        return $hidden;
    }

    /**
     * Open an area for one record. Throws RevealNotAllowed when the person may not, or gave too short a reason.
     */
    public function reveal(DataArea $area, Model $record, string $reason, int|string|null $userId = null): void
    {
        $userId ??= auth()->id();
        $reason = trim($reason);

        if ($userId === null || ! $this->mayReveal($area, $userId)) {
            throw new RevealNotAllowed('Your role does not allow opening this area.');
        }

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw new RevealNotAllowed('Say why you need it (at least '.self::MIN_REASON.' characters).');
        }

        Cache::put($this->grantKey($userId, $area, $record), true, now()->addMinutes((int) config('odden-core.areas.reveal_minutes', 15)));

        event(new AreaRevealed($area, $userId, $record->getMorphClass(), $record->getKey(), mb_substr($reason, 0, 500)));
    }

    /**
     * Give a masked attribute its display value: the value itself, or a mask when this person cannot see its area.
     */
    public function display(Model $record, string $attribute, mixed $value = null, ?string $mask = null): mixed
    {
        $area = $this->classification->areaOfAttribute($record, $attribute);

        if ($area === null || $this->canSee($area, $record)) {
            return func_num_args() >= 3 ? $value : $record->getAttribute($attribute);
        }

        return $mask ?? self::MASK;
    }

    public const string MASK = '••••••';

    private function grantKey(int|string $userId, DataArea $area, Model $record): string
    {
        return CacheKey::for("areas.reveal.{$userId}.{$area->value}.{$record->getMorphClass()}.{$record->getKey()}");
    }
}
