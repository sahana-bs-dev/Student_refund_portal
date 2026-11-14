const backendPath = "../backend/";

const usn = localStorage.getItem("student_usn");

if (!usn) {
    alert("Please login first");
    window.location.href = "../index.html";
}

/* ==========================================
   DATE FORMATTING HELPER
========================================== */

function formatDateTime(raw) {

    if (!raw) {
        return "—";
    }

    const parsed = new Date(String(raw).replace(" ", "T"));

    if (isNaN(parsed.getTime())) {
        return escapeHtml(raw);
    }

    const datePart = parsed.toLocaleDateString("en-IN", {
        day: "2-digit",
        month: "short",
        year: "numeric"
    });

    const timePart = parsed.toLocaleTimeString("en-IN", {
        hour: "2-digit",
        minute: "2-digit",
        hour12: true
    });

    return `${escapeHtml(datePart)}<br><span class="date-time">${escapeHtml(timePart)}</span>`;
}

function formatDateShort(raw) {

    if (!raw) {
        return "—";
    }

    const parsed = new Date(String(raw).replace(" ", "T"));

    if (isNaN(parsed.getTime())) {
        return escapeHtml(raw);
    }

    return escapeHtml(parsed.toLocaleDateString("en-IN", {
        day: "2-digit",
        month: "short",
        year: "numeric"
    }));
}

function studentAmountCell(req){
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


/* ==========================================
   PAGE DETECTION
========================================== */

const isStudentPage = !!document.getElementById("dashboard");

document.addEventListener("DOMContentLoaded", function () {

    const displayUsnField = document.getElementById("display_usn");
    if (displayUsnField) {
        displayUsnField.innerText = usn;
    }

    const studentUsnField = document.getElementById("student_usn");
    if (studentUsnField) {
        studentUsnField.value = usn;
    }

    const permissionUsnField = document.getElementById("permission_usn");
    if (permissionUsnField) {
        permissionUsnField.value = usn;
    }

    const studentName = localStorage.getItem("student_name");

    if (studentName) {
        const nameField = document.getElementById("name");
        if (nameField) {
            nameField.value = studentName;
        }

        const permissionNameField = document.getElementById("permission_name");
        if (permissionNameField) {
            permissionNameField.value = studentName;
        }
    }

    fetchStudentName();

    loadEventTypes();

    // Dashboard-only work (student.html).
    if (isStudentPage) {
        fetchStudentDetails();
        viewRequests();
        viewGroupRefundStatus();
        fetchPermissions();
        loadApprovedPermissionsForRefund();
    }

    const refundForm = document.getElementById("refundForm");
    if (refundForm) {
        refundForm.addEventListener("submit", submitRefund);
    }

    const permissionForm = document.getElementById("permissionForm");
    if (permissionForm) {
        permissionForm.addEventListener("submit", submitPermission);
        setupEventDuplicateCheck();
    }

    if (isStudentPage) {
        showSection("dashboard");
    }
});

function fetchStudentName() {
    fetch(backendPath + "get_user.php?login_id=" + usn)
        .then(response => response.json())

            .then(data => {

    console.log(data);

    if (data.status === "success") {

const nameField = document.getElementById("name");
if (nameField) {
    nameField.value = data.data.name;
}

const dashboardName = document.getElementById("dashboard_name");

if(dashboardName){

    dashboardName.value = data.data.name;

}

        const departmentField = document.getElementById("department");
        if (departmentField) {
            departmentField.value = data.data.department;
        }

        const refundSemester = document.getElementById("semester");
        if (refundSemester) {
            refundSemester.value = data.data.semester;
        }

        const permissionName = document.getElementById("permission_name");
        const permissionUsn = document.getElementById("permission_usn");
        const permissionDept = document.getElementById("permission_department");
        const permissionSemester = document.getElementById("permission_semester");

        if(permissionName){
            permissionName.value = data.data.name;
        }

        if(permissionUsn){
            permissionUsn.value = usn;
        }

        if(permissionDept){
            permissionDept.value = data.data.department;
        }

        if(permissionSemester){
            permissionSemester.value = data.data.semester;
        }

        localStorage.setItem("student_name", data.data.name);

    }

})
        .catch(error => console.log(error));
}

function fetchStudentDetails() {
    fetch(backendPath + "fetch_bank_details.php")
        .then(response => response.json())
        .then(data => {
            if (data.status === "success" && data.data) {
                const accountHolder = document.getElementById("account_holder");
                if (accountHolder) {
                    accountHolder.value = data.data.account_holder || "";
                }
                const accountNumber = document.getElementById("account_number");
                if (accountNumber) {
                    accountNumber.value = data.data.account_number || "";
                }
                const ifscCode = document.getElementById("ifsc_code");
                if (ifscCode) {
                    ifscCode.value = data.data.ifsc_code || "";
                }
                               const bankName = document.getElementById("bank_name");
                const otherBankName = document.getElementById("other_bank_name");
                const otherBankDiv = document.getElementById("otherBankDiv");

                if (bankName) {
                    const savedBank = data.data.bank_name || "";
                    const matchExists = Array.from(bankName.options)
                        .some(opt => opt.value === savedBank);

                    if (matchExists) {
                        bankName.value = savedBank;
                        if (otherBankDiv) otherBankDiv.style.display = "none";
                    } else if (savedBank) {
                        bankName.value = "Other";
                        if (otherBankDiv) otherBankDiv.style.display = "block";
                        if (otherBankName) otherBankName.value = savedBank;
                    }
                }
            }
        })
        .catch(error => console.log(error));
}


function submitRefund(e) {
    e.preventDefault();

    const form = document.getElementById("refundForm");
    if (!form) {
        return;
    }

    const messageField = document.getElementById("message");

    const amountField = document.getElementById("amount");
    const amountVal = amountField ? Number(amountField.value) : 0;

    if (!(amountVal > 0)) {
        if (messageField) {
            messageField.innerText = "Please enter a refund amount before submitting.";
        }
        return;
    }

    const dupMsgRefund = findDuplicateMemberUsn(".member-usn-input");
    if (dupMsgRefund) {
        if (messageField) {
            messageField.innerText = dupMsgRefund;
        }
        return;
    }

    const formData = new FormData(form);

    formData.append("usn", usn);

    const refundTypeField = document.getElementById("refund_type");
    if (refundTypeField) {
        formData.set("refund_type", refundTypeField.value);
    }

    const bankNameField = document.getElementById("bank_name");
    const otherBankNameField = document.getElementById("other_bank_name");

    if (bankNameField && bankNameField.value === "Other" && otherBankNameField) {
        formData.set("bank_name", otherBankNameField.value);
    }

    const eventNameField = document.getElementById("event_name");
    const permissionRequestId = eventNameField ? eventNameField.value : "";

    if (!permissionRequestId) {
        if (messageField) {
            messageField.innerText = "Please select an approved event before submitting a refund request.";
        }
        return;
    }

    /* ===========================================================
       BANK DETAILS VALIDATION
    =========================================================== */

    const accountHolderField = document.getElementById("account_holder");
    const accountNumberField = document.getElementById("account_number");
    const ifscCodeField = document.getElementById("ifsc_code");

    const accountHolderVal = accountHolderField ? accountHolderField.value.trim() : "";
    const accountNumberVal = accountNumberField ? accountNumberField.value.trim() : "";
    const ifscCodeVal = ifscCodeField ? ifscCodeField.value.trim().toUpperCase() : "";

    const namePattern = /^[A-Za-z ]{3,50}$/;
    const accountNumberPattern = /^[0-9]{9,18}$/;
    const ifscPattern = /^[A-Z]{4}0[A-Z0-9]{6}$/;

    if (!namePattern.test(accountHolderVal)) {
        if (messageField) {
            messageField.innerText = "Account Holder Name should contain letters and spaces only.";
        }
        return;
    }

    if (!accountNumberPattern.test(accountNumberVal)) {
        if (messageField) {
            messageField.innerText = "Account Number must be numeric (9 to 18 digits).";
        }
        return;
    }

    if (!ifscPattern.test(ifscCodeVal)) {
        if (messageField) {
            messageField.innerText = "Enter a valid IFSC Code (e.g., CNRB0001234).";
        }
        return;
    }

    formData.set("account_holder", accountHolderVal);
    formData.set("account_number", accountNumberVal);
    formData.set("ifsc_code", ifscCodeVal);

    fetch(backendPath + "apply_refund.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const messageField = document.getElementById("message");
        if (messageField) {
            messageField.innerText = data.message;
        }

        if (data.status === "success") {
            form.reset();

            const otherEventNameDiv = document.getElementById("otherEventNameDiv");
            if (otherEventNameDiv) {
                otherEventNameDiv.style.display = "none";
            }

            const nameField = document.getElementById("name");
            if (nameField) {
                nameField.value = localStorage.getItem("student_name") || "";
            }

            fetchStudentDetails();
            viewRequests();
        }
    })
    .catch(error => {
        console.log(error);
        const messageField = document.getElementById("message");
        if (messageField) {
            messageField.innerText = "Something went wrong";
        }
    });
}

function toggleTeamRow(index, rowPrefix, togglePrefix) {
    rowPrefix = rowPrefix || "team-row-";
    togglePrefix = togglePrefix || (rowPrefix === "perm-team-row-" ? "perm-team-toggle-" : "team-toggle-");
    const row = document.getElementById(rowPrefix + index);
    const btn = document.getElementById(togglePrefix + index);
    if (!row || !btn) {
        return;
    }
    const arrow = btn.querySelector(".team-toggle-arrow");
    const isHidden = row.style.display === "none";
    row.style.display = isHidden ? "table-row" : "none";
    if (arrow) {
        arrow.textContent = isHidden ? "▾" : "▸";
    }
    btn.classList.toggle("open", isHidden);
}

function viewRequests() {

    const requestsDiv = document.getElementById("requests");
    if (!requestsDiv) {
        return;
    }

    fetch(backendPath + "view_requests.php?usn=" + usn)
        .then(response => response.json())
        .then(data => {

            requestsDiv.innerHTML = "";

            if (!data.data || data.data.length === 0) {
                requestsDiv.innerHTML = "<p>No requests found</p>";
                return;
            }

            let table = `
                <div class="table-wrapper">
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th class="col-reason">Reason</th>
                                <th class="col-amount">Amount</th>
                                <th class="col-amount">Per Student</th>
                                <th class="col-dept">Dept</th>
                                <th class="col-status">Status</th>
                                <th class="col-date">Date</th>
                                <th class="col-docs">Documents</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            let pending = 0;
            let approved = 0;
            let rejected = 0;
            let reqIndex = 0;
            data.data.forEach(req => {

    reqIndex++;
    
    const hodStatus = (req.hod_status || "Pending").trim();

    const adminStatus = (req.admin_status || "Pending").trim();

    let displayStatus;
    if (adminStatus === "Approved") displayStatus = "Approved by Admin";
    else if (adminStatus === "Rejected") displayStatus = "Rejected by Admin";
    else if (adminStatus === "Paid") displayStatus = "Payment Completed";
    else if (hodStatus === "Approved") displayStatus = "Approved by HOD";
    else if (hodStatus === "Rejected") displayStatus = "Rejected by HOD";
    else if (hodStatus === "Clarification Needed") displayStatus = "Clarification Needed";
    else displayStatus = "Pending";

    req.displayStatus = displayStatus;

    const status = displayStatus.trim().toLowerCase();

if (status.includes("pending")) {
    pending++;
}
else if (status.includes("approved")) {
    approved++;
}
else if (status.includes("payment")) {
    approved++;
}
else if (status.includes("completed")) {
    approved++;
}
else if (status.includes("rejected")) {
    rejected++;
}

                const amountClass =
                    parseFloat(req.amount) > 5000 ? "large-amount" : "";

                table += `
                    <tr>
                        <td class="col-reason cell-left">
                            ${escapeHtml(req.refund_reason)}
                            ${req.refund_type === "Group"
                                ? `<button type="button" class="team-toggle-btn" id="team-toggle-${reqIndex}"
                                           onclick="toggleTeamRow(${reqIndex})">
                                       <span class="team-toggle-arrow">▸</span> Team
                                   </button>`
                                : ""}
                        </td>
                        <td class="${amountClass}">
                            ${studentAmountCell(req)}
                        </td>

                        <td>
                            ${req.per_student_amount ? "₹" + escapeHtml(req.per_student_amount) : "—"}
                        </td>

                        <td>${escapeHtml(req.department)}</td>

                        <td>
                            <span class="badge
                            ${req.displayStatus.includes("Pending")?"pending":
                            req.displayStatus.includes("Approved")?"approved":
                            req.displayStatus.includes("Rejected")?"rejected":
                            req.displayStatus.includes("Clarification")?"clarify":
                            "paid"}">${escapeHtml(req.displayStatus)}</span>
                            ${renderRefundShares(req)}
                        </td>

                        <td>${formatDateTime(req.created_at)}</td>

                        <td>
                            <div class="doc-icons">
                                                                <button class="doc-icon-btn" title="Payment Proof"
                                        onclick="openImage('${backendPath}serve_file.php?column=payment_proof&id=${req.id}', '${escapeHtml(req.payment_proof)}')">
                                    Payment
                                </button>

                                ${req.participation_proof
                                     ? `<button class="doc-icon-btn" title="Participation Proof"
                                         onclick="openImage('${backendPath}serve_file.php?column=participation_proof&id=${req.id}', '${escapeHtml(req.participation_proof)}')">
                                          Participation
                                         </button>`
                                        : `<span class="doc-icon-missing" title="Participation proof not submitted">
                                           Participation
                                     </span>`}

                                <button class="doc-icon-btn" title="Bank Proof"
                                        onclick="openImage('${backendPath}serve_file.php?column=bank_proof&id=${req.id}', '${escapeHtml(req.bank_proof)}')">
                                    Bank
                                </button>
                            </div>
                        </td>
                    </tr>
                `;

                if (req.refund_type === "Group") {

                    let membersList = `<span class="text-muted">No team members recorded</span>`;

                    if (Array.isArray(req.members) && req.members.length > 0) {
                        membersList = req.members
                            .map(m => {
                                const memberName = escapeHtml(m.student_name || "Unnamed");
                                const memberUsn = escapeHtml(m.usn || "");
                                const memberDept = m.department ? escapeHtml(m.department) : "";
                                const memberSem = m.semester ? `Sem ${escapeHtml(m.semester)}` : "";
                                const meta = [memberDept, memberSem].filter(Boolean).join(", ");
                                return `<div class="member-row">
                                    <span class="member-dot">•</span>
                                    <span class="member-name">${memberName}</span>
                                    <span class="member-usn">${memberUsn}</span>
                                    ${meta ? `<span class="member-meta">${meta}</span>` : ""}
                                </div>`;
                            })
                            .join("");
                    }

                    table += `
                        <tr class="group-members-row" id="team-row-${reqIndex}" style="display:none;">
                            <td colspan="7">
                                <div class="team-name-line"><b>Team:</b> ${escapeHtml(req.team_name || "—")}</div>
                                <div class="members-list">${membersList}</div>
                            </td>
                        </tr>
                    `;
                }
            });

            table += `
                        </tbody>
                    </table>
                </div>
            `;

            requestsDiv.innerHTML = table;
            let activity = "";

data.data.slice(0,5).forEach(req=>{

activity += `
<div class="mb-2">

<b>${escapeHtml(req.displayStatus)}</b>

- ₹${escapeHtml((req.admin_status === "Paid" && req.refunded_amount !== null) ? req.refunded_amount : req.amount)}

<small class="text-muted">

(${formatDateShort(req.created_at)})

</small>

</div>
`;

});

const recentActivity = document.getElementById("recentActivity");
if (recentActivity) {
    recentActivity.innerHTML = activity || "No recent activity.";
}

const pendingCount = document.getElementById("pendingCount");
if (pendingCount) {
    pendingCount.innerText = pending;
}

const approvedCount = document.getElementById("approvedCount");
if (approvedCount) {
    approvedCount.innerText = approved;
}

const rejectedCount = document.getElementById("rejectedCount");
if (rejectedCount) {
    rejectedCount.innerText = rejected;
}
        })
        .catch(error => console.log(error));
}

/* ===========================================================
   TEAM REFUND STATUS (read-only)
=========================================================== */

function viewGroupRefundStatus() {

    const statusDiv = document.getElementById("groupRefundStatus");
    if (!statusDiv) {
        return;
    }

    fetch(backendPath + "fetch_group_refund_status.php")
        .then(response => response.json())
        .then(data => {

            statusDiv.innerHTML = "";

            if (!data.data || data.data.length === 0) {
                statusDiv.innerHTML = "<p>No group events found.</p>";
                return;
            }

            let table = `
                <div class="table-wrapper">
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th class="col-g-event">Event</th>
                                <th class="col-g-leader">Team Leader</th>
                                <th class="col-g-amount">Per Student</th>
                                <th class="col-g-status">Status</th>
                                <th class="col-g-date">Date</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            data.data.forEach(ev => {

                const status = (ev.display_status || "Not Yet Applied");

                const badgeClass =
                    status.includes("Pending") ? "pending" :
                    status.includes("Approved") ? "approved" :
                    status.includes("Rejected") ? "rejected" :
                    status.includes("Clarification") ? "clarify" :
                    status.includes("Completed") ? "paid" :
                    status.includes("Not Yet Applied") ? "not-applied" :
                    "pending";

                table += `
                    <tr>
                        <td class="col-g-event cell-left">${escapeHtml(ev.event_type || "")} - ${escapeHtml(ev.event_name)}</td>

                        <td>${escapeHtml(ev.leader_name || ev.leader_usn)}</td>

                        <td>
                            ${ev.per_student_amount ? "₹" + escapeHtml(ev.per_student_amount) : "—"}
                        </td>

                        <td>
                            <span class="badge ${badgeClass}">
                                ${escapeHtml(status)}
                            </span>
                            ${renderRefundShares(ev)}
                        </td>

                        <td>${ev.created_at ? formatDateTime(ev.created_at) : "—"}</td>
                    </tr>
                `;
            });

            table += `
                        </tbody>
                    </table>
                </div>
            `;

            statusDiv.innerHTML = table;
        })
        .catch(error => console.log(error));
}

let zoomLevel = 1;

function openImage(path, filename = ""){

    zoomLevel = 1;

    const img = document.getElementById("modalImage");
    const pdf = document.getElementById("modalPdf");
    const modal = document.getElementById("imageModal");

    if (!img || !modal) {
        return;
    }

    const isPdf = (filename || path).toLowerCase().endsWith(".pdf");

    if (isPdf) {
        img.style.display = "none";
        img.src = "";

        if (pdf) {
            pdf.src = path;
            pdf.style.display = "block";
        }
    } else {
        if (pdf) {
            pdf.style.display = "none";
            pdf.src = "";
        }

        img.src = path;
        img.style.display = "block";
        img.style.transform = "scale(1)";
    }

    modal.style.display = "flex";
}

function closeModal(){

    const modal = document.getElementById("imageModal");
    if (modal) {
        modal.style.display = "none";
    }
}

document.addEventListener("DOMContentLoaded", () => {

    const img = document.getElementById("modalImage");

    if (!img) {
        return;
    }

    img.addEventListener("wheel", function(e){

        e.preventDefault();

        if(e.deltaY < 0){
            zoomLevel += 0.1;
        }else{
            zoomLevel -= 0.1;
        }

        zoomLevel = Math.max(0.5, Math.min(zoomLevel, 5));

        img.style.transform = `scale(${zoomLevel})`;

    });

});

function logout() {
    performLogout(backendPath);
}

function toggleGroupFields() {
    const refundTypeField = document.getElementById("refund_type");
    const groupFields = document.getElementById("groupFields");

    if (!refundTypeField || !groupFields) {
        return;
    }

    if (refundTypeField.value === "Group") {
        groupFields.style.display = "block";
    } else {
        groupFields.style.display = "none";
    }
}

/* ==========================================
   ERP Dashboard Navigation
========================================== */

function showSection(sectionId, clickedItem = null) {

    const target = document.getElementById(sectionId);
    if (!target) {
        return;
    }

    document.querySelectorAll(".page").forEach(page => {
        page.style.display = "none";
    });

    target.style.display = "block";

    document.querySelectorAll(".sidebar li").forEach(item => {
        item.classList.remove("active");
    });

    if (clickedItem) {
        clickedItem.classList.add("active");
    }

    if (sectionId === "notifications") {
        markPermissionNotificationsRead();
    }
}

function checkAmount(){

    const amountField = document.getElementById("amount");
    const amountWarning = document.getElementById("amountWarning");

    if (!amountField || !amountWarning) {
        return;
    }

    let amount = Number(amountField.value);

    if(amount > 2000){

        amountField.classList.add("border-danger");

        amountWarning.style.display="block";

    }

    else{

        amountField.classList.remove("border-danger");

        amountWarning.style.display="none";

    }

    const bankDetailsCard = document.getElementById("bankDetailsCard");
    const proofUploadsRow = document.getElementById("proofUploadsRow");

    const showSections = amount > 0;

    if (bankDetailsCard) {
        bankDetailsCard.style.display = showSections ? "" : "none";
    }

    if (proofUploadsRow) {
        proofUploadsRow.style.display = showSections ? "" : "none";
    }

}

function generateMembers(){

    const totalField = document.getElementById("number_of_students");
    const container = document.getElementById("membersContainer");

    if (!totalField || !container) {
        return;
    }

    let total = parseInt(totalField.value);

    container.innerHTML = "";

    if(isNaN(total) || total < 2){
        return;
    }

    for(let i=2;i<=total;i++){

        container.innerHTML += `

<div class="card shadow-sm mt-3">

<div class="card-body">

<h6 class="text-primary mb-3">

Member ${i}

</h6>

<div class="row g-3">

<div class="col-md-3">

<label>USN</label>

<input
class="form-control member-usn-input"
name="member_usn[]"
placeholder="USN"
onblur="autofillMemberDetails(this)">

</div>

<div class="col-md-3">

<label>Name</label>

<input
class="form-control member-name-input"
placeholder="Auto-filled from USN"
readonly>

</div>

<div class="col-md-3">

<label>Department</label>

<input
class="form-control member-department-input"
placeholder="Auto-filled from USN"
readonly>

</div>

<div class="col-md-3">

<label>Semester</label>

<input
class="form-control member-semester-input"
placeholder="Auto-filled from USN"
readonly>

</div>

</div>

</div>

</div>

`;

    }

}

/* ==========================================
   DUPLICATE TEAM MEMBER USN CHECK
   Returns a message if the same USN appears twice
   (or equals the logged-in leader's USN), else "".
========================================== */

function findDuplicateMemberUsn(inputSelector) {

    const seen = [String(usn || "").trim().toUpperCase()];

    const inputs = document.querySelectorAll(inputSelector);

    for (const input of inputs) {

        const value = input.value.trim().toUpperCase();

        if (value === "") continue;

        if (seen.includes(value)) {
            return value === seen[0]
                ? value + " is the Team Leader (you) and is already included. Please enter a different team member."
                : value + " has been entered more than once. Please enter each team member only once.";
        }

        seen.push(value);
    }

    return "";
}

function rejectDuplicateMember(usnInput, inputSelector, nameSel, deptSel, semSel) {

    const value = usnInput.value.trim().toUpperCase();
    if (value === "") return false;

    const leader = String(usn || "").trim().toUpperCase();
    let duplicate = value === leader;

    if (!duplicate) {
        let count = 0;
        document.querySelectorAll(inputSelector).forEach(inp => {
            if (inp.value.trim().toUpperCase() === value) count++;
        });
        duplicate = count > 1;
    }

    if (!duplicate) return false;

    alert(value === leader
        ? value + " is the Team Leader (you) and is already included. Please enter a different team member."
        : value + " has already been entered for another member. Please enter each team member only once.");

    usnInput.value = "";
    const row = usnInput.closest(".row");
    if (row) {
        [nameSel, deptSel, semSel].forEach(sel => {
            const el = row.querySelector(sel);
            if (el) el.value = "";
        });
    }
    return true;
}

/* ==========================================
   AUTO-FILL GROUP MEMBER DETAILS FROM USN
========================================== */

function autofillMemberDetails(usnInput) {

    const memberUsn = usnInput.value.trim();

    const row = usnInput.closest(".row");

    if (!row) {
        return;
    }

    const nameInput = row.querySelector(".member-name-input");
    const departmentInput = row.querySelector(".member-department-input");
    const semesterInput = row.querySelector(".member-semester-input");

    if (memberUsn === "") {
        if (nameInput) nameInput.value = "";
        if (departmentInput) departmentInput.value = "";
        if (semesterInput) semesterInput.value = "";
        return;
    }

    if (rejectDuplicateMember(usnInput, ".member-usn-input", ".member-name-input", ".member-department-input", ".member-semester-input")) {
        return;
    }

    fetch(backendPath + "fetch_student.php?usn=" + encodeURIComponent(memberUsn))
        .then(res => res.json())
        .then(data => {

            if (data.status === "success" && data.data) {

                if (nameInput) nameInput.value = data.data.name || "";
                if (departmentInput) departmentInput.value = data.data.department || "";
                if (semesterInput) semesterInput.value = data.data.semester || "";

            } else {

                if (nameInput) nameInput.value = "Not found";
                if (departmentInput) departmentInput.value = "";
                if (semesterInput) semesterInput.value = "";
            }
        })
        .catch(error => console.log(error));
}

function bankChanged(){

    const bankField = document.getElementById("bank_name");
    const other = document.getElementById("otherBankDiv");
    const ifsc = document.getElementById("ifsc_code");

    if (!bankField || !other || !ifsc) {
        return;
    }

    const bank = bankField.value;

    if(bank==="Other"){

        other.style.display="block";

        ifsc.value="";

        return;
    }

    other.style.display="none";

    fillIFSCPrefix();

}

function toggleOtherEventName(){

    const eventNameSelect = document.getElementById("event_name");
    const otherDiv = document.getElementById("otherEventNameDiv");

    if (!eventNameSelect || !otherDiv) {
        return;
    }

    if(eventNameSelect.value === "Other"){
        otherDiv.style.display = "block";
    } else {
        otherDiv.style.display = "none";
    }
}

function fillIFSCPrefix(){

    const bankField = document.getElementById("bank_name");
    const ifsc = document.getElementById("ifsc_code");

    if (!bankField || !ifsc) {
        return;
    }

    const bank = bankField.value;

    const prefixes={

        "Canara Bank":"CNRB",

        "State Bank of India":"SBIN",

        "Union Bank of India":"UBIN",

        "Bank of Baroda":"BARB",

        "Punjab National Bank":"PUNB",

        "HDFC Bank":"HDFC",

        "ICICI Bank":"ICIC",

        "Axis Bank":"UTIB",

        "Kotak Mahindra Bank":"KKBK",

        "Indian Bank":"IDIB"

    };

    if(prefixes[bank]){

        ifsc.value = prefixes[bank];

    }

}

/* ==========================================
   EVENT PERMISSION MODULE
========================================== */

function calcPermissionDuration(){

    const start = document.getElementById("event_date");
    const end = document.getElementById("event_end_date");
    const durationField = document.getElementById("event_duration");

    if (!start || !end || !durationField) {
        return;
    }

    if(!start.value || !end.value){
        durationField.value = "";
        return;
    }

    const startDate = new Date(start.value);
    const endDate = new Date(end.value);

    const diffTime = endDate - startDate;
    const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24)) + 1;

    if(diffDays < 1){
        durationField.value = "Invalid date range";
        return;
    }

    durationField.value = diffDays + (diffDays === 1 ? " Day" : " Days");
}

function validateRegistrationDeadline(){

    const startField = document.getElementById("event_date");
    const deadlineField = document.getElementById("registration_deadline");
    const errorMsg = document.getElementById("deadlineError");
    const submitBtn = document.querySelector("#permissionForm button[type='submit']");

    if (!startField || !deadlineField || !errorMsg) {
        return;
    }

    if (!deadlineField.value || !startField.value) {
        errorMsg.style.display = "none";
        deadlineField.setCustomValidity("");
        return;
    }

    const startDate = new Date(startField.value);
    const deadlineDate = new Date(deadlineField.value);
    const todayDate = new Date();
    todayDate.setHours(0, 0, 0, 0);

    if (deadlineDate >= startDate) {
        errorMsg.style.display = "block";
        errorMsg.textContent = "Registration deadline must be before the event start date.";
        deadlineField.setCustomValidity("Registration deadline must be before the event start date.");
    } else if (deadlineDate < todayDate) {
        errorMsg.style.display = "block";
        errorMsg.textContent = "Registration deadline cannot be in the past.";
        deadlineField.setCustomValidity("Registration deadline cannot be in the past.");
    } else {
        errorMsg.style.display = "none";
        deadlineField.setCustomValidity("");
    }
}

function togglePermissionGroupFields(){

    const teamTypeField = document.getElementById("permission_team_type");
    const groupFields = document.getElementById("permissionGroupFields");
    const leaderBadge = document.getElementById("permissionLeaderBadge");

    if (!teamTypeField || !groupFields || !leaderBadge) {
        return;
    }

    if(teamTypeField.value === "Group"){
        groupFields.style.display = "block";
        leaderBadge.style.display = "inline-block";
    } else {
        groupFields.style.display = "none";
        leaderBadge.style.display = "none";
    }
}

function generatePermissionMembers(){

    const totalField = document.getElementById("permission_team_size");
    const container = document.getElementById("permissionMembersContainer");

    if (!totalField || !container) {
        return;
    }

    let total = parseInt(totalField.value);

    container.innerHTML = "";

    if(isNaN(total) || total < 2){
        return;
    }

    for(let i=2;i<=total;i++){

        container.innerHTML += `

<div class="card shadow-sm mt-3">

<div class="card-body">

<h6 class="text-primary mb-3">

Member ${i}

</h6>

<div class="row g-3">

<div class="col-md-3">

<label>USN</label>

<input
class="form-control permission-member-usn-input"
name="member_usn[]"
placeholder="USN"
onblur="autofillPermissionMemberDetails(this)">

</div>

<div class="col-md-3">

<label>Name</label>

<input
class="form-control permission-member-name-input"
placeholder="Auto-filled from USN"
readonly>

</div>

<div class="col-md-3">

<label>Department</label>

<input
class="form-control permission-member-department-input"
placeholder="Auto-filled from USN"
readonly>

</div>

<div class="col-md-3">

<label>Semester</label>

<input
class="form-control permission-member-semester-input"
placeholder="Auto-filled from USN"
readonly>

</div>

</div>

</div>

</div>

`;

    }

}

function autofillPermissionMemberDetails(usnInput) {

    const memberUsn = usnInput.value.trim();

    const row = usnInput.closest(".row");

    if (!row) {
        return;
    }

    const nameInput = row.querySelector(".permission-member-name-input");
    const departmentInput = row.querySelector(".permission-member-department-input");
    const semesterInput = row.querySelector(".permission-member-semester-input");

    if (memberUsn === "") {
        if (nameInput) nameInput.value = "";
        if (departmentInput) departmentInput.value = "";
        if (semesterInput) semesterInput.value = "";
        return;
    }

    if (rejectDuplicateMember(usnInput, ".permission-member-usn-input", ".permission-member-name-input", ".permission-member-department-input", ".permission-member-semester-input")) {
        return;
    }

    fetch(backendPath + "fetch_student.php?usn=" + encodeURIComponent(memberUsn))
        .then(res => res.json())
        .then(data => {

            if (data.status === "success" && data.data) {

                if (nameInput) nameInput.value = data.data.name || "";
                if (departmentInput) departmentInput.value = data.data.department || "";
                if (semesterInput) semesterInput.value = data.data.semester || "";

            } else {

                if (nameInput) nameInput.value = "Not found";
                if (departmentInput) departmentInput.value = "";
                if (semesterInput) semesterInput.value = "";
            }
        })
        .catch(error => console.log(error));
}

function loadEventTypes(){

    const typeSelect = document.getElementById("event_type");

    if(!typeSelect){
        return;
    }

    fetch(backendPath + "get_event_types.php")
        .then(response => response.json())
        .then(data => {

            typeSelect.innerHTML = '<option value="">Select Event Type</option>';

            if(data.status === "success"){

                const sortedTypes = [...data.data].sort(
                    (a, b) => Number(a.event_type_id) - Number(b.event_type_id)
                );

                sortedTypes.forEach(type => {

                    const opt = document.createElement("option");
                    opt.value = type.event_type_id;
                    opt.textContent = type.event_type_name;

                    typeSelect.appendChild(opt);
                });
            }
        })
        .catch(error => console.log(error));
}

function submitPermission(e){

    e.preventDefault();

    const form = document.getElementById("permissionForm");
    if (!form) {
        return;
    }

    const dupMsgPermission = findDuplicateMemberUsn(".permission-member-usn-input");
    if (dupMsgPermission) {
        alert(dupMsgPermission);
        return;
    }

    const formData = new FormData(form);

    formData.append("leader_usn", usn);

    const declarationField = document.getElementById("declaration");
    const permissionMessageField = document.getElementById("permissionMessage");

    if (declarationField && !declarationField.checked) {

        if (permissionMessageField) {
            permissionMessageField.innerText =
                "Please certify the declaration before submitting.";
        }

        return;
    }

    fetch(backendPath + "apply_permission.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {

        if (permissionMessageField) {
            permissionMessageField.innerText = data.message;
        }

        if(data.status === "success"){

            form.reset();

            const membersContainer = document.getElementById("permissionMembersContainer");
            if (membersContainer) {
                membersContainer.innerHTML = "";
            }

            const groupFields = document.getElementById("permissionGroupFields");
            if (groupFields) {
                groupFields.style.display = "none";
            }

            const leaderBadge = document.getElementById("permissionLeaderBadge");
            if (leaderBadge) {
                leaderBadge.style.display = "none";
            }

            const durationField = document.getElementById("event_duration");
            if (durationField) {
                durationField.value = "";
            }

            const permissionNameField = document.getElementById("permission_name");
            if (permissionNameField) {
                permissionNameField.value = localStorage.getItem("student_name") || "";
            }

            const permissionUsnField = document.getElementById("permission_usn");
            if (permissionUsnField) {
                permissionUsnField.value = usn;
            }

            fetchPermissions();
        }
    })
    .catch(error => {
        console.log(error);
        if (permissionMessageField) {
            permissionMessageField.innerText = "Something went wrong";
        }
    });
}

function submitClarificationReply(id){

    const field = document.getElementById("reply-" + id);
    const reply = field ? field.value.trim() : "";

    if(reply === ""){
        alert("Please enter a reply before sending.");
        return;
    }

    const formData = new FormData();
    formData.append("id", id);
    formData.append("reply", reply);

    fetch(backendPath + "reply_clarification.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if(data.status === "success"){
            fetchPermissions();
        }
    })
    .catch(error => {
        console.log(error);
        alert("Something went wrong sending your reply");
    });
}

/* Department-wise status for inter-department events. Shown only when
   more than one department has to approve. */
function renderDeptApprovals(req) {

    const list = Array.isArray(req.dept_approvals) ? req.dept_approvals : [];

    if (list.length < 2) {
        return "";
    }

    const mine = (req.my_department || "").trim().toLowerCase();

    return `<div class="dept-status-list">` + list.map(d => {

        const st = (d.hod_status || "Pending").trim();

        const cls =
            st === "Approved" ? "dept-approved" :
            st === "Rejected" ? "dept-rejected" :
            st === "Clarification Needed" ? "dept-clarify" :
            "dept-pending";

        const isMine = (d.department || "").trim().toLowerCase() === mine;

        return `<div class="dept-status-line ${cls}">
            <span class="dept-name">${escapeHtml(d.department)}${isMine ? " (yours)" : ""}</span>
            <span class="dept-state">${escapeHtml(st)}</span>
        </div>`;

    }).join("") + `</div>`;
}

/* Department-wise status of a refund (one share per department). Shown
   only when more than one department is involved. */
function renderRefundShares(req) {

    const list = Array.isArray(req.dept_shares) ? req.dept_shares : [];

    if (list.length < 2) {
        return "";
    }

    const mine = (req.my_department || "").trim().toLowerCase();
    const paid = (req.admin_status || "").trim() === "Paid";

    return `<div class="dept-status-list">` + list.map(d => {

        let st = (d.hod_status || "Pending").trim();

        // an approved share becomes "Paid" once the admin has paid the refund
        if (paid && st === "Approved") {
            st = "Paid";
        }

        const cls =
            st === "Paid" ? "dept-paid" :
            st === "Approved" ? "dept-approved" :
            st === "Rejected" ? "dept-rejected" :
            st === "Clarification Needed" ? "dept-clarify" :
            "dept-pending";

        const isMine = (d.department || "").trim().toLowerCase() === mine;
        const amount = d.share_amount !== null && d.share_amount !== undefined
            ? " · ₹" + Number(d.share_amount).toFixed(2) : "";

        return `<div class="dept-status-line ${cls}">
            <span class="dept-name">${escapeHtml(d.department)}${isMine ? " (yours)" : ""}${amount}</span>
            <span class="dept-state">${escapeHtml(st)}</span>
        </div>`;

    }).join("") + `</div>`;
}

function fetchPermissions(){

    fetch(backendPath + "fetch_permissions.php")
        .then(response => response.json())
        .then(data => {

            const container = document.getElementById("permissionRequests");

            if(!container){
                return;
            }

            container.innerHTML = "";

            const permissionCount = document.getElementById("permissionCount");

            if (!data.data || data.data.length === 0) {
                container.innerHTML = "<p>No permission requests found</p>";
                if (permissionCount) {
                    permissionCount.innerText = 0;
                }
                return;
            }

            if (permissionCount) {
                permissionCount.innerText = data.data.length;
            }

            let table = `
                <div class="table-wrapper">
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th class="col-p-event">Event Name</th>
                                <th class="col-p-type">Event Type</th>
                                <th class="col-p-leader">Team Leader</th>
                                <th class="col-p-status">Status</th>
                                <th class="col-p-date">Submitted</th>
                                <th class="col-p-remarks">HOD Remarks</th>
                                <th class="col-p-date">Approved</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            let permIndex = 0;

            data.data.forEach(req => {

                permIndex++;

                const status = (req.hod_status || "Pending").trim();

                const badgeClass =
                    status === "Approved" ? "approved" :
                    status === "Rejected" ? "rejected" :
                    status === "Clarification Needed" ? "clarify" :
                    "pending";

                table += `
                    <tr>
                        <td class="col-p-event cell-left">
                            ${escapeHtml(req.event_name)}
                            ${req.is_leader === false ? `<span class="member-role-tag">Team Member</span>` : ""}
                            ${req.team_type === "Team"
                                ? `<button type="button" class="team-toggle-btn" id="perm-team-toggle-${permIndex}"
                                           onclick="toggleTeamRow(${permIndex}, 'perm-team-row-')">
                                       <span class="team-toggle-arrow">▸</span> Team
                                   </button>`
                                : ""}
                        </td>
                        <td>${escapeHtml(req.event_type || "")}</td>
                        <td>${escapeHtml(req.leader_name)}</td>
                        <td>
                            <span class="badge ${badgeClass}">
                                ${escapeHtml(status)}
                            </span>
                            ${renderDeptApprovals(req)}
                        </td>
                        <td>${formatDateTime(req.created_at)}</td>
                        <td class="cell-left">${escapeHtml(req.hod_remarks || "—")}</td>
                        <td>${req.approved_at ? formatDateTime(req.approved_at) : "—"}</td>
                    </tr>
                `;

                if(req.team_type === "Team"){

                    let membersList = `<span class="text-muted">No team members recorded</span>`;

                    if(Array.isArray(req.members) && req.members.length > 0){
                        membersList = req.members
                            .map(m => {
                                const memberName = escapeHtml(m.student_name || "Unnamed");
                                const memberUsn = escapeHtml(m.usn || "");
                                const memberDept = m.department ? escapeHtml(m.department) : "";
                                const memberSem = m.semester ? `Sem ${escapeHtml(m.semester)}` : "";
                                const meta = [memberDept, memberSem].filter(Boolean).join(", ");
                                return `<div class="member-row">
                                    <span class="member-dot">•</span>
                                    <span class="member-name">${memberName}</span>
                                    <span class="member-usn">${memberUsn}</span>
                                    ${meta ? `<span class="member-meta">${meta}</span>` : ""}
                                </div>`;
                            })
                            .join("");
                    }

                    table += `
                        <tr class="group-members-row" id="perm-team-row-${permIndex}" style="display:none;">
                            <td colspan="7">
                                <div class="team-name-line"><b>Team:</b> ${escapeHtml(req.team_name || "—")}</div>
                                <div class="members-list">${membersList}</div>
                            </td>
                        </tr>
                    `;
                }

                if(req.is_leader === false && status === "Approved"){

                    table += `
                        <tr class="clarification-reply-row">
                            <td colspan="7">
                                <b>Refund:</b> only the team leader (${escapeHtml(req.leader_name)}) can apply for the refund for this event.
                            </td>
                        </tr>
                    `;
                }

                if(req.is_leader === false && status === "Clarification Needed"){

                    table += `
                        <tr class="clarification-reply-row">
                            <td colspan="7">
                                <b>HOD is asking for clarification.</b> The team leader (${escapeHtml(req.leader_name)}) will reply.
                            </td>
                        </tr>
                    `;

                } else if(status === "Clarification Needed"){

                    table += `
                        <tr class="clarification-reply-row">
                            <td colspan="7">
                                <b>HOD is asking for clarification.</b> Reply below so they can re-review your request.
                                <br><br>
                                <textarea id="reply-${Number(req.id)}" rows="2" style="width:100%;" placeholder="Type your reply to the HOD..."></textarea>
                                <br>
                                <button class="btn btn-primary mt-2" onclick="submitClarificationReply(${Number(req.id)})">Send Reply</button>
                            </td>
                        </tr>
                    `;

                } else if(req.student_reply){

                    table += `
                        <tr class="clarification-reply-row">
                            <td colspan="7">
                                <b>${req.is_leader === false ? "Team leader's reply:" : "Your reply:"}</b> ${escapeHtml(req.student_reply)}
                            </td>
                        </tr>
                    `;
                }
            });

            table += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = table;

            renderPermissionNotifications(data.data);
        })
        .catch(error => console.log(error));
}

let approvedPermissionsMap = {};

function loadApprovedPermissionsForRefund(){

    const eventSelect = document.getElementById("event_name");
    const noApprovedDiv = document.getElementById("noApprovedEventDiv");
    const submitBtn = document.querySelector("#refundForm button[type='submit']");

    if(!eventSelect){
        return; // not on the refund page
    }

    fetch(backendPath + "fetch_approved_permissions.php")
        .then(response => response.json())
        .then(data => {

            eventSelect.innerHTML = '<option value="">Select Approved Event</option>';
            approvedPermissionsMap = {};

            const hasApproved =
                data.status === "success" &&
                Array.isArray(data.data) &&
                data.data.length > 0;

            if(hasApproved){

                data.data.forEach(item => {

                    const opt = document.createElement("option");
                    opt.value = item.id;
                    opt.textContent = item.label;

                    eventSelect.appendChild(opt);

                    approvedPermissionsMap[item.id] = {
                        event_type: item.event_type,
                        event_name: item.event_name,
                        team_type: item.team_type,
                        team_name: item.team_name,
                        team_size: item.team_size,
                        members: Array.isArray(item.members) ? item.members : []
                    };
                });

                eventSelect.disabled = false;
                if(noApprovedDiv) noApprovedDiv.style.display = "none";
                if(submitBtn) submitBtn.disabled = false;

            } else {

                // Requirement: no approved permission -> refund blocked entirely.
                eventSelect.disabled = true;
                if(noApprovedDiv) noApprovedDiv.style.display = "block";
                if(submitBtn) submitBtn.disabled = true;
            }
        })
        .catch(error => console.log(error));
}

function onApprovedEventSelected(){

    const eventSelect = document.getElementById("event_name");
    const refundTypeField = document.getElementById("refund_type");
    const refundTypeHint = document.getElementById("refundTypeHint");
    const teamNameField = document.getElementById("team_name");
    const numberField = document.getElementById("number_of_students");
    const container = document.getElementById("membersContainer");

    if (!eventSelect || !refundTypeField) {
        return;
    }

    const selectedId = eventSelect.value;
    const record = selectedId ? approvedPermissionsMap[selectedId] : null;

    if (!record) {
        refundTypeField.disabled = false;
        if (refundTypeHint) refundTypeHint.style.display = "none";
        if (teamNameField) teamNameField.value = "";
        if (numberField) numberField.value = "";
        if (container) container.innerHTML = "";
        toggleGroupFields();
        return;
    }

    refundTypeField.value = (record.team_type === "Team") ? "Group" : "Individual";
    refundTypeField.disabled = true;
    if (refundTypeHint) refundTypeHint.style.display = "inline";

    toggleGroupFields();

    if (record.team_type === "Team") {

        if (teamNameField) teamNameField.value = record.team_name || "";
        if (numberField) numberField.value = record.team_size || (record.members.length + 1);

        if (container) {

            let html = `
                <div class="card shadow-sm mt-3">
                    <div class="card-body">
                        <h6 class="text-primary mb-3">Team Leader (you)</h6>
                        <div class="row g-3">
                            <div class="col-md-4"><label>USN</label>
                                <input class="form-control" value="${escapeHtml(usn)}" readonly></div>
                            <div class="col-md-4"><label>Name</label>
                                <input class="form-control" value="${escapeHtml(document.getElementById("name") ? document.getElementById("name").value : "")}" readonly></div>
                            <div class="col-md-4"><label>Department</label>
                                <input class="form-control" value="${escapeHtml(document.getElementById("department") ? document.getElementById("department").value : "")}" readonly></div>
                        </div>
                    </div>
                </div>
            `;

            record.members.forEach((m, idx) => {
                html += `
                    <div class="card shadow-sm mt-3">
                        <div class="card-body">
                            <h6 class="text-primary mb-3">Member ${idx + 2}</h6>
                            <div class="row g-3">
                                <div class="col-md-4"><label>USN</label>
                                    <input class="form-control" value="${escapeHtml(m.usn)}" readonly></div>
                                <div class="col-md-4"><label>Name</label>
                                    <input class="form-control" value="${escapeHtml(m.student_name || "")}" readonly></div>
                                <div class="col-md-4"><label>Department</label>
                                    <input class="form-control" value="${escapeHtml(m.department || "")}" readonly></div>
                            </div>
                            <input type="hidden" name="member_usn[]" value="${escapeHtml(m.usn)}">
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

    } else {

        if (teamNameField) teamNameField.value = "";
        if (numberField) numberField.value = "";
        if (container) container.innerHTML = "";
    }
}

function renderPermissionNotifications(requests) {

    const listEl = document.getElementById("notificationsList");
    const badgeEl = document.getElementById("notificationBadge");

    if (!listEl) {
        return;
    }

    const seenKey = "permission_status_seen";
    let seen = {};

    try {
        seen = JSON.parse(localStorage.getItem(seenKey) || "{}");
    } catch (e) {
        seen = {};
    }

    const decided = (requests || []).filter(r => {
        const status = (r.hod_status || "Pending").trim();
        return status === "Approved" || status === "Rejected" || status === "Clarification Needed";
    });

    if (decided.length === 0) {
        listEl.innerHTML = "No notifications available.";
        if (badgeEl) badgeEl.style.display = "none";
        return;
    }

    // Newest decision first.
    decided.sort((a, b) => (b.id || 0) - (a.id || 0));

    let unreadCount = 0;
    let html = "";

    decided.forEach(r => {
        const status = r.hod_status.trim();
        const isUnread = seen[r.id] !== status;

        if (isUnread) {
            unreadCount++;
        }

        const verb =
            status === "Approved" ? "approved" :
            status === "Rejected" ? "rejected" :
            "sent back for clarification on";

        const remarksLine = r.hod_remarks
            ? `<br><small>HOD remarks: ${escapeHtml(r.hod_remarks)}</small>`
            : "";

        html += `
            <div class="notification-item ${isUnread ? "unread" : ""}">
                ${r.is_leader === false ? "Your team's event permission request for" : "Your event permission request for"}
                <b>${escapeHtml(r.event_name)}</b> has been
                <b>${verb}</b> by the HOD.
                ${remarksLine}
            </div>
        `;
    });

    listEl.innerHTML = html;

    if (badgeEl) {
        if (unreadCount > 0) {
            badgeEl.innerText = unreadCount;
            badgeEl.style.display = "inline-block";
        } else {
            badgeEl.style.display = "none";
        }
    }
}

function markPermissionNotificationsRead() {

    fetch(backendPath + "fetch_permissions.php")
        .then(response => response.json())
        .then(data => {

            const seenKey = "permission_status_seen";
            const seen = {};

            (data.data || []).forEach(r => {
                const status = (r.hod_status || "Pending").trim();
                if (status === "Approved" || status === "Rejected" || status === "Clarification Needed") {
                    seen[r.id] = status;
                }
            });

            localStorage.setItem(seenKey, JSON.stringify(seen));

            const badgeEl = document.getElementById("notificationBadge");
            if (badgeEl) badgeEl.style.display = "none";

            renderPermissionNotifications(data.data);
        })
        .catch(error => console.log(error));
}


/* ==========================================
   LIVE DUPLICATE EVENT CHECK
   As soon as event name + date are entered (and whenever a
   team member is added), warn right under the date fields if any
   student is already part of a request for the same event.
   The server repeats this check on submit.
========================================== */

let eventDuplicateTimer = null;
let eventDuplicateRequestId = 0;

function setupEventDuplicateCheck() {

    const form = document.getElementById("permissionForm");

    if (!form) {
        return;
    }

    const watched =
        "#permission_event_name, #event_date, " +
        "#permission_team_type, #permission_team_size, .permission-member-usn-input";

    const trigger = function (e) {
        if (e.target && e.target.matches && e.target.matches(watched)) {
            scheduleEventDuplicateCheck();
        }
    };

    form.addEventListener("input", trigger);
    form.addEventListener("change", trigger);
    form.addEventListener("focusout", trigger);

    form.addEventListener("reset", function () {
        setTimeout(runEventDuplicateCheck, 0);
    });
}

function scheduleEventDuplicateCheck() {

    clearTimeout(eventDuplicateTimer);
    eventDuplicateTimer = setTimeout(runEventDuplicateCheck, 450);
}

function runEventDuplicateCheck() {

    const warning = document.getElementById("eventDuplicateWarning");
    const dateField = document.getElementById("event_date");
    const nameField = document.getElementById("permission_event_name");
    const teamTypeField = document.getElementById("permission_team_type");

    if (!warning || !dateField || !nameField) {
        return;
    }

    const clearWarning = function () {
        warning.style.display = "none";
        warning.textContent = "";
        dateField.setCustomValidity("");
    };

    if (!nameField.value.trim() || !dateField.value) {
        clearWarning();
        return;
    }

    const body = new FormData();

    body.append("event_name", nameField.value.trim());
    body.append("event_date", dateField.value);

    if (teamTypeField && teamTypeField.value === "Group") {

        document
            .querySelectorAll("#permissionMembersContainer .permission-member-usn-input")
            .forEach(function (input) {
                if (input.value.trim()) {
                    body.append("member_usn[]", input.value.trim());
                }
            });
    }

    const thisRequest = ++eventDuplicateRequestId;

    fetch(backendPath + "check_event_duplicate.php", {
        method: "POST",
        body: body
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {

        // ignore answers that arrive after a newer check was started
        if (thisRequest !== eventDuplicateRequestId) {
            return;
        }

        if (data.status === "success" && data.duplicate) {

            warning.textContent = data.message;
            warning.style.display = "block";

            // also stops the form from being submitted
            dateField.setCustomValidity(data.message);

        } else {
            clearWarning();
        }
    })
    .catch(function () {
        // if the check cannot run, do not block the student; the server
        // still checks again on submit
        clearWarning();
    });
}