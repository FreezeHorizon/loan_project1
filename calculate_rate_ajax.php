<?php
if (session_status() == PHP_SESSION_NONE) { // Ensure session is started
    session_start();
}
require_once __DIR__ . '/includes/db_connect.php'; // Establishes $conn, handles basic DB connection and session_start if not already
require_once __DIR__ . '/includes/functions.php';  // Defines helper functions

header('Content-Type: application/json');

$response = ['success' => false, 'rate_display' => 'Error: Unspecified', 'emi_display' => 'N/A', 'total_repayment_display' => 'N/A', 'debug_info' => ''];

if (!isset($conn) || !$conn instanceof mysqli || !$conn->ping()) {
    $response['rate_display'] = 'Error: DB Connection Issue';
    $response['debug_info'] = 'Database connection not available or not responding in calculate_rate_ajax.php.';
    echo json_encode($response);
    exit;
}

if (!function_exists('is_logged_in') || !function_exists('calculate_dynamic_monthly_interest_rate') || !function_exists('calculate_emi_from_monthly_rate')) {
    $response['rate_display'] = 'Error: Core function(s) missing';
    $response['debug_info'] = 'One or more required functions (is_logged_in, calculate_dynamic_monthly_interest_rate, calculate_emi_from_monthly_rate) are not defined. Check includes.';
    echo json_encode($response);
    exit;
}

if (!is_logged_in()) {
    $response['rate_display'] = 'Error: Not logged in';
    $response['debug_info'] = 'User not logged in.';
    echo json_encode($response);
    exit;
}

if (isset($_GET['amount']) && isset($_GET['term'])) {
    $loan_amount = floatval($_GET['amount']);
    $loan_term_months = intval($_GET['term']);
    $user_id = $_SESSION['user_id'];
    $response['debug_info'] = "Input Amount: $loan_amount, Term: $loan_term_months, UserID: $user_id.";

    if ($loan_amount <= 0 || $loan_term_months <= 0) {
        $response['rate_display'] = 'Error: Invalid input values';
        $response['debug_info'] .= ' Loan amount and term must be positive.';
        echo json_encode($response);
        exit;
    }

    // Fetch user's credit score
    $current_credit_score = null; 
    $stmt_user_score = $conn->prepare("SELECT credit_score FROM users WHERE id = ?");
    if ($stmt_user_score) {
        $stmt_user_score->bind_param("i", $user_id);
        if ($stmt_user_score->execute()) {
            $result_user_score = $stmt_user_score->get_result();
            if ($user_score_row = $result_user_score->fetch_assoc()) {
                $current_credit_score = $user_score_row['credit_score'];
                if ($current_credit_score === null) {
                    $current_credit_score = 300; // Fallback if credit_score is NULL in DB
                    $response['debug_info'] .= " Credit score was NULL, defaulted to 300.";
                } else {
                    $response['debug_info'] .= " Fetched credit score: $current_credit_score.";
                }
            } else {
                $response['rate_display'] = 'Error: User score not found';
                $response['debug_info'] .= ' Could not fetch user credit score row.';
                $stmt_user_score->close();
                echo json_encode($response);
                exit;
            }
        } else {
            $response['rate_display'] = 'Error: DB execute (score)';
            $response['debug_info'] .= " Failed to execute user score statement: " . $stmt_user_score->error;
            $stmt_user_score->close();
            echo json_encode($response);
            exit;
        }
        $stmt_user_score->close();
    } else {
        $response['rate_display'] = 'Error: DB prepare (score)';
        $response['debug_info'] .= " Failed to prepare user score statement: " . $conn->error;
        echo json_encode($response);
        exit;
    }
    
    $monthly_interest_rate = calculate_dynamic_monthly_interest_rate(
        $current_credit_score,
        $loan_amount,
        $loan_term_months
    );
    $response['debug_info'] .= " Calculated dynamic rate: $monthly_interest_rate.";

    if ($monthly_interest_rate !== null && is_numeric($monthly_interest_rate)) {
        $response['success'] = true;
        $response['rate_display'] = number_format($monthly_interest_rate * 100, 2) . "%";

        $emi = calculate_emi_from_monthly_rate(
            $loan_amount,
            $monthly_interest_rate,
            $loan_term_months
        );
        $response['debug_info'] .= " Calculated EMI: $emi.";

        if ($emi !== null && is_numeric($emi) && $emi > 0) {
            $response['emi_display'] = '₱' . number_format($emi, 2);
            $total_repayment = $emi * $loan_term_months;
            $response['total_repayment_display'] = '₱' . number_format($total_repayment, 2);
            $response['debug_info'] .= " Calculated Total Repayment: $total_repayment.";
        } else {
            $response['success'] = false; // EMI calculation failed or was zero/negative
            $response['rate_display'] = 'Error: EMI calculation failed';
            $response['emi_display'] = 'N/A';
            $response['total_repayment_display'] = 'N/A';
            $response['debug_info'] .= ' EMI calculation resulted in non-numeric or non-positive value.';
        }
    } else {
        $response['rate_display'] = 'Error: Rate calculation failed';
        $response['debug_info'] .= ' Dynamic interest rate calculation failed or was non-numeric.';
    }
} else {
    $response['rate_display'] = 'Error: Missing parameters';
    $response['debug_info'] = 'Loan amount or term parameters missing from GET request.';
}

echo json_encode($response);
?>