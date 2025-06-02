<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    // Redirect to login if not logged in or not an admin
    // However, a more specific error or redirect might be better if they land here unexpectedly.
    redirect(BASE_URL . 'login.php'); 
}

$feedback_message = '';
$feedback_type = '';
$current_admin_id = $_SESSION['user_id'];

$redirect_url_base = isset($_POST['redirect_url_base']) ? $_POST['redirect_url_base'] : BASE_URL . 'admin/manage_users.php'; // Default if base not set

// Function to append query parameters to a base URL
function append_params_to_url($url, $params) {
    $query = parse_url($url, PHP_URL_QUERY);
    if ($query) {
        $url .= '&' . http_build_query($params);
    } else {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_id']) && isset($_POST['user_id']) && isset($_POST['action_log_type']) && $_POST['action_log_type'] === 'request_loan_deletion') {
    $loan_id_to_delete = (int)$_POST['loan_id'];
    $target_user_id = (int)$_POST['user_id']; // User who owns the loan

    // Fetch current loan details to store for review by Super Admin
    $stmt_loan_details = $conn->prepare("SELECT amount_requested, amount_approved, term_months, status, request_date, remaining_balance, next_payment_due_date, loan_end_term_date FROM loans WHERE id = ? AND user_id = ?");
    if (!$stmt_loan_details) {
        error_log("Prepare failed for loan details: (" . $conn->errno . ") " . $conn->error);
        redirect(append_params_to_url($redirect_url_base, ['deletion_request_status' => 'failure', 'drs_error' => 'prepare_fetch']));
        exit;
    }
    $stmt_loan_details->bind_param("ii", $loan_id_to_delete, $target_user_id);
    $stmt_loan_details->execute();
    $result_loan_details = $stmt_loan_details->get_result();
    
    if ($result_loan_details->num_rows === 1) {
        $loan_current_values = $result_loan_details->fetch_assoc();
        $current_values_json = json_encode($loan_current_values);
        $stmt_loan_details->close();

        // For loan deletion, proposed_changes might be simple or not strictly needed if action_type is clear
        $proposed_changes_json = json_encode(['action' => 'delete_loan', 'loan_id' => $loan_id_to_delete]);
        $admin_reason = "Requesting deletion of loan ID: " . $loan_id_to_delete; // Admins could add a textarea later if more detailed reasons are needed

        $stmt_log = $conn->prepare(
            "INSERT INTO admin_actions_log (admin_user_id, target_user_id, target_object_id, action_type, proposed_changes, current_values, admin_reason, status) " .
            "VALUES (?, ?, ?, 'request_loan_deletion', ?, ?, ?, 'pending')"
        );
        
        if (!$stmt_log) {
            error_log("Prepare failed for admin_actions_log: (" . $conn->errno . ") " . $conn->error);
            redirect(append_params_to_url($redirect_url_base, ['deletion_request_status' => 'failure', 'drs_error' => 'prepare_log']));
            exit;
        }

        $stmt_log->bind_param("iiisss", $current_admin_id, $target_user_id, $loan_id_to_delete, $proposed_changes_json, $current_values_json, $admin_reason);

        if ($stmt_log->execute()) {
            // Success - redirect with a success query param
            redirect(append_params_to_url($redirect_url_base, ['deletion_request_status' => 'success', 'loan_id' => $loan_id_to_delete]));
        } else {
            error_log("Execute failed for admin_actions_log: (" . $stmt_log->errno . ") " . $stmt_log->error);
            redirect(append_params_to_url($redirect_url_base, ['deletion_request_status' => 'failure', 'drs_error' => 'execute_log', 'loan_id' => $loan_id_to_delete]));
        }
        $stmt_log->close();

    } else {
        if($stmt_loan_details) $stmt_loan_details->close();
        // Loan not found or doesn't belong to the user, or already processed
        redirect(append_params_to_url($redirect_url_base, ['deletion_request_status' => 'failure', 'drs_error' => 'notfound', 'loan_id' => $loan_id_to_delete]));
    }

} else {
    // Invalid request method or missing parameters
    redirect(append_params_to_url($redirect_url_base, ['deletion_request_status' => 'failure', 'drs_error' => 'invalid_request']));
}

// No HTML output needed as this script only processes and redirects.
?> 