<?php
/**
 * Echo-loop prevention. Three layers:
 *   1) Actor filter — ignore webhook events triggered by our own integration's user.
 *   2) Hash debounce — if incoming payload's hash equals the last hash we wrote,
 *      and it's within $debounceWindow seconds, skip.
 *   3) Origin tag — (separate concern, applied at write time, not here).
 *
 * Returns ['skip' => bool, 'reason' => string]
 */
function echoGuardCheck(?array $existingMap, string $incomingHash, int $debounceWindow = 30): array {
    if (!$existingMap) return ['skip' => false, 'reason' => ''];

    $lastHash = (string)($existingMap['last_hash'] ?? '');
    $lastSyncedAt = (string)($existingMap['last_synced_at'] ?? '');

    if ($lastHash !== '' && $lastHash === $incomingHash) {
        $age = $lastSyncedAt ? (time() - strtotime($lastSyncedAt)) : PHP_INT_MAX;
        if ($age >= 0 && $age <= $debounceWindow) {
            return ['skip' => true, 'reason' => "hash match within {$age}s"];
        }
    }
    return ['skip' => false, 'reason' => ''];
}

/**
 * Verify whether a Trello webhook actor matches our own user.
 * @param string|null $actorId   action.idMemberCreator from Trello payload
 * @param string|null $ourUserId  Trello user id we authenticated as
 */
function isOurTrelloAction(?string $actorId, ?string $ourUserId): bool {
    return $actorId !== null && $ourUserId !== null && $actorId === $ourUserId;
}

/**
 * Verify whether a ClickUp webhook actor matches our own user.
 * @param array $historyItems  ClickUp webhook's history_items array
 * @param int|string $ourUserId  ClickUp user id we authenticated as
 */
function isOurClickUpAction(array $historyItems, $ourUserId): bool {
    if (!$ourUserId) return false;
    foreach ($historyItems as $h) {
        $uid = $h['user']['id'] ?? null;
        if ($uid !== null && (string)$uid === (string)$ourUserId) return true;
    }
    return false;
}
