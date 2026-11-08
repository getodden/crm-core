<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\Contact;
use Odden\Core\Support\PropertyMerge;
use Odden\Core\Support\TenantGuard;

class UpdateContactAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidLifecycleStageTransitionException
     */
    public function execute(Contact $contact, array $attributes, ?int $userId = null): Contact
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($contact);

        $attributes = Arr::except($attributes, Contact::SYSTEM_ATTRIBUTES);

        return DB::transaction(function () use ($contact, $attributes, $userId): Contact {
            $contact->fill(PropertyMerge::onto($contact, $attributes));

            if (! $contact->isDirty('lifecycle_stage')) {
                $contact->save();

                return $contact;
            }

            $raw = $contact->getAttribute('lifecycle_stage');
            $toStage = $raw instanceof LifecycleStage ? $raw : LifecycleStage::from((string) $raw);

            $contact->setAttribute('lifecycle_stage', $contact->getOriginal('lifecycle_stage'));

            app(TransitionLifecycleStageAction::class)->execute($contact, $toStage, 'manual', $userId);

            return $contact;
        });
    }
}
