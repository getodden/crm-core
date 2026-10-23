<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

/**
 * One thing a person, a script or an AI can do in the CRM, described once: its name, what it is for, the input it takes, and how
 * risky it is. The UI, the assistant and the MCP server all run it through the ActionExecutor, so validation, permission checks,
 * approval and the audit log happen in one place.
 *
 * An action wraps the existing Action classes (CreateContactAction and the like); it does not replace them.
 */
interface CrmAction
{
    /**
     * Dotted and lower-case, e.g. `contacts.create`.
     */
    public static function name(): string;

    /**
     * What the action does and when to use it. It is shown to a model, so write it for one.
     */
    public static function description(): string;

    /**
     * Laravel validation rules for the input. They are also turned into the tool schema, so the input is described once.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array;

    public function risk(): Risk;

    /**
     * Input keys that hold free text from people (a note body, a message). The audit log records that they were given but
     * not their value.
     *
     * @return list<string>
     */
    public function sensitive(): array;

    /**
     * Whether this actor may run the action with this input. The host's ActionGate has already said yes by now; this is for
     * checks that depend on the action's own input.
     *
     * @param  array<string, mixed>  $input
     */
    public function authorize(Actor $actor, array $input): bool;

    /**
     * Do the work. The input has been validated. Throw ActionFailedException for a refusal worth showing.
     *
     * @param  array<string, mixed>  $input  Only the keys named in rules().
     */
    public function handle(Actor $actor, array $input): ActionResult;
}
