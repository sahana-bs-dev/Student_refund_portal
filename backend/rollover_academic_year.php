<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "auth_check.php";

require_login(['admin']);

header("Content-Type: application/json");

$confirm = $_POST['confirm'] ?? '';

if ($confirm !== 'yes') {
    echo json_encode([
        "status" => "error",
        "message" => "Confirmation required."
    ]);
    exit;
}

$closing_academic_year = getCurrentAcademicYear();
$parts = explode('-', $closing_academic_year);
$startYear = (int)$parts[0] + 1;
$endYear = (int)($parts[1] ?? $parts[0]) + 1;
$academic_year = $startYear . '-' . $endYear;

$conn->begin_transaction();

try {

    $stmt = $conn->prepare(
    "INSERT INTO permission_requests_history
     (id, leader_usn, leader_department, leader_semester, event_type_id,
      event_name, event_date, event_end_date, registration_deadline,
      venue, city, mode, reason, registration_fee, team_type, team_name,
      team_size, hod_status, hod_remarks,
      student_reply, replied_at, approved_at, created_at, academic_year)
     SELECT id, leader_usn, leader_department, leader_semester, event_type_id,
            event_name, event_date, event_end_date, registration_deadline,
            venue, city, mode, reason, registration_fee, team_type, team_name,
            team_size, hod_status, hod_remarks,
            student_reply, replied_at, approved_at, created_at, academic_year
     FROM permission_requests WHERE academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "INSERT INTO permission_members_history
         SELECT pm.* FROM permission_members pm
         JOIN permission_requests pr ON pr.id = pm.permission_id
         WHERE pr.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    /* =======================================================
       REFUND REQUESTS (+ members)
    ======================================================= */

$stmt = $conn->prepare(
    "INSERT INTO refund_requests_history
     (id, usn, permission_request_id, name, department, semester,
      refund_type, event_name, team_name, refund_reason, amount,
      number_of_students, per_student_amount, hod_status, hod_remarks,
      hod_archived, admin_status, admin_archived, created_at, academic_year, refunded_amount, admin_remarks)
     SELECT id, usn, permission_request_id, name, department, semester,
            refund_type, event_name, team_name, refund_reason, amount,
            number_of_students, per_student_amount, hod_status, hod_remarks,
            hod_archived, admin_status, admin_archived, created_at, academic_year, refunded_amount, admin_remarks\n FROM refund_requests
     WHERE academic_year != ?"
);
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "INSERT INTO refund_members_history
         SELECT rm.* FROM refund_members rm
         JOIN refund_requests r ON r.id = rm.refund_id
         WHERE r.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    /* =======================================================
       DEPARTMENT APPROVALS AND REFUND SHARES (kept in history)
    ======================================================= */

    $stmt = $conn->prepare(
        "INSERT INTO permission_department_approvals_history
         SELECT pda.* FROM permission_department_approvals pda
         JOIN permission_requests pr ON pr.id = pda.permission_id
         WHERE pr.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "INSERT INTO refund_shares_history
         SELECT sh.* FROM refund_shares sh
         JOIN refund_requests r ON r.id = sh.refund_id
         WHERE r.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    /* =======================================================
       DELETE THE UPLOADED FILE BYTES FOR EVERYTHING ARCHIVED
    ======================================================= */

    $stmt = $conn->prepare(
        "DELETE FROM attachments
         WHERE owner_type = 'permission_request'
           AND owner_id IN (
               SELECT id FROM permission_requests_history WHERE academic_year = ?
           )"
    );
    $stmt->bind_param("s", $closing_academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "DELETE FROM attachments
         WHERE owner_type = 'refund_request'
           AND owner_id IN (
               SELECT id FROM refund_requests_history WHERE academic_year = ?
           )"
    );
    $stmt->bind_param("s", $closing_academic_year);
    $stmt->execute();

    /* =======================================================
       NOW REMOVE THE OLD-YEAR ROWS FROM THE LIVE TABLES
    ======================================================= */

    $stmt = $conn->prepare(
        "DELETE sh FROM refund_shares sh
         JOIN refund_requests r ON r.id = sh.refund_id
         WHERE r.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "DELETE rm FROM refund_members rm
         JOIN refund_requests r ON r.id = rm.refund_id
         WHERE r.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "DELETE FROM refund_requests WHERE academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $refundsRemoved = $stmt->affected_rows;

    $stmt = $conn->prepare(
        "DELETE ep FROM event_participants ep
         JOIN permission_requests pr ON pr.id = ep.permission_id
         WHERE pr.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "DELETE pda FROM permission_department_approvals pda
         JOIN permission_requests pr ON pr.id = pda.permission_id
         WHERE pr.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "DELETE pm FROM permission_members pm
         JOIN permission_requests pr ON pr.id = pm.permission_id
         WHERE pr.academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();

    $stmt = $conn->prepare(
        "DELETE FROM permission_requests WHERE academic_year != ?"
    );
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $permissionsRemoved = $stmt->affected_rows;

    $conn->commit();

    $new_academic_year = advanceToNextAcademicYear();

    echo json_encode([
        "status" => "success",
        "message" => "Previous academic year archived, files cleared, and removed from active tables.",
        "previous_academic_year" => $closing_academic_year,
        "current_academic_year" => $new_academic_year,
        "refund_requests_archived" => $refundsRemoved,
        "permission_requests_archived" => $permissionsRemoved
    ]);

} catch (Throwable $e) {

    $conn->rollback();

    echo json_encode([
        "status" => "error",
        "message" => "Rollover failed, nothing was changed: " . $e->getMessage()
    ]);
}

?>