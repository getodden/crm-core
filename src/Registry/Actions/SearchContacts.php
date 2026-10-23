<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;
use Odden\Core\Registry\Like;
use Odden\Core\Registry\Risk;

class SearchContacts extends BaseAction
{
    public const int LIMIT = 25;

    public static function name(): string
    {
        return 'contacts.search';
    }

    public static function description(): string
    {
        return 'Find contacts by name, email address or phone number. Use it before creating or changing a contact, to get its id. Returns at most '.self::LIMIT.' matches.';
    }

    public static function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIMIT],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $contacts = Like::any(Contact::query(), ['first_name', 'last_name', 'email', 'phone'], (string) $input['query'])
            ->orderBy('id')
            ->limit(min(self::LIMIT, (int) ($input['limit'] ?? self::LIMIT)))
            ->get();

        return ActionResult::completed([
            'contacts' => $contacts->map(fn (Contact $c): array => [
                'id' => $c->id,
                'name' => $c->full_name,
                'email' => $c->email,
                'phone' => $c->phone,
                'job_title' => $c->job_title,
                'lifecycle_stage' => $c->lifecycle_stage->value,
            ])->all(),
        ], $contacts->isEmpty() ? 'No contacts matched.' : null);
    }
}
