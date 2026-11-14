<?php
function getCurrentAcademicYear(): string
{
    global $conn;

    $result = $conn->query(
        "SELECT setting_value FROM portal_settings WHERE setting_name = 'current_academic_year'"
    );

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }

    $y = (int)date('Y');
    $default = $y . '-' . ($y + 1);

    $stmt = $conn->prepare(
        "INSERT INTO portal_settings (setting_name, setting_value) VALUES ('current_academic_year', ?)"
    );
    $stmt->bind_param("s", $default);
    $stmt->execute();

    return $default;
}

function advanceToNextAcademicYear(): string
{
    global $conn;

    $current = getCurrentAcademicYear();
    $parts = explode('-', $current);

    $startYear = (int)$parts[0] + 1;
    $endYear = (int)($parts[1] ?? $parts[0]) + 1;

    $next = $startYear . '-' . $endYear;

    $stmt = $conn->prepare(
        "UPDATE portal_settings SET setting_value = ? WHERE setting_name = 'current_academic_year'"
    );
    $stmt->bind_param("s", $next);
    $stmt->execute();

    return $next;
}