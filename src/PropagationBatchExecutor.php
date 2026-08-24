<?php

/**
 * -------------------------------------------------------------------------
 * Clone Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Clone Ticket plugin for GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Clone;

/**
 * Fans one source ticket out to several destination entities in a single
 * synchronous request. This is the scoped-down version of what the PR5
 * comments in PropagationRequest and PropagationLedgerRepository were
 * anticipating: a real CronTask-driven async executor, deliberately not
 * built yet because nothing here has proven synchronous insufficient.
 * Looping this class over a few dozen destinations inside one HTTP request
 * is not slow enough to justify a queue; if that changes, the ledger's
 * claim()/PENDING contract was already written to support that later
 * without a rewrite (see PropagationLedgerRepository's class doc-comment).
 *
 * MAX_TARGETS exists to stop one click from becoming hundreds of tickets by
 * accident (e.g. every entity in a large tree selected at once). Raising
 * the number is not the fix if it is ever hit in practice -- that is the
 * signal the synchronous, human-in-the-loop model above has reached its
 * limit and the async version is actually needed.
 *
 * Each destination still goes through PropagationExecutor::execute()
 * untouched: its own local transaction, its own ledger row, its own
 * idempotent claim. One destination failing has no effect on the others.
 */
final class PropagationBatchExecutor
{
    public const MAX_TARGETS = 25;

    public function __construct(
        private readonly PropagationExecutor $executor = new PropagationExecutor()
    ) {
    }

    /**
     * @param int[] $target_entities_ids
     * @return array<int, array{success:bool, ticket_id:?int, ticket_url:?string, error_code:?string, error_message:?string}>
     *         Keyed by target_entities_id.
     */
    public function execute(
        string $source_itemtype,
        int $source_items_id,
        int $source_entities_id,
        array $target_entities_ids,
        int $requesting_users_id,
        string $batch_uuid
    ): array {
        $results = [];

        // One batch_uuid shared across every destination in this submission.
        // That still works with the ledger's unique key -- (batch_uuid,
        // source_itemtype, source_items_id, target_entities_id) -- because
        // target_entities_id is part of the key: each destination gets its
        // own row under the same batch_uuid, not a collision. A retried
        // bulk submission (same set of destinations, same stored key from
        // clone.js) safely re-claims only whichever destinations did not
        // reach a terminal state the first time; destinations that already
        // completed are recognised as an idempotent replay, exactly like a
        // single-destination retry already works.
        foreach (array_unique($target_entities_ids) as $target_entities_id) {
            $request = new PropagationRequest(
                source_itemtype: $source_itemtype,
                source_items_id: $source_items_id,
                source_entities_id: $source_entities_id,
                target_entities_id: $target_entities_id,
                requesting_users_id: $requesting_users_id,
                batch_uuid: $batch_uuid
            );

            $results[$target_entities_id] = $this->executor->execute($request);
        }

        return $results;
    }
}
