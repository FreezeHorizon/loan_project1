<?php
require_once __DIR__ . '/includes/header.php'; // For session, db, functions

if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['loan_id']) && isset($_POST['payment_amount'])) {
    $loan_id = intval($_POST['loan_id']);
    $payment_amount_received = floatval($_POST['payment_amount']);
    $user_id = $_SESSION['user_id'];
    $current_sim_date = get_simulated_date($conn); // Use simulated date for payment date logic

    // Start transaction
    $conn->begin_transaction();

    try {
        // Fetch the loan to verify it belongs to the user and is active, and get current details
        $stmt_loan = $conn->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt_loan->bind_param("ii", $loan_id, $user_id);
        $stmt_loan->execute();
        $loan_result = $stmt_loan->get_result();

        if ($loan_result->num_rows === 1) {
            $loan = $loan_result->fetch_assoc();
            $stmt_loan->close();

            if ($payment_amount_received <= 0) {
                throw new Exception("Payment amount must be positive.");
            }
            
            // For simplicity, assume payment_amount_received is the EMI.
            // More complex logic could handle under/overpayments.
            $actual_payment_made = $payment_amount_received; // Could be different if partials allowed

            // Record the payment
            $stmt_payment = $conn->prepare("INSERT INTO loan_payments (loan_id, amount_paid, payment_date, payment_type) VALUES (?, ?, ?, 'scheduled')");
            // For payment_date, using current real time for record, but due date logic uses simulated time
            $payment_timestamp = date('Y-m-d H:i:s'); 
            $stmt_payment->bind_param("ids", $loan_id, $actual_payment_made, $payment_timestamp);
            $stmt_payment->execute();
            $stmt_payment->close();
		
            // Update loan's remaining balance
            $new_remaining_balance = round($loan['remaining_balance'] - $actual_payment_made, 2);
            $new_status = $loan['status'];
            $new_next_payment_due_date = $loan['next_payment_due_date'];

            if ($new_remaining_balance <= 0.01) { // Using a small threshold for float comparisons
                $new_remaining_balance = 0;
                $new_status = 'paid_off';
                $new_next_payment_due_date = null; // No next payment
            } else {
                // Calculate new next payment due date (add 1 month to the current next_payment_due_date)
                // Important: This assumes payments are made on or before due date.
                // More complex logic for late payments/penalties later.
                if ($loan['next_payment_due_date']) {
                    $next_due_obj = new DateTime($loan['next_payment_due_date']);
                    $next_due_obj->add(new DateInterval('P1M'));
                    $new_next_payment_due_date = $next_due_obj->format('Y-m-d');
                }
            }
			
            $stmt_update_loan = $conn->prepare("UPDATE loans SET remaining_balance = ?, status = ?, next_payment_due_date = ? WHERE id = ?");
            $stmt_update_loan->bind_param("dssi", $new_remaining_balance, $new_status, $new_next_payment_due_date, $loan_id);
            $stmt_update_loan->execute();
            $stmt_update_loan->close();
			$payment_made_on_datetime_obj = new DateTime($payment_timestamp); // Real time of payment
			$original_next_due_date_obj = new DateTime($loan['next_payment_due_date']);
			
			// Consider it "on-time" if paid before or on the due date
			// A more lenient check could use the grace period logic from penalty script
			if ($payment_made_on_datetime_obj <= $original_next_due_date_obj) {
				$credit_score_change = 5; // Points for on-time payment
				$stmt_update_score = $conn->prepare("UPDATE users SET credit_score = LEAST(850, GREATEST(300, credit_score + ?)) WHERE id = ?");
				if ($stmt_update_score) {
					$stmt_update_score->bind_param("ii", $credit_score_change, $user_id);
					$stmt_update_score->execute();
					$stmt_update_score->close();
					// $success_messages[] could be used if this was part of a larger process
				}
			}
            $conn->commit();
            $_SESSION['payment_message'] = "Payment of ₱" . number_format($actual_payment_made, 2) . " for loan ID $loan_id processed successfully.";
            $_SESSION['payment_message_type'] = 'success';

        } else {
            $stmt_loan->close(); // Close even if no rows
            throw new Exception("Loan not found, not active, or does not belong to you.");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['payment_message'] = "Payment failed: " . $e->getMessage();
        $_SESSION['payment_message_type'] = 'error';
    }

    redirect(BASE_URL . 'my_loans.php');
} else {
    // If accessed directly or missing parameters
    $_SESSION['payment_message'] = "Invalid payment request.";
    $_SESSION['payment_message_type'] = 'error';
    redirect(BASE_URL . 'my_loans.php');
}

// Footer not typically needed as this script just processes and redirects
// require_once __DIR__ . '/includes/footer.php';
?>