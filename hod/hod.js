function hodAmountCell(req){
    const requested = Number(req.amount);
    const isPaid = (req.admin_status || "").trim() === "Paid";

    if(isPaid && req.refunded_amount !== null && req.refunded_amount !== undefined
       && Number(req.refunded_amount) < requested){

        const remark = req.admin_remarks
            ? `<br><small class="text-muted">Note: ${escapeHtml(req.admin_remarks)}</small>` : "";

        return `₹${Number(req.refunded_amount).toFixed(2)} refunded
                <br><small class="text-muted">Requested ₹${requested.toFixed(2)}</small>${remark}`;
    }
    return `₹${escapeHtml(req.amount)}`;
}

if (localStorage.getItem("hod_logged_in") !== "true") {
    alert("Please login first");
    window.location.href = "../index.html";
}

const backendPath = "../backend/";
const department = localStorage.getItem("hod_department");
const hodName = localStorage.getItem("hod_name") || "HOD";

if (!department) {
    alert("Please login again");
    window.location.href = "../index.html";
}

document.getElementById("display_department").innerText = department;

// One person in a "Student" column. The team leader gets a clear label so
// an HOD from another department understands why that student is listed.
function renderPerson(name, usn, isLeader) {
    const id = usn ? ` (${escapeHtml(usn)})` : "";
    const tag = isLeader ? ' <span class="team-leader-tag">Team Leader</span>' : "";
    return `<div class="stu-line">${escapeHtml(name) || "-"}${id}${tag}</div>`;
}
document.getElementById("display_name").innerText = hodName;
document.getElementById("dashboard_name").value = hodName;

let allRequests = [];

function semesterToYear(semester) {
    const sem = parseInt(semester, 10);
    if (!sem || sem < 1) return null;
    return Math.ceil(sem / 2);
}

function showConfirmModal(message, onConfirm){

    const overlay = document.getElementById("confirmModal");
    const okBtn = document.getElementById("confirmOkBtn");
    const cancelBtn = document.getElementById("confirmCancelBtn");

    document.getElementById("confirmMessage").innerText = message;
    overlay.style.display = "flex";

    function cleanup(){
        overlay.style.display = "none";
        okBtn.removeEventListener("click", handleOk);
        cancelBtn.removeEventListener("click", handleCancel);
    }

    function handleOk(){
        cleanup();
        onConfirm();
    }

    function handleCancel(){
        cleanup();
    }

    okBtn.addEventListener("click", handleOk);
    cancelBtn.addEventListener("click", handleCancel);
}

// ================= SEMESTER PROMOTION (own department only) =================

function hodPromoteStudents(){

    showConfirmModal("This will move EVERY ACTIVE student in your department to their next semester. Students already in semester 8 will be marked as graduated and lose login access. This cannot be undone automatically. Continue?", function(){

        const formData = new FormData();
        formData.append("confirm", "yes");

        fetch(backendPath + "hod_promote_students.php", {
            method: "POST",
            credentials: "include",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            alert(data.message + (data.promoted !== undefined ? " (" + data.promoted + " promoted, " + data.graduated + " graduated)" : ""));
        })
        .catch(error => {
            console.log(error);
            alert("Semester promotion failed");
        });

    });
}

function applyYearFilter() {
    const selected = document.getElementById("yearFilterSelect").value;

    const filtered = (selected === "all")
        ? allRequests
        : allRequests.filter(r => semesterToYear(r.leader_semester) === parseInt(selected, 10));

    renderTable(filtered);
}

/* ===========================================================
   SECTION SWITCHING (same pattern as the student portal)
=========================================================== */

function showSection(sectionId, clickedItem = null) {

    document.querySelectorAll(".page").forEach(sec => {
        sec.style.display = "none";
    });

    document.getElementById(sectionId).style.display = "block";

    document.querySelectorAll(".sidebar li").forEach(li => {
        li.classList.remove("active");
    });

    if (clickedItem) {
        clickedItem.classList.add("active");
    }

    const sidebar = document.querySelector(".sidebar");
        if (sectionId === "requests" || sectionId === "refunds" || sectionId === "grantReport" || sectionId === "upload") {
        sidebar.classList.add("collapsed");
    } else {
        sidebar.classList.remove("collapsed");
    }

    if (sectionId === "refunds") {
        loadRefundRequests();
    }
}

/* ===========================================================
   LOAD + DASHBOARD
=========================================================== */

function loadRequests() {
    fetch(backendPath + "hod_requests.php")
        .then(response => response.json())
        .then(data => {
            allRequests = data.data || [];
            renderDashboard(allRequests);
            applyYearFilter();
        })
        .catch(error => {
            console.log(error);
            document.getElementById("requestsTable").innerHTML = "<p>Unable to load requests</p>";
        });

    loadRefundDashboardCounts();
}

function loadRefundDashboardCounts() {
    fetch(backendPath + "hod_refund_requests.php")
        .then(response => response.json())
        .then(data => {
            renderRefundDashboard(data.data || []);
        })
        .catch(error => {
            console.log(error);
        });
}

function renderRefundDashboard(requests) {

    const counts = {
        total: requests.length,
        pending: 0,
        approved: 0,
        rejected: 0,
        clarify: 0
    };

    requests.forEach(r => {
        const status = (r.hod_status || "Pending").trim();
        if (status === "Approved") counts.approved++;
        else if (status === "Rejected") counts.rejected++;
        else if (status === "Clarification Needed") counts.clarify++;
        else counts.pending++;
    });

    document.getElementById("refundTotalCount").innerText = counts.total;
    document.getElementById("refundPendingCount").innerText = counts.pending;
    document.getElementById("refundApprovedCount").innerText = counts.approved;
    document.getElementById("refundRejectedCount").innerText = counts.rejected;
    document.getElementById("refundClarificationCount").innerText = counts.clarify;
}

function renderDashboard(requests) {

    const counts = {
        total: requests.length,
        pending: 0,
        approved: 0,
        rejected: 0,
        clarify: 0
    };

    requests.forEach(r => {
        const status = (r.hod_status || "Pending").trim();
        if (status === "Approved") counts.approved++;
        else if (status === "Rejected") counts.rejected++;
        else if (status === "Clarification Needed") counts.clarify++;
        else counts.pending++;
    });

    document.getElementById("totalCount").innerText = counts.total;
    document.getElementById("pendingCount").innerText = counts.pending;
    document.getElementById("approvedCount").innerText = counts.approved;
    document.getElementById("rejectedCount").innerText = counts.rejected;
    document.getElementById("clarificationCount").innerText = counts.clarify;

}

/* ===========================================================
   REQUESTS TABLE
=========================================================== */

function badgeClass(status) {
    if (status === "Approved") return "approved";
    if (status === "Rejected") return "rejected";
    if (status === "Clarification Needed") return "clarify";
    return "pending";
}

function formatProgramDate(startDate, endDate) {
    if (!startDate) return "-";

    let daysLabel = "";
    if (endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffDays = Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
        if (!isNaN(diffDays) && diffDays > 0) {
            daysLabel = ` (${diffDays} day${diffDays > 1 ? "s" : ""})`;
        }
    }

    return `${escapeHtml(startDate)}${daysLabel}`;
}

function formatEventLabel(category, eventName) {
    const name = escapeHtml(eventName) || "-";
    return category
        ? `<span class="event-category">${escapeHtml(category)}</span><span class="event-name">${name}</span>`
        : `<span class="event-name">${name}</span>`;
}

function renderTable(requests) {

    const container = document.getElementById("requestsTable");

    if (requests.length === 0) {
        container.innerHTML = "<p>No permission requests found</p>";
        document.getElementById("resultCount").innerText = "Showing 0 Requests";
        return;
    }

    let table = `
        <div class="table-wrapper">
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>Event Name</th>
                        <th>Type</th>
                        <th>Student</th>
                        <th>Event Dates</th>
                        <th>Venue</th>
                        <th>Status</th>
                        <th>Invitation</th>
                        <th>Supporting Doc</th>
                        <th>Remarks</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
    `;

    requests.forEach(req => {

        const status = (req.hod_status || "Pending").trim();

        const isTeam = req.team_type === "Team";
        const leaderInMyDept =
            (req.leader_department || "").trim().toLowerCase() === (department || "").trim().toLowerCase();

        const leaderLine =
            renderPerson(req.leader_name, req.leader_usn, isTeam) +
            ((isTeam && !leaderInMyDept)
                ? `<div class="dept-note">Team Leader is from ${escapeHtml(req.leader_department)} department</div>`
                : "");

        const memberLines = (req.members || [])
            .map(m => renderPerson(m.student_name, m.usn, false))
            .join("");

        const studentCell = isTeam
            ? leaderLine +
              ((memberLines && !leaderInMyDept) ? `<div class="own-students-label">Your department's students:</div>` : "") +
              memberLines
            : leaderLine;

               const invitationBtn = req.invitation_file
            ? `<button class="btn btn-sm view-btn" onclick="openImage('${backendPath}serve_file.php?column=invitation_file&id=${req.id}', '${escapeHtml(req.invitation_file)}')">View</button>`
            : "-";

        const supportingBtn = req.supporting_file
            ? `<button class="btn btn-sm view-btn" onclick="openImage('${backendPath}serve_file.php?column=supporting_file&id=${req.id}', '${escapeHtml(req.supporting_file)}')">View</button>`
            : "-";

        const locked = (status === "Approved" || status === "Rejected");
const decidedNote = (status === "Approved" || status === "Rejected")
    ? `<p class="locked-note"><i class="fa-solid fa-pen"></i> ${escapeHtml(status)}</p>`
    : "";

const actionCell = locked
    ? `<p class="locked-note"><i class="fa-solid fa-lock"></i> Approved</p>`
    : `
        <div class="action-cell">
            <textarea id="remarks-${Number(req.id)}" rows="1" placeholder="Remarks..."></textarea>
            <div class="action-buttons">
               <button class="btn btn-success" title="Approve" onclick="updateStatus(${Number(req.id)}, 'approve')"><i class="fa-solid fa-check"></i></button>
               <button class="btn btn-danger" title="Reject" onclick="updateStatus(${Number(req.id)}, 'reject')"><i class="fa-solid fa-xmark"></i></button>
               <button class="btn btn-warning" title="Clarify" onclick="updateStatus(${Number(req.id)}, 'clarify')"><i class="fa-solid fa-comment-dots"></i></button>
            </div>
        </div>
    `;

        const remarksCell = `
            ${req.hod_remarks ? `<b>HOD:</b> ${escapeHtml(req.hod_remarks)}<br>` : ""}
            ${req.student_reply ? `<small><b>Student reply:</b> ${escapeHtml(req.student_reply)}</small>` : ""}
            ${(!req.hod_remarks && !req.student_reply) ? "-" : ""}
        `;

        table += `
            <tr>
                <td>${formatEventLabel(req.event_type_name, req.event_name)}</td>
                <td>${escapeHtml(req.team_type) || "-"}</td>
                <td>${studentCell}</td>
                <td>${formatProgramDate(req.event_date, req.event_end_date)}</td>
                <td>${escapeHtml(req.venue)}</td>
                <td><span class="badge ${badgeClass(status)}">${escapeHtml(status)}</span></td>
                <td>${invitationBtn}</td>
                <td>${supportingBtn}</td>
                <td class="remarks-cell">${remarksCell}</td>
                <td>${actionCell}</td>
            </tr>
        `;
    });

    table += `
                </tbody>
            </table>
        </div>
    `;

    container.innerHTML = table;
    document.getElementById("resultCount").innerText = `Showing ${requests.length} Requests`;
}

function updateStatus(id, action) {

    const remarksField = document.getElementById("remarks-" + id);
    const remarks = remarksField ? remarksField.value.trim() : "";

    const formData = new FormData();
    formData.append("id", id);
    formData.append("action", action);
    formData.append("remarks", remarks);

    fetch(backendPath + "hod_action.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        loadRequests();
    })
    .catch(error => {
        console.log(error);
        alert("Action failed");
    });
}

function showGroupDetails(eventName, teamName, memberUsns) {
    alert(`Event: ${eventName}\n\nTeam: ${teamName}\n\nMembers: ${memberUsns}`);
}

function searchRequests() {

    const input = document.getElementById("searchInput").value.toLowerCase();
    const rows = document.querySelectorAll("#requestsTable .requests-table tbody tr");

    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        if (text.includes(input)) {
            row.style.display = "";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });

    document.getElementById("resultCount").innerText = `Showing ${visibleCount} Requests`;
}

function logout() {
    performLogout(backendPath);
}

function closeModal() {
    document.getElementById("imageModal").style.display = "none";
}

let zoomLevel = 1;

function openImage(path, filename = "") {
    const img = document.getElementById("modalImage");
    const pdf = document.getElementById("modalPdf");

    const isPdf = (filename || path).toLowerCase().endsWith(".pdf");

    if (isPdf) {
        img.style.display = "none";
        img.src = "";

        pdf.src = path;
        pdf.style.display = "block";
    } else {
        pdf.style.display = "none";
        pdf.src = "";

        img.src = path;
        img.style.display = "block";
        img.style.transform = "scale(1)";
        zoomLevel = 1;
    }

    document.getElementById("imageModal").style.display = "flex";
}

document.addEventListener("DOMContentLoaded", () => {

    const img = document.getElementById("modalImage");

    img.addEventListener("wheel", function (e) {

        e.preventDefault();

        if (e.deltaY < 0) {
            zoomLevel += 0.1;
        } else {
            zoomLevel -= 0.1;
        }

        zoomLevel = Math.max(0.5, Math.min(zoomLevel, 5));

        img.style.transform = `scale(${zoomLevel})`;

    });

});

/* ===========================================================
   REFUND REQUESTS
=========================================================== */

let allRefundRequests = [];
let currentRefundView = "active";
let currentRefundRequests = [];

function loadRefundRequests() {
    showRefundActive();
}

// ================= ACTIVE / ARCHIVE (same pattern as admin.js) =================

function showRefundActive() {

    currentRefundView = "active";

    fetch(backendPath + "hod_refund_requests.php")
        .then(response => response.json())
        .then(data => {

            allRefundRequests = data.data || [];
            currentRefundRequests = allRefundRequests;

            applyRefundYearFilter();

        })
        .catch(error => {

            console.log(error);

            document.getElementById("refundsTable").innerHTML =
                "<p>Unable to load refund requests</p>";

        });
}

function applyRefundYearFilter() {

    const select = document.getElementById("refundYearFilterSelect");

    if (!select) return;

    const selectedYear = select.value;

    const filteredRequests =
        selectedYear === "all"
            ? currentRefundRequests
            : currentRefundRequests.filter(req =>
                semesterToYear(req.semester) === parseInt(selectedYear, 10)
            );

    if (currentRefundView === "archive") {
        renderRefundArchiveTable(filteredRequests);
    } else {
        renderRefundTable(filteredRequests);
    }
}

function showRefundArchive() {

    currentRefundView = "archive";

    fetch(backendPath + "hod_archive_requests.php")
        .then(response => response.json())
        .then(data => {

            currentRefundRequests = data.data || [];

            applyRefundYearFilter();

        })
        .catch(error => {

            console.log(error);

            document.getElementById("refundsTable").innerHTML =
                "<p>Unable to load archived requests</p>";

        });
}

function downloadRefundExcel() {

    const select = document.getElementById("refundYearFilterSelect");

    // If the year filter does not exist, default to all years
    const selectedYear = select ? select.value : "all";

    window.location.href =
        backendPath +
        "download_excel.php?type=hod&year=" +
        encodeURIComponent(selectedYear);
}

/* ===========================================================
   STUDENT GRANT REPORT
=========================================================== */

let allGrantReportRows = [];

let grantReportTable = null;

function initGrantReportTable() {

    if ($.fn.DataTable.isDataTable("#grantReportDataTable")) {
        grantReportTable = $("#grantReportDataTable").DataTable();
        return;
    }

    grantReportTable = $("#grantReportDataTable").DataTable({
        dom: "Bfrtip",
        buttons: [
        {
            text: "Export",
            action: function (e, dt, node, config) {

                const select = document.getElementById("grantReportYearFilterSelect");
                const selectedYear = select ? select.value : "all";

                let url = backendPath + "student_grant_report.php";

                if (selectedYear !== "all") {
                    url += "?year=" + encodeURIComponent(selectedYear);
                }

                window.location.href = url;
            }
        }
        ],
        data: [],
        order: [],
        language: {
            emptyTable: "No paid refunds found for this academic year"
        },
        columns: [
            {
                data: null,
                title: "S.No",
                orderable: false,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                }
            },
            { data: "usn", title: "USN" },
            { data: "name", title: "Student Name" },
            {
                data: "semesters",
                title: "Semester",
                render: function (groups, type) {
                    groups = groups || [];
                    if (type === "display") {
                        return groups
                            .map(g => `Sem ${escapeHtml(String(g.semester))} - ${g.count} req(s)`)
                            .join("<br>");
                    }

                     return groups
                        .map(g => g.semester)
                        .join("\n\n");
                }
            },
            {
                data: "semesters",
                title: "Events Refunded",
                orderable: false,
                render: function (groups, type) {
                    groups = groups || [];
                    if (type === "display") {
                        return groups.map(g => {
                            const header = `<b>Sem ${escapeHtml(String(g.semester))}</b>`;
                            const events = (g.events || []).map(ev => escapeHtml(ev)).join("<br>");
                            return `${header}<br>${events}`;
                        }).join("<br><br>");
                    }
                     return groups
                        .map(g => `Sem ${g.semester}: ` + (g.events || []).join("\n"))
                        .join("\n\n");
                }
            },
            {
                data: "total",
                title: "Total Amount Granted",
                render: function (value, type) {
                    if (type === "display") {
                        return "₹" + Number(value).toFixed(2);
                    }
                    return Number(value);
                }
            }
        ]
    });
}

function loadGrantReport() {

    if (!grantReportTable) {
        initGrantReportTable();
    }

    const select = document.getElementById("grantReportYearFilterSelect");
    const selectedYear = select ? select.value : "all";

    let url = backendPath + "student_grant_report.php?format=json";

    if (selectedYear !== "all") {
        url += "&year=" + encodeURIComponent(selectedYear);
    }

    fetch(url)
        .then(response => response.json())
        .then(data => {

            if (data.status !== "success") {
                allGrantReportRows = [];
                grantReportTable.clear().draw();
                return;
            }

            allGrantReportRows = data.data || [];

            grantReportTable.clear();
            grantReportTable.rows.add(allGrantReportRows);
            grantReportTable.draw();

        })
        .catch(error => {
            console.log(error);
            grantReportTable.clear().draw();
        });
}

function applyGrantReportYearFilter() {

    const select = document.getElementById("grantReportYearFilterSelect");

    if (!select) {
        return;
    }

    const selectedYear = select.value;

    let url = backendPath + "student_grant_report.php?format=json";

    if (selectedYear !== "all") {
        url += "&year=" + encodeURIComponent(selectedYear);
    }

    fetch(url)
        .then(response => response.json())
        .then(data => {

            if (data.status !== "success") {
                allGrantReportRows = [];
                grantReportTable.clear().draw();
                return;
            }

            allGrantReportRows = data.data || [];

            grantReportTable.search("");
            grantReportTable.clear();
            grantReportTable.rows.add(allGrantReportRows);
            grantReportTable.draw();

        })
        .catch(error => {
            console.log(error);
            grantReportTable.clear().draw();
        });
}

function renderRefundArchiveTable(requests) {

    const container = document.getElementById("refundsTable");

    if (requests.length === 0) {
        container.innerHTML = "<p>No archived refund requests found</p>";
        document.getElementById("refundResultCount").innerText = "Showing 0 Requests";
        return;
    }

    let table = `
        <div class="table-wrapper">
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>USN</th>
                        <th>Name</th>
                        <th>Event Name</th>
                        <th>Amount</th>
                        <th>HOD Status</th>
                        <th>Admin Status</th>
                        <th>Program Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
    `;

    requests.forEach((req, index) => {

        const hodStatus = (req.hod_status || "Pending").trim();

        const isPaid = req.admin_status === "Paid";

        const restoreCell = isPaid
            ? `<span class="locked-note"><i class="fa-solid fa-lock"></i> Paid</span>`
            : `<button class="btn btn-secondary" onclick="restoreRefundRequest(${Number(req.id)})">Restore</button>`;

        table += `
            <tr>
                <td class="sno-cell">${index + 1}</td>
                <td>${escapeHtml(req.usn)}</td>
                <td>${escapeHtml(req.name)}</td>
                <td>${formatEventLabel(req.event_category, req.event_name)}</td>
                <td>${hodAmountCell(req)}</td>
                <td><span class="badge ${badgeClass(hodStatus)}">${escapeHtml(hodStatus)}</span></td>
                <td>${escapeHtml(req.admin_status || "Pending")}</td>
                <td>${escapeHtml(req.program_start_date) || "-"}</td>
                <td>${restoreCell}</td>
            </tr>
        `;
    });

    table += `
                </tbody>
            </table>
        </div>
    `;

    container.innerHTML = table;
    document.getElementById("refundResultCount").innerText = `Showing ${requests.length} Requests`;
}

function renderRefundTable(requests) {

    const container = document.getElementById("refundsTable");

    if (requests.length === 0) {
        container.innerHTML = "<p>No refund requests found</p>";
        document.getElementById("refundResultCount").innerText = "Showing 0 Requests";
        return;
    }

    let table = `
        <div class="table-wrapper">
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>USN</th>
                        <th>Name</th>
                        <th>Event Name</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Per Student</th>
                        <th>Status</th>
                        <th>Program Date</th>
                        <th>Payment Proof</th>
                        <th>Participation Proof</th>
                        <th>Bank Proof</th>
                        <th>Remarks</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
    `;

    requests.forEach((req, index) => {

        const status = (req.hod_status || "Pending").trim();

        const isGroupRefund = req.refund_type === "Group";
        const shownPeople = [{ name: req.name, usn: req.usn }]
            .concat((req.members || []).map(m => ({ name: m.student_name, usn: m.usn })));
        const leaderShown = shownPeople.some(p => p.usn === req.leader_usn);

        let namesCell = shownPeople
            .map(p => renderPerson(p.name, "", isGroupRefund && p.usn === req.leader_usn))
            .join("");

        // Team leader belongs to another department: show them first, with a label
        if (isGroupRefund && req.leader_usn && !leaderShown) {
            namesCell =
                renderPerson(req.leader_name, "", true) +
                `<div class="own-students-label">Your department's students:</div>` +
                namesCell;
        }

               const paymentBtn = req.payment_proof
            ? `<button class="btn btn-sm view-btn" onclick="openImage('${backendPath}serve_file.php?column=payment_proof&id=${req.id}', '${escapeHtml(req.payment_proof)}')"
>View</button>`
            : "-";

        const participationBtn = req.participation_proof
            ? `<button class="btn btn-sm view-btn" onclick="openImage('${backendPath}serve_file.php?column=participation_proof&id=${req.id}', '${escapeHtml(req.participation_proof)}')"
>View</button>`
            : "-";

        const bankBtn = req.bank_proof
            ? `<button class="btn btn-sm view-btn" onclick="openImage('${backendPath}serve_file.php?column=bank_proof&id=${req.id}', '${escapeHtml(req.bank_proof)}')">View</button>`
            : "-";

        const locked = req.admin_status === "Paid";

        const decidedNote = (status === "Approved" || status === "Rejected")
            ? `<p class="locked-note"><i class="fa-solid fa-pen"></i> Current: <b>${escapeHtml(status)}</b></p>`
            : "";

                const approveClass = status === "Approved" ? "btn btn-muted" : "btn btn-success";
        const rejectClass = status === "Rejected" ? "btn btn-muted" : "btn btn-danger";

        const actionCell = locked
            ? `<p class="locked-note"><i class="fa-solid fa-lock"></i> Locked (Paid)</p>`
            : `
                <div class="action-cell">
                    ${decidedNote}
                    <textarea id="refund-remarks-${Number(req.id)}" rows="2" placeholder="Remarks (optional)"></textarea>
                    <div class="action-buttons">
                       <button class="${approveClass}" title="Approve" onclick="updateRefundStatus(${Number(req.id)}, 'approve')"><i class="fa-solid fa-check"></i></button>
                       <button class="${rejectClass}" title="Reject" onclick="updateRefundStatus(${Number(req.id)}, 'reject')"><i class="fa-solid fa-xmark"></i></button>
                       <button class="btn btn-warning" title="Clarify" onclick="updateRefundStatus(${Number(req.id)}, 'clarify')"><i class="fa-solid fa-comment-dots"></i></button>
                    </div>
                </div>
            `;

        const remarksCell = `
            ${req.hod_remarks ? `<b>HOD:</b> ${escapeHtml(req.hod_remarks)}<br>` : ""}
            ${req.student_reply ? `<small><b>Student reply:</b> ${escapeHtml(req.student_reply)}</small>` : ""}
            ${(!req.hod_remarks && !req.student_reply) ? "-" : ""}
        `;

        table += `
            <tr>
                <td class="sno-cell">${index + 1}</td>
                <td>${escapeHtml(req.usn)}</td>
                <td>${namesCell}</td>
                <td>${formatEventLabel(req.event_category, req.event_name)}</td>
                <td>${escapeHtml(req.refund_type)}</td>
                <td>${hodAmountCell(req)}</td>
                <td>₹${escapeHtml(req.per_student_amount)}</td>
                <td><span class="badge ${badgeClass(status)}">${escapeHtml(status)}</span></td>
                <td>${escapeHtml(req.program_start_date) || "-"}</td>
                <td>${paymentBtn}</td>
                <td>${participationBtn}</td>
                <td>${bankBtn}</td>
                <td class="remarks-cell">${remarksCell}</td>
                <td>${actionCell}</td>
            </tr>
        `;
    });

    table += `
                </tbody>
            </table>
        </div>
    `;

    container.innerHTML = table;
    document.getElementById("refundResultCount").innerText = `Showing ${requests.length} Requests`;
}

function updateRefundStatus(id, action) {

    const remarksField = document.getElementById("refund-remarks-" + id);
    const remarks = remarksField ? remarksField.value.trim() : "";

    const formData = new FormData();
    formData.append("id", id);
    formData.append("action", action);
    formData.append("remarks", remarks);

    fetch(backendPath + "hod_refund_action.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        loadRefundRequests();
    })
    .catch(error => {
        console.log(error);
        alert("Action failed");
    });
}


function searchRefundRequests() {

    const input = document.getElementById("refundSearchInput").value.toLowerCase();
    const rows = document.querySelectorAll("#refundsTable .requests-table tbody tr");

    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        if (text.includes(input)) {
            row.style.display = "";
            visibleCount++;
            const snoCell = row.querySelector(".sno-cell");
            if (snoCell) snoCell.innerText = visibleCount;
        } else {
            row.style.display = "none";
        }
    });

    document.getElementById("refundResultCount").innerText = `Showing ${visibleCount} Requests`;
}

loadRequests();

function restoreRefundRequest(id) {

    if (!confirm("Restore this refund request to Active? You'll be able to change its status again until payment is made.")) {
        return;
    }

    const formData = new FormData();
    formData.append("id", id);
    formData.append("type", "hod");

    fetch(backendPath + "restore_request.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        showRefundArchive();
    })
    .catch(error => {
        console.log(error);
        alert("Restore failed");
    });
}

function toggleSidebar() {
    document.querySelector(".sidebar").classList.toggle("collapsed");
}

/* ===========================================================
   UPLOAD STUDENTS (Excel download/upload, replaces manual entry)
=========================================================== */

function downloadStudentTemplate() {
    window.location.href = backendPath + "download_student_template.php";
}

function onStudentFileChosen() {
    const fileInput = document.getElementById("studentExcelFile");
    const label = document.getElementById("studentFileNameLabel");
    label.innerText = fileInput.files.length ? fileInput.files[0].name : "No file chosen";
}

function uploadStudentExcel() {

    const fileInput = document.getElementById("studentExcelFile");
    const statusEl = document.getElementById("uploadStatusMsg");
    const resultsEl = document.getElementById("uploadResultsTable");

    const file = fileInput.files[0];

    if (!file) {
        alert("Please choose an Excel file first.");
        return;
    }

    statusEl.innerText = "Reading file...";
    resultsEl.innerHTML = "";

    const reader = new FileReader();

    reader.onload = function (e) {

        let workbook;

        try {
            const data = new Uint8Array(e.target.result);
            workbook = XLSX.read(data, { type: "array" });
        } catch (err) {
            console.log(err);
            statusEl.innerText = "Could not read this file. Please upload a valid .xlsx file.";
            return;
        }

        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(sheet, { defval: "" });

        if (!rows.length) {
            statusEl.innerText = "No student rows found in this file.";
            return;
        }

        const students = rows.map(row => ({
            usn: String(row["USN"] ?? "").trim(),
            name: String(row["Name"] ?? "").trim(),
            semester: String(row["Semester"] ?? "").trim(),
            password: String(row["Password"] ?? "").trim()
        }));

        statusEl.innerText = "Uploading...";

        const formData = new FormData();
        formData.append("students", JSON.stringify(students));

        fetch(backendPath + "upload_students.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {

            if (data.status !== "success") {
                statusEl.innerText = data.message || "Upload failed.";
                return;
            }

            statusEl.innerText = "";
            resultsEl.innerHTML = "";

            alert(
                `Inserted: ${data.inserted} | Skipped (duplicate USN): ${data.duplicates} | Skipped (invalid row): ${data.invalid}`
            );

            fileInput.value = "";
            document.getElementById("studentFileNameLabel").innerText = "No file chosen";
        })
        .catch(error => {
            console.log(error);
            statusEl.innerText = "Upload failed. Please try again.";
        });
    };

    reader.readAsArrayBuffer(file);
}

function renderUploadResults(details) {

    const resultsEl = document.getElementById("uploadResultsTable");

    if (!details.length) {
        resultsEl.innerHTML = "";
        return;
    }

    let html = `<div class="table-wrapper"><table class="requests-table">
        <thead>
            <tr><th>USN</th><th>Name</th><th>Status</th><th>Reason</th></tr>
        </thead>
        <tbody>`;

    details.forEach(row => {

        let badgeClass = "pending";
        if (row.status === "Inserted") badgeClass = "approved";
        if (row.status === "Skipped") badgeClass = "rejected";

        html += `<tr>
            <td>${escapeHtml(row.usn)}</td>
            <td>${escapeHtml(row.name)}</td>
            <td><span class="badge ${badgeClass}">${escapeHtml(row.status)}</span></td>
            <td>${escapeHtml(row.reason || "-")}</td>
        </tr>`;
    });

    html += "</tbody></table></div>";
    resultsEl.innerHTML = html;
}