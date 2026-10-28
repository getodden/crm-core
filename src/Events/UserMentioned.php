<?php

declare(strict_types=1);

namespace Odden\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone was picked with "@" in a note or a comment, for the first time in it: editing it later fires this only for people added
 * then. A host application listens to tell the person (who may be the author: that is the listener's choice).
 *
 * It carries no text: a note's text is encrypted and may sit in a data area the person cannot see. A listener that shows any of it
 * reads it again from $mentionable, under the mentioned person's own permissions. Dispatched once the save is committed.
 */
class UserMentioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Model  $mentionable  The note (an activity) or comment the person is mentioned in.
     * @param  Model  $subject  The record it is on: a contact, company or deal for a note, a ticket for a comment.
     */
    public function __construct(
        public int $mentionedUserId,
        public ?int $actorId,
        public Model $mentionable,
        public Model $subject,
    ) {}
}
