const NAV_ICONS_FALLBACK = "•";

function initLayout(options, onReady) {
    fetch(getBackendPath() + "session_check.php")
        .then(response => response.json())
        .then(data => {
            if (data.status !== "success") {
                redirectToLogin();
                return;
            }

            if (options.role && data.role !== options.role) {
                redirectToLogin();
                return;
            }

            renderShell(data, options);

            if (typeof onReady === "function") {
                onReady(data);
            }
        })
        .catch(() => {
            redirectToLogin();
        });
}

function redirectToLogin() {
    localStorage.clear();
    window.location.href = getLoginRedirectPath();
}

function renderShell(user, options) {
    document.body.classList.add("app-shell");

    const existingContainer = document.querySelector(".container");
    if (existingContainer) {
        existingContainer.classList.add("main-content", "page-container");
    }

    const sidebar = buildSidebar(user, options);
    const topbar = buildTopbar(user, options);
    const main = document.createElement("div");
    main.className = "main";

    const modal = document.getElementById("imageModal");
    const scripts = Array.from(document.querySelectorAll("body > script"));

    while (document.body.firstChild) {
        document.body.removeChild(document.body.firstChild);
    }

    document.body.appendChild(sidebar);
    document.body.appendChild(topbar);

    if (existingContainer) {
        main.appendChild(existingContainer);
    }
    document.body.appendChild(main);

    scripts.forEach(s => document.body.appendChild(s));
    if (modal) document.body.appendChild(modal);
}

function buildSidebar(user, options) {
    const aside = document.createElement("aside");
    aside.className = "sidebar";

    const nav = options.nav || [];
    const navHtml = nav.map(item => `
        <button class="nav-item${item.active ? " active" : ""}" onclick="${item.onClick || ""}">
            <span class="icon">${item.icon || NAV_ICONS_FALLBACK}</span>
            <span>${escapeHtml(item.label)}</span>
        </button>
    `).join("");

    const initials = (user.name || user.login_id || "?").trim().charAt(0).toUpperCase();

    aside.innerHTML = `
        <div class="sidebar-brand">
            <div class="crest">SRP</div>
            <div class="brand-text">
                <span class="name">Refund Portal</span>
                <span class="sub">${escapeHtml(roleLabel(user.role))}</span>
            </div>
        </div>
        <nav class="sidebar-nav">${navHtml}</nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="avatar">${escapeHtml(initials)}</div>
                <div class="who">
                    <div class="u-name">${escapeHtml(user.name || user.login_id)}</div>
                    <div class="u-role">${escapeHtml(roleLabel(user.role))}</div>
                </div>
            </div>
            <button class="logout-btn" onclick="logout()">
                <span>⎋</span><span>Log Out</span>
            </button>
        </div>
    `;

    return aside;
}

function buildTopbar(user, options) {
    const header = document.createElement("header");
    header.className = "topbar";

    const deptChip = user.department
        ? `<span class="dept-chip">${escapeHtml(user.department)}</span>`
        : "";

    header.innerHTML = `
        <div class="topbar-title">
            <span class="eyebrow">${escapeHtml(options.eyebrow || roleLabel(user.role))}</span>
            ${escapeHtml(options.title || "Dashboard")}
        </div>
        <div class="topbar-right">
            ${deptChip}
            <span class="date-chip">${escapeHtml(todayLabel())}</span>
        </div>
    `;

    return header;
}

function roleLabel(role) {
    switch (role) {
        case "student": return "Student";
        case "admin": return "Department Admin";
        case "superadmin": return "Super Admin";
        case "hod": return "Head of Department";
        default: return role || "";
    }
}

function todayLabel() {
    const d = new Date();
    return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}
