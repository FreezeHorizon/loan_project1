<?php
// This script should be included and its function called by time_controls.php after time is updated.
// It should NOT be directly accessible via URL.

if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    // Prevent direct access to this file
    http_response_code(403);
    die("Forbidden: You do not have permission to access this page directly.");
}

function process_loan_updates($db_conn, $days_advanced, $new_current_simulated_datetime_str) {
    $success_messages = [];
    $error_messages = [];

    $new_sim_date_obj = new DateTime($new_current_simulated_datetime_str);
    $new_sim_date_only_str = $new_sim_date_obj->format('Y-m-d');

    // --- Activate 'approved' loans whose start_date is reached ---
    $stmt_activate = $db_conn->prepare("UPDATE loans SET status = 'active' WHERE status = 'approved' AND start_date IS NOT NULL AND start_date <= ?");
    if ($stmt_activate) {
        $stmt_activate->bind_param("s", $new_sim_date_only_str);
        if ($stmt_activate->execute()) {
            if ($stmt_activate->affected_rows > 0) {
                $success_messages[] = $stmt_activate->affected_rows . " loan(s) activated as their start date was reached.";
            }
        } else {
            $error_messages[] = "Error activating loans: " . $stmt_activate->error;
        }
        $stmt_activate->close();
    } else {
        $error_messages[] = "Failed to prepare statement to activate loans: " . $db_conn->error;
    }
    // --- End loan activation ---

    // --- Define Penalty Parameters ---
    $penalty_amount = 10.00; // Fixed penalty amount for now (e.g., $10)
    $grace_period_days = 3; // Optional: Number of days after due date before penalty applies

    // Fetch all 'active' loans
    $stmt_loans = $db_conn->prepare("SELECT id, user_id, next_payment_due_date, remaining_balance, term_months, interest_rate_monthly, amount_approved FROM loans WHERE status = 'active'");
    if (!$stmt_loans) {
        $error_messages[] = "Failed to prepare statement to fetch active loans: " . $db_conn->error;
        return ['success' => $success_messages, 'errors' => $error_messages];
    }

    $stmt_loans->execute();
    $active_loans_result = $stmt_loans->get_result();

    while ($loan = $active_loans_result->fetch_assoc()) {
        if (empty($loan['next_payment_due_date'])) {
            continue; // Should not happen for active loans, but good to check
        }

        $next_due_date_obj = new DateTime($loan['next_payment_due_date']);
        
        // Check if next_payment_due_date is in the past compared to the new simulated date (ignoring time part for due date check)
        // And apply grace period
        $due_date_with_grace = clone $next_due_date_obj;
        $due_date_with_grace->add(new DateInterval('P' . $grace_period_days . 'D'));

        if ($new_sim_date_obj > $due_date_with_grace) {
            // Payment is overdue beyond grace period.
            // For now, we apply a penalty.
            // A more complex check would be to see if a payment covering this due date has already been made.
            // This simple version assumes if it's past due, a penalty applies.

            $db_conn->begin_transaction(); // Start transaction for this loan's updates

            try {
                $new_remaining_balance_after_penalty = $loan['remaining_balance'] + $penalty_amount;

                // Record the penalty payment
                $penalty_payment_type = 'penalty';
                $penalty_notes = "Overdue payment penalty applied on " . $new_sim_date_only_str . " for due date " . $loan['next_payment_due_date'];
                $penalty_timestamp = $new_current_simulated_datetime_str; // Record with simulated datetime

                $stmt_insert_penalty = $db_conn->prepare("INSERT INTO loan_payments (loan_id, amount_paid, payment_date, payment_type, notes) VALUES (?, ?, ?, ?, ?)");
                if (!$stmt_insert_penalty) throw new Exception("Prepare insert penalty failed: " . $db_conn->error);
                
                // Note: amount_paid for penalty is the penalty amount itself, it doesn't reduce principal here.
                // It increases the total remaining balance.
                $stmt_insert_penalty->bind_param("idsss", $loan['id'], $penalty_amount, $penalty_timestamp, $penalty_payment_type, $penalty_notes);
                if (!$stmt_insert_penalty->execute()) throw new Exception("Execute insert penalty failed: " . $stmt_insert_penalty->error);
                $stmt_insert_penalty->close();

                // Update the loan's remaining balance
                $stmt_update_loan_balance = $db_conn->prepare("UPDATE loans SET remaining_balance = ? WHERE id = ?");
                if (!$stmt_update_loan_balance) throw new Exception("Prepare update loan balance failed: " . $db_conn->error);
                
                $stmt_update_loan_balance->bind_param("di", $new_remaining_balance_after_penalty, $loan['id']);
                if (!$stmt_update_loan_balance->execute()) throw new Exception("Execute update loan balance failed: " . $stmt_update_loan_balance->error);
                $stmt_update_loan_balance->close();
                
                $db_conn->commit();
                $success_messages[] = "Penalty of $" . number_format($penalty_amount, 2) . " applied to Loan ID: " . $loan['id'] . ".";

                // Future: Add logic here to change loan status to 'defaulted' if overdue by X payments/days.
                // ---- CREDIT SCORE UPDATE ON PENALTY ----
                $credit_score_change_penalty = -15; // Points deducted for penalty
                $stmt_update_score_penalty = $db_conn->prepare("UPDATE users SET credit_score = LEAST(850, GREATEST(300, credit_score + ?)) WHERE id = ?");
                // Note: using '+' with a negative number to subtract
                if ($stmt_update_score_penalty) {
                    $stmt_update_score_penalty->bind_param("ii", $credit_score_change_penalty, $loan['user_id']);
                    $stmt_update_score_penalty->execute();
                    $stmt_update_score_penalty->close();
                }
                // ---- END CREDIT SCORE UPDATE ----
            } catch (Exception $e) {
                $db_conn->rollback();
                $error_messages[] = "Error processing penalty for Loan ID " . $loan['id'] . ": " . $e->getMessage();
            }
        }
    }
    $stmt_loans->close();

    // Add other periodic tasks here if needed (e.g., monthly interest accrual if not using EMI, credit score updates)

    return ['success' => $success_messages, 'errors' => $error_messages];
}
?>