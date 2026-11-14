// ================= LOGIN CHECK =================

if(localStorage.getItem("admin_logged_in") !== "true"){

    alert("Please login first");

    window.location.href="../index.html";

}


const backendPath="../backend/";

const department=localStorage.getItem("admin_department");



if(!department){

    alert("Please select department first");

    window.location.href="../index.html";

}







// ================= PAGE NAVIGATION =================

// ================= CUSTOM CONFIRM MODAL =================

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

// ================= PAGE NAVIGATION =================

function showSection(section,element){

    document.querySelectorAll(".page").forEach(page=>{
        page.style.display="none";
    });

    document.getElementById(section).style.display="block";

    document.querySelectorAll(".sidebar li").forEach(li=>{
        li.classList.remove("active");
    });

    element.classList.add("active");

    // NEW: auto-collapse sidebar for more table space
    const sidebar = document.querySelector(".sidebar");
    if(section === "requests"){
        sidebar.classList.add("collapsed");
    } else {
        sidebar.classList.remove("collapsed");
    }

    if(section==="dashboard"){
        loadDashboardCounts();
    }



    if(section==="requests"){

        loadRequests();

    }


}




// ================= DASHBOARD COUNT =================
function loadDepartmentTally(){

fetch("../backend/department_tally.php")

.then(res=>res.json())

.then(data=>{

    if(data.status !== "success"){
        document.getElementById("tallyTable").innerHTML =
        `<p class="text-muted">Unable to load tally.</p>`;
        return;
    }

    document.getElementById("tallySummary").innerHTML = `
        <div class="card-box">
            <h3>Total Refunded</h3>
            <span>₹${Number(data.grand_total).toFixed(2)}</span>
        </div>
        <div class="card-box">
            <h3>Requests Paid</h3>
            <span>${data.grand_count}</span>
        </div>
        <div class="card-box">
            <h3>Departments</h3>
            <span>${data.data.length}</span>
        </div>
    `;

    if(data.data.length === 0){
        document.getElementById("tallyTable").innerHTML =
        `<p class="text-muted">No paid refunds yet.</p>`;
        return;
    }

    let rows = "";

    data.data.forEach(dept=>{
        rows += `
            <tr>
                <td>${escapeHtml(dept.department || "Unknown")}</td>
                <td>${dept.requests_paid}</td>
                <td>₹${Number(dept.total_refunded).toFixed(2)}</td>
            </tr>
        `;
    });

    document.getElementById("tallyTable").innerHTML = `
        <div class="table-wrapper">
        <table class="requests-table">
        <thead>
        <tr>
            <th>Department</th>
            <th>Requests Paid</th>
            <th>Total Refunded</th>
        </tr>
        </thead>
        <tbody>
        ${rows}
        </tbody>
        </table>
        </div>
    `;

})

.catch(err=>console.log(err));

}

function loadDashboardCounts(){


fetch("../backend/admin_dashboard_count.php")


.then(res=>res.json())


.then(data=>{


    if(data.data){


        document.getElementById("totalCount").innerText =
        data.data.total || 0;


        document.getElementById("paidCount").innerText =
        data.data.paid || 0;


        document.getElementById("rejectedCount").innerText =
        data.data.rejected || 0;


        document.getElementById("pendingCount").innerText =
        data.data.pending || 0;


    }


})


.catch(err=>console.log(err));


}







// ================= LOAD REQUESTS =================


function loadRequests(){


fetch("../backend/admin_requests.php")


.then(res=>res.json())


.then(data=>{


const box=document.getElementById("requestsTable");


box.innerHTML="";



if(!data.data || data.data.length===0){


box.innerHTML="<p>No requests found</p>";

document.getElementById("resultCount").innerText=
"Showing 0 Requests";

return;


}



let table=`


<div class="table-wrapper">


<table class="requests-table">


<thead>

<tr>

<th>S.No</th>
<th>USN</th>
<th>Name</th>
<th>Department</th>
<th>Reason</th>
<th>Type</th>
<th>Amount</th>
<th>Status</th>
<th>Date</th>
<th>Payment Proof</th>
<th>Participation<br>Proof</th>
<th>Bank Proof</th>
<th>Action</th>

</tr>

</thead>


<tbody>


`;





data.data.forEach((req, index)=>{

const namesCell = (req.refund_type === "Group" && (req.members || []).length > 0)
    ? [escapeHtml(req.student_name), ...(req.members || []).map(m => escapeHtml(m.student_name))].join("<br>")
    : escapeHtml(req.student_name);

const status = (req.admin_status || "Pending").trim();

const actionCell = status === "Paid"
    ? `<span class="locked-note"><i class="fa-solid fa-lock"></i> Paid</span>`
    : `
        <div class="action-buttons">
            <button class="approve" onclick="markAsPaid(${req.id}, ${Number(req.amount)})">Accept</button>
            <button class="reject" onclick="updateStatus(${req.id},'reject')">Reject</button>
        </div>
    `;

table+=`


<tr data-semester="${escapeHtml(req.semester)}">


<td class="sno-cell">${index + 1}</td>

<td class="usn-cell">${escapeHtml(req.usn)}</td>


<td>${namesCell}</td>


<td>${escapeHtml(req.department)}</td>


<td class="reason-cell">${escapeHtml(req.refund_reason)}</td>


<td>${escapeHtml(req.refund_type)}</td>


<td>${amountCell(req)}</td>


<td>${escapeHtml(req.admin_status)}</td>


<td class="date-cell">${escapeHtml((req.created_at || "").split(" ")[0])}</td>



<td>
${req.payment_proof
        ? `<button class="view-btn" onclick="openImage('${backendPath}serve_file.php?column=payment_proof&id=${req.id}', '${escapeHtml(req.payment_proof)}')"
>View</button>`
    : "-"}
</td>




<td>
${req.participation_proof
        ? `<button class="view-btn" onclick="openImage('${backendPath}serve_file.php?column=participation_proof&id=${req.id}', '${escapeHtml(req.participation_proof)}')"
    >View</button>`

    : "-"}
</td>




<td>
${req.bank_proof
              ? `<button class="view-btn"  onclick="openImage('${backendPath}serve_file.php?column=bank_proof&id=${req.id}', '${escapeHtml(req.bank_proof)}')">View</button>`
    : "-"}
</td>




<td>${actionCell}</td>



</tr>


`;



});



table+=`

</tbody>

</table>

</div>

`;



box.innerHTML=table;



applyFilters();



});


}

// ================= ARCHIVE REQUESTS =================


function showArchive(){


fetch("../backend/admin_archive_requests.php")


.then(res=>res.json())


.then(data=>{


const box=document.getElementById("requestsTable");


box.innerHTML="";



if(!data.data || data.data.length===0){


box.innerHTML="<p>No archived requests found</p>";

document.getElementById("resultCount").innerText=
"Showing 0 Requests";

return;


}



let table=`


<div class="table-wrapper">

<table class="requests-table">


<thead>

<tr>

<th>S.No</th>
<th>USN</th>
<th>Name</th>
<th>Department</th>
<th>Reason</th>
<th>Type</th>
<th>Amount</th>
<th>Status</th>
<th>Date</th>
<th>Action</th>

</tr>

</thead>


<tbody>

`;



data.data.forEach((req, index)=>{

const namesCell = (req.refund_type === "Group" && (req.members || []).length > 0)
    ? [escapeHtml(req.student_name), ...(req.members || []).map(m => escapeHtml(m.student_name))].join("<br>")
    : escapeHtml(req.student_name);

table+=`

<tr data-semester="${escapeHtml(req.semester)}">

<td class="sno-cell">${index + 1}</td>

<td class="usn-cell">${escapeHtml(req.usn)}</td>

<td>${namesCell}</td>

<td>${escapeHtml(req.department)}</td>

<td class="reason-cell">${escapeHtml(req.refund_reason)}</td>

<td>${escapeHtml(req.refund_type)}</td>

<td>${amountCell(req)}</td>
<td>${escapeHtml(req.admin_status)}</td>

<td class="date-cell">${escapeHtml((req.created_at || "").split(" ")[0])}</td>

<td>${req.admin_status === "Paid"
    ? `<span class="locked-note"><i class="fa-solid fa-lock"></i> Paid</span>`
    : (req.admin_status === "Rejected"
        ? `<button class="approve" onclick="restoreRequest(${req.id})">Restore</button>`
        : "-")}</td>

</tr>

`;



});



table+=`

</tbody>

</table>

</div>

`;



box.innerHTML=table;



applyFilters();



});


}

function amountCell(req){
    const requested = Number(req.amount);
    const isPaid = (req.admin_status || "").trim() === "Paid";

    if(isPaid && req.refunded_amount !== null && req.refunded_amount !== undefined){
        const paid = Number(req.refunded_amount);
        if(paid < requested){
            return `₹${paid.toFixed(2)}<br><small style="color:#b45309;">Partial (requested ₹${requested.toFixed(2)})</small>`;
        }
        return `₹${paid.toFixed(2)}`;
    }
    return `₹${escapeHtml(req.amount)}`;
}

// ================= MARK AS PAID =================
// ================= MARK AS PAID =================
function markAsPaid(id, requestedAmount){

    const overlay   = document.getElementById("payModal");
    const amountIn  = document.getElementById("payAmountInput");
    const remarksIn = document.getElementById("payRemarksInput");
    const errorBox  = document.getElementById("payError");
    const okBtn     = document.getElementById("payOkBtn");
    const cancelBtn = document.getElementById("payCancelBtn");

    document.getElementById("payRequestedText").innerText =
        "Requested amount: ₹" + Number(requestedAmount).toFixed(2) +
        "\nThis cannot be undone once confirmed.";

    amountIn.value = Number(requestedAmount).toFixed(2);
    amountIn.max = requestedAmount;
    remarksIn.value = "";
    errorBox.innerText = "";
    overlay.style.display = "flex";

    function cleanup(){
        overlay.style.display = "none";
        okBtn.removeEventListener("click", handleOk);
        cancelBtn.removeEventListener("click", handleCancel);
    }

    function handleCancel(){ cleanup(); }

    function handleOk(){

        const refunded = Number(amountIn.value);
        const remarks  = remarksIn.value.trim();

        if(!(refunded > 0) || refunded > Number(requestedAmount)){
            errorBox.innerText = "Enter an amount between 0 and ₹" + Number(requestedAmount).toFixed(2);
            return;
        }
        if(refunded < Number(requestedAmount) && remarks === ""){
            errorBox.innerText = "Please enter a remark for the partial refund.";
            return;
        }

        cleanup();

        const formData = new FormData();
        formData.append("id", id);
        formData.append("action", "pay");
        formData.append("refunded_amount", refunded);
        formData.append("admin_remarks", remarks);

        fetch("../backend/approve_request.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            loadRequests();
            loadDashboardCounts();
        })
        .catch(error => {
            console.log(error);
            alert("Action failed");
        });
    }

    okBtn.addEventListener("click", handleOk);
    cancelBtn.addEventListener("click", handleCancel);
}

// ================= ACTIVE REQUESTS =================
function restoreRequest(id){

    showConfirmModal("Restore this rejected request to Active? Its status can be changed again (e.g. back to Approved) until payment is made.", function(){

        const formData = new FormData();
        formData.append("id", id);
        formData.append("type", "admin");

        fetch("../backend/restore_request.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            showArchive();
            loadDashboardCounts();
        })
        .catch(error => {
            console.log(error);
            alert("Restore failed");
        });

    });
}

function showActive(){

    loadRequests();

}


// ================= ACADEMIC YEAR ROLLOVER =================

function rolloverAcademicYear(){

    showConfirmModal("This will archive ALL requests from the current academic year's PREVIOUS batch into a backup history table, remove them from the Active/Archive tabs, and move to the next academic year. Continue?", function(){

        const formData = new FormData();
        formData.append("confirm", "yes");

        fetch("../backend/rollover_academic_year.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === "success") {
                alert(
                    "Done. Academic year moved: " + data.previous_academic_year + " -> " + data.current_academic_year +
                    "\nPermission requests archived: " + data.permission_requests_archived +
                    "\nRefund requests archived: " + data.refund_requests_archived
                );
                loadRequests();
                loadDashboardCounts();
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.log(error);
            alert("Academic year rollover failed");
        });

    });
}

// ================= DOWNLOAD EXCEL =================


function downloadExcel(){

    const deptSelect =
        document.getElementById("excelDeptSelect");

    const yearSelect =
        document.getElementById("excelYearSelect");

    const department =
        deptSelect ? deptSelect.value : "all";

    const year =
        yearSelect ? yearSelect.value : "all";


    let url =
        "../backend/download_excel.php?type=admin" +
        "&department=" +
        encodeURIComponent(department) +
        "&year=" +
        encodeURIComponent(year);


    window.location.href = url;


    // The download itself archives any exported Paid rows.
    setTimeout(() => {

        loadRequests();
        loadDashboardCounts();

    }, 1500);

}








// ================= APPROVE / REJECT =================


function updateStatus(id,action){


let formData=new FormData();


formData.append("id",id);

formData.append("action",action);



fetch("../backend/approve_request.php",{

method:"POST",

body:formData

})


.then(res=>res.json())


.then(data=>{


alert(data.message);


loadRequests();


loadDashboardCounts();


});


}








// ================= SEARCH + DEPARTMENT FILTER =================
// Both the search box and the department dropdown filter the SAME
// currently-rendered table (Active or Archive, whichever is showing)
// -- they're combined here so picking a department doesn't wipe out
// whatever the admin already typed in search, and vice versa.

function applyFilters(){

    const searchValue = document
        .getElementById("searchInput")
        .value
        .toLowerCase();

    const deptSelect = document.getElementById("excelDeptSelect");
    const selectedDept = deptSelect ? deptSelect.value : "all";

    const yearSelect = document.getElementById("excelYearSelect");
    const selectedYear = yearSelect ? yearSelect.value : "all";

    const rows = document.querySelectorAll(".requests-table tbody tr");

    let count = 0;

    rows.forEach(row => {

        const deptCell = row.children[3];
        const rowDept = deptCell
            ? deptCell.innerText.trim()
            : "";

        const rowDepts = rowDept.toLowerCase().split(",").map(d => d.trim());

        const matchesDept =
            selectedDept === "all" ||
            rowDepts.includes(selectedDept.toLowerCase());

        const semester = parseInt(row.dataset.semester || "0", 10);

        let matchesYear = true;

        if (selectedYear !== "all") {

            const yearSemesters = {
                "1": [1, 2],
                "2": [3, 4],
                "3": [5, 6],
                "4": [7, 8]
            };

            const allowedSemesters = yearSemesters[selectedYear] || [];

            matchesYear = allowedSemesters.includes(semester);
        }


        const matchesSearch =
            row.innerText
                .toLowerCase()
                .includes(searchValue);


        if (matchesDept && matchesYear && matchesSearch) {

            row.style.display = "";
            count++;
            const snoCell = row.querySelector(".sno-cell");
            if (snoCell) snoCell.innerText = count;

        } else {

            row.style.display = "none";

        }

    });


    document.getElementById("resultCount").innerText =
        `Showing ${count} Requests`;

}

function searchRequests(){
    applyFilters();
}


// ================= IMAGE =================


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



function closeModal(){


document.getElementById("imageModal").style.display="none";


}




// ================= LOGOUT =================


function logout(){


performLogout(backendPath);


}



// ================= ESCAPE =================


function escapeHtml(text){


if(text===null || text===undefined)

return "";


return String(text)

.replace(/&/g,"&amp;")

.replace(/</g,"&lt;")

.replace(/>/g,"&gt;")

.replace(/"/g,"&quot;")

.replace(/'/g,"&#039;");


}



function escapeJsAttr(text){


if(text===null || text===undefined)

return "";


return String(text)

.replace(/\\/g,"\\\\")

.replace(/'/g,"\\'")

.replace(/"/g,'\\"');


}




// ================= START =================


document.addEventListener("DOMContentLoaded",()=>{


document.getElementById("dashboard_name").value=

localStorage.getItem("admin_name") || "Admin";


loadDashboardCounts();


});


function toggleSidebar(){
    document.querySelector(".sidebar").classList.toggle("collapsed");
}