<?php

require_once "db.php";
require_once "auth_check.php";
require_once "refund_lib.php";

require_login(['admin']);

mysqli_report(MYSQLI_REPORT_OFF);

header("Content-Type: application/json");


if ($_SERVER["REQUEST_METHOD"] == "POST") {


    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';



    if ($id === false || $id === null) {

        echo json_encode([
            "status"=>"error",
            "message"=>"Invalid request id"
        ]);

        exit;
    }



    // One transaction: the refund row is locked so a HOD cannot change a
    // department share while the admin is paying.
    $conn->begin_transaction();

    $check = $conn->prepare(
        "SELECT id, admin_status, hod_status, amount,
                (SELECT COALESCE(SUM(x.share_amount), 0)
                   FROM refund_shares x
                  WHERE x.refund_id = refund_requests.id
                    AND x.hod_status = 'Approved') AS payable_amount
         FROM refund_requests 
         WHERE id = ?
         FOR UPDATE"
    );

    $check->bind_param("i",$id);

    $check->execute();


    $checkResult = $check->get_result();



    if($checkResult->num_rows === 0){

        echo json_encode([
            "status"=>"error",
            "message"=>"Request not found"
        ]);

        exit;
    }



    // Lock after payment completed

    $checkRow = $checkResult->fetch_assoc();


    if($checkRow['admin_status'] === 'Paid'){

        echo json_encode([
            "status"=>"error",
            "message"=>"This request is locked — payment has already been completed."
        ]);

        exit;

    }

    if($checkRow['hod_status'] !== 'Approved'){

        echo json_encode([
            "status"=>"error",
            "message"=>"This request cannot be processed until the HOD has approved it."
        ]);

        exit;

    }

    $payable = round((float)$checkRow['payable_amount'], 2);

    if($payable <= 0){

        echo json_encode([
            "status"=>"error",
            "message"=>"No department share has been approved for this refund."
        ]);

        exit;

    }

    if($action == "approve"){


        $sql = "UPDATE refund_requests

                SET admin_status='Approved'

                WHERE id = ?";


    }

    elseif($action == "reject"){


        $sql = "UPDATE refund_requests

                SET admin_status='Rejected',

                    admin_archived = 1

                WHERE id = ?";


    }

        elseif($action == "pay"){

        // The admin can pay at most what the HODs approved
        $requested = $payable;
        $refunded  = filter_var($_POST['refunded_amount'] ?? '', FILTER_VALIDATE_FLOAT);
        $remarks   = mb_substr(trim($_POST['admin_remarks'] ?? ''), 0, 500);

        if($refunded === false || $refunded <= 0 || $refunded > $requested){
            echo json_encode([
                "status"=>"error",
                "message"=>"Refunded amount must be more than 0 and not more than the approved amount (₹" . number_format($requested, 2) . ")."
            ]);
            exit;
        }

        $refunded = round($refunded, 2);

        if($refunded < $requested && $remarks === ''){
            echo json_encode([
                "status"=>"error",
                "message"=>"Please enter a remark explaining the partial refund."
            ]);
            exit;
        }

        $payStmt = $conn->prepare(
            "UPDATE refund_requests
             SET admin_status='Paid', refunded_amount=?, admin_remarks=?
             WHERE id = ?"
        );
        $payStmt->bind_param("dsi", $refunded, $remarks, $id);

        if($payStmt->execute()){
            $conn->commit();
            echo json_encode(["status"=>"success","message"=>"Payment recorded: ₹" . number_format($refunded, 2) . " refunded."]);
        } else {
            echo json_encode(["status"=>"error","message"=>"Database update failed"]);
        }

        exit;
    }


    else{


        echo json_encode([

            "status"=>"error",

            "message"=>"Invalid action"

        ]);


        exit;

    }




    $stmt = $conn->prepare($sql);


    $stmt->bind_param("i",$id);



    if($stmt->execute()){

        $conn->commit();

        echo json_encode([

            "status"=>"success",

            "message"=>"Request updated successfully"

        ]);


    }


    else{


        echo json_encode([

            "status"=>"error",

            "message"=>"Database update failed"

        ]);


    }



}

else{


    echo json_encode([

        "status"=>"error",

        "message"=>"Invalid request method"

    ]);


}


?>