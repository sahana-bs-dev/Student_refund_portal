function escapeHtml(value) {
    if (value === null || value === undefined) return "";
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

/**
 * Safe to embed inside onclick="...('VALUE')..." — i.e. inside a
 * single-quoted JS string literal that itself sits inside a double-quoted
 * HTML attribute.
 */
function escapeJsAttr(value) {
    if (value === null || value === undefined) return "";
    return String(value)
        .replace(/\\/g, "\\\\")
        .replace(/'/g, "\\'")
        .replace(/"/g, "&quot;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}

function performLogout(backendPath) {
    fetch(backendPath + "logout.php", { method: "POST" })
        .catch(() => {})
        .finally(() => {
            localStorage.clear();
            window.location.href = getLoginRedirectPath();
        });
}

function getLoginRedirectPath() {
    return "../index.html";
}
