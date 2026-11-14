<?php

/* ===========================================================
   Shared helpers for multi-department permission approval
=========================================================== */

/**
 * Recalculate the overall status of a team permission request
 * from its per-department approval rows and save it on
 * permission_requests (hod_status / hod_remarks / approved_at).
 *
 * Rules:
 *   any department Rejected               -> Rejected
 *   else any Clarification Needed          -> Clarification Needed
 *   else every department Approved         -> Approved
 *   else                                   -> Pending
 *
 * When a request becomes Rejected, the students are released
 * (event_participants rows removed) so they can apply again.
 *
 * Returns the new overall status.
 */
function recalcPermissionStatus(mysqli $conn, int $permission_id): string
{
    $stmt = $conn->prepare(
        "SELECT department, hod_status, hod_remarks
         FROM permission_department_approvals
         WHERE permission_id = ?
         ORDER BY department"
    );
    $stmt->bind_param("i", $permission_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $statuses = [];
    $remarks  = [];

    while ($row = $res->fetch_assoc()) {

        $statuses[] = $row['hod_status'];

        if (trim((string)$row['hod_remarks']) !== '') {
            $remarks[] = $row['department'] . ': ' . trim($row['hod_remarks']);
        }
    }

    $stmt->close();

    if (in_array('Rejected', $statuses, true)) {
        $overall = 'Rejected';
    } elseif (in_array('Clarification Needed', $statuses, true)) {
        $overall = 'Clarification Needed';
    } elseif (!empty($statuses) && count(array_unique($statuses)) === 1 && $statuses[0] === 'Approved') {
        $overall = 'Approved';
    } else {
        $overall = 'Pending';
    }

    $remarkText = empty($remarks) ? null : implode(' | ', $remarks);
    $decided    = ($overall === 'Approved' || $overall === 'Rejected') ? 1 : 0;

    $upd = $conn->prepare(
        "UPDATE permission_requests
         SET hod_status = ?,
             hod_remarks = ?,
             approved_at = IF(? = 1, NOW(), NULL)
         WHERE id = ?"
    );
    $upd->bind_param("ssii", $overall, $remarkText, $decided, $permission_id);
    $upd->execute();
    $upd->close();

    if ($overall === 'Rejected') {

        $rel = $conn->prepare(
            "DELETE FROM event_participants WHERE permission_id = ?"
        );
        $rel->bind_param("i", $permission_id);
        $rel->execute();
        $rel->close();
    }

    return $overall;
}

/**
 * Is any of these students already part of an ACTIVE permission request
 * (Pending / Approved / Clarification Needed) for the same event?
 * Same event = same name + same date; case, spaces and symbols are
 * ignored and the venue does not matter.
 *
 * This reads the real requests and members directly, so it always
 * reflects the current data.
 *
 * Returns ['usn' => clashing student, 'leader_usn' => leader of the
 * other team], preferring the leader's own clash, or null.
 */
function findEventConflict(mysqli $conn, string $event_name, string $event_date, array $participants, string $leader_usn): ?array
{
    $norm = preg_replace('/[^a-z0-9]/', '', strtolower($event_name));

    if ($norm === '' || empty($participants)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, event_name
         FROM permission_requests
         WHERE event_date = ?
           AND hod_status IN ('Pending','Approved','Clarification Needed')"
    );
    $stmt->bind_param("s", $event_date);
    $stmt->execute();
    $res = $stmt->get_result();

    $ids = [];

    while ($r = $res->fetch_assoc()) {
        if (preg_replace('/[^a-z0-9]/', '', strtolower($r['event_name'])) === $norm) {
            $ids[] = (int)$r['id'];
        }
    }

    $stmt->close();

    if (empty($ids)) {
        return null;
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));

    $q = $conn->prepare(
        "SELECT t.usn, pr.leader_usn
         FROM (
             SELECT id AS permission_id, leader_usn AS usn
               FROM permission_requests WHERE id IN ($ph)
             UNION ALL
             SELECT permission_id, usn
               FROM permission_members WHERE permission_id IN ($ph)
         ) t
         JOIN permission_requests pr ON pr.id = t.permission_id"
    );

    $params = array_merge($ids, $ids);
    $q->bind_param(str_repeat('i', count($params)), ...$params);
    $q->execute();
    $res = $q->get_result();

    $wanted = array_map('strtolower', $participants);
    $found = null;

    while ($row = $res->fetch_assoc()) {

        if (in_array(strtolower($row['usn']), $wanted, true)) {

            if (strcasecmp($row['usn'], $leader_usn) === 0) {
                $q->close();
                return $row;
            }

            if ($found === null) {
                $found = $row;
            }
        }
    }

    $q->close();

    return $found;
}

?>