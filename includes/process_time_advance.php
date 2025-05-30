<?php
// This script should be included and its function called by time_controls.php after time is updated.
// It should NOT be directly accessible via URL.

if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    http_response_code(403);
    die("Forbidden: You do not have permission to access this page directly.");
}

function process_loan_updates($db_conn, $days_advanced, $new_current_simulated_datetime_str) {
    $success_messages = [];
    $error_messages = [];

    $new_sim_datetime_obj = new DateTime($new_current_simulated_datetime_str);
    $new_sim_date_only_str = $new_sim_datetime_obj->format('Y-m-d');

    // --- Activate 'approved' loans whose start_date is reached ---
    $stmt_activate = $db_conn->prepare("UPDATE loans SET status = 'active' WHERE status = 'approved' AND start_date IS NOT NULL AND start_date <= ?");
    if ($stmt_activate) {
        $stmt_activate->bind_param("s", $new_sim_date_only_str);
        if ($stmt_activate->execute()) {
            if ($stmt_activate->affected_rows > 0) {
                $success_messages[] = $stmt_activate->affected_rows . " loan(s) newly activated as their start date was reached.";
            }
        } else {
            $error_messages[] = "Error activating loans: " . $stmt_activate->error;
        }
        $stmt_activate->close();
    } else {
        $error_messages[] = "Failed to prepare statement to activate loans: " . $db_conn->error;
    }

    // --- Define Penalty Parameters (PHP Currency) ---
    $standard_penalty_amount = 100.00;         // e.g., ₱100
    $escalated_weekly_penalty_amount = 250.00; // e.g., ₱250 weekly post-term
    $grace_period_days = 3;
    $days_past_term_for_default = 60; // e.g., 60 days past theoretical end to mark as defaulted

    // Fetch all 'active' or 'defaulted' loans that still have a balance
    $stmt_loans = $db_conn->prepare("SELECT id, user_id, status, next_payment_due_date, theoretical_end_date, last_penalized_due_date, last_escalated_penalty_date, remaining_balance FROM loans WHERE status IN ('active', 'defaulted') AND remaining_balance > 0.009"); // Check for balance > small epsilon
    if (!$stmt_loans) {
        $error_messages[] = "Failed to prepare statement to fetch active/defaulted loans: " . $db_conn->error;
        return ['success' => $success_messages, 'errors' => $error_messages];
    }

    $stmt_loans->execute();
    $loans_to_process_result = $stmt_loans->get_result();

    while ($loan = $loans_to_process_result->fetch_assoc()) {
        $apply_standard_penalty = false;
        $apply_escalated_penalty = false;
        $mark_as_defaulted = false;

        // --- Standard Penalty Logic (for 'active' loans within their term) ---
        if ($loan['status'] === 'active' && !empty($loan['next_payment_due_date'])) {
            $next_due_date_obj = new DateTime($loan['next_payment_due_date']);
            $due_date_with_grace_obj = clone $next_due_date_obj;
            $due_date_with_grace_obj->add(new DateInterval('P' . $grace_period_days . 'D'));

            if ($new_sim_datetime_obj > $due_date_with_grace_obj &&
                ($loan['last_penalized_due_date'] === null || $loan['last_penalized_due_date'] < $loan['next_payment_due_date'])) {
                $apply_standard_penalty = true;
            }
        }

        // --- Escalated Penalty & Default Logic (if past theoretical_end_date) ---
        if ($loan['status'] !== 'paid_off' && !empty($loan['theoretical_end_date']) && $loan['remaining_balance'] > 0.009) {
            $theoretical_end_obj = new DateTime($loan['theoretical_end_date']);

            if ($new_sim_datetime_obj > $theoretical_end_obj) { // Past theoretical end date
                if ($loan['status'] !== 'defaulted') { // Only apply escalated weekly if not already defaulted (defaulted loans might have different handling)
                    $last_escalated_obj = $loan['last_escalated_penalty_date'] ? new DateTime($loan['last_escalated_penalty_date']) : null;
                    
                    if ($last_escalated_obj === null) {
                        $apply_escalated_penalty = true;
                    } else {
                        $next_weekly_penalty_due_obj = clone $last_escalated_obj;
                        $next_weekly_penalty_due_obj->add(new DateInterval('P7D')); // Check 7 days after last escalated penalty
                        if ($new_sim_datetime_obj >= $next_weekly_penalty_due_obj) {
                            $apply_escalated_penalty = true;
                        }
                    }
                }

                // Check for defaulting the loan (even if already defaulted, this check is harmless)
                $days_past_term = $new_sim_datetime_obj->diff($theoretical_end_obj)->days;
                if ($loan['status'] !== 'defaulted' && $days_past_term > $days_past_term_for_default) {
                    $mark_as_defaulted = true;
                }
            }
        }

        // --- Apply Penalties and Updates ---
        if ($apply_standard_penalty || $apply_escalated_penalty || $mark_as_defaulted) {
            $db_conn->begin_transaction();
            try {
                $penalty_applied_this_cycle = false;
                $current_remaining_balance = $loan['remaining_balance']; // Work with current balance for this cycle

                if ($apply_standard_penalty) {
                    $new_remaining_balance_after_penalty = $current_remaining_balance + $standard_penalty_amount;
                    $penalty_notes = "Overdue payment penalty for due date " . $loan['next_payment_due_date'];
                    
                    $stmt_insert_std_penalty = $db_conn->prepare("INSERT INTO loan_payments (loan_id, amount_paid, payment_date, payment_type, notes) VALUES (?, ?, ?, 'penalty', ?)");
                    if(!$stmt_insert_std_penalty) throw new Exception("Prepare insert standard penalty failed: " . $db_conn->error);
                    $stmt_insert_std_penalty->bind_param("idss", $loan['id'], $standard_penalty_amount, $new_current_simulated_datetime_str, $penalty_notes);
                    if(!$stmt_insert_std_penalty->execute()) throw new Exception("Execute insert standard penalty failed: " . $stmt_insert_std_penalty->error);
                    $stmt_insert_std_penalty->close();

                    $stmt_update_loan = $db_conn->prepare("UPDATE loans SET remaining_balance = ?, last_penalized_due_date = ? WHERE id = ?");
                    if(!$stmt_update_loan) throw new Exception("Prepare update loan for std penalty failed: " . $db_conn->error);
                    $stmt_update_loan->bind_param("dsi", $new_remaining_balance_after_penalty, $loan['next_payment_due_date'], $loan['id']);
                    if(!$stmt_update_loan->execute()) throw new Exception("Execute update loan for std penalty failed: " . $stmt_update_loan->error);
                    $stmt_update_loan->close();
                    
                    $current_remaining_balance = $new_remaining_balance_after_penalty; // Update for next potential penalty in same cycle
                    $success_messages[] = "Standard penalty (₱" . number_format($standard_penalty_amount,2) . ") applied to Loan ID: " . $loan['id'];
                    $penalty_applied_this_cycle = true;
                }

                if ($apply_escalated_penalty) {
                    // Ensure we're not applying escalated penalty if a standard one for a due date just pushed it past term end in THIS same cycle
                    // This check might be complex if theoretical_end_date was very close to next_payment_due_date.
                    // For simplicity now, it applies if conditions are met based on $new_sim_datetime_obj.
                    
                    $new_remaining_balance_after_penalty = $current_remaining_balance + $escalated_weekly_penalty_amount;
                    $penalty_notes = "Escalated weekly penalty (past term end " . $loan['theoretical_end_date'] . ")";

                    $stmt_insert_esc_penalty = $db_conn->prepare("INSERT INTO loan_payments (loan_id, amount_paid, payment_date, payment_type, notes) VALUES (?, ?, ?, 'escalated_penalty', ?)");
                    if(!$stmt_insert_esc_penalty) throw new Exception("Prepare insert escalated penalty failed: " . $db_conn->error);
                    $stmt_insert_esc_penalty->bind_param("idss", $loan['id'], $escalated_weekly_penalty_amount, $new_current_simulated_datetime_str, $penalty_notes);
                    if(!$stmt_insert_esc_penalty->execute()) throw new Exception("Execute insert escalated penalty failed: " . $stmt_insert_esc_penalty->error);
                    $stmt_insert_esc_penalty->close();

                    $stmt_update_loan_esc = $db_conn->prepare("UPDATE loans SET remaining_balance = ?, last_escalated_penalty_date = ? WHERE id = ?");
                    if(!$stmt_update_loan_esc) throw new Exception("Prepare update loan for esc penalty failed: " . $db_conn->error);
                    $stmt_update_loan_esc->bind_param("dsi", $new_remaining_balance_after_penalty, $new_sim_date_only_str, $loan['id']);
                    if(!$stmt_update_loan_esc->execute()) throw new Exception("Execute update loan for esc penalty failed: " . $stmt_update_loan_esc->error);
                    $stmt_update_loan_esc->close();
                    
                    // $current_remaining_balance = $new_remaining_balance_after_penalty; // Update local if needed for more ops
                    $success_messages[] = "Escalated weekly penalty (₱" . number_format($escalated_weekly_penalty_amount,2) . ") applied to Loan ID: " . $loan['id'];
                    $penalty_applied_this_cycle = true;
                }

                if ($penalty_applied_this_cycle) {
                    $credit_score_change_penalty = -15;
                    $stmt_update_score_penalty = $db_conn->prepare("UPDATE users SET credit_score = LEAST(850, GREATEST(300, credit_score + ?)) WHERE id = ?");
                    if ($stmt_update_score_penalty) {
                        $stmt_update_score_penalty->bind_param("ii", $credit_score_change_penalty, $loan['user_id']);
                        $stmt_update_score_penalty->execute();
                        $stmt_update_score_penalty->close();
                        $success_messages[] = "Credit score adjusted for user " . $loan['user_id'] . " due to penalty on Loan ID: " . $loan['id'];
                    } else {
                        $error_messages[] = "Failed to prepare statement to update credit score for user " . $loan['user_id'];
                    }
                }
                
                if ($mark_as_defaulted) { // This happens if $days_past_term_for_default is exceeded
                    $stmt_default = $db_conn->prepare("UPDATE loans SET status = 'defaulted' WHERE id = ? AND status != 'defaulted'"); // only update if not already defaulted
                    if($stmt_default) {
                        $stmt_default->bind_param("i", $loan['id']);
                        if ($stmt_default->execute()) {
                            if ($stmt_default->affected_rows > 0) {
                                $success_messages[] = "Loan ID: " . $loan['id'] . " marked as defaulted.";
                                $credit_score_change_default = -100;
                                $stmt_update_score_default = $db_conn->prepare("UPDATE users SET credit_score = GREATEST(300, credit_score + ?) WHERE id = ?");
                                if($stmt_update_score_default){
                                    $stmt_update_score_default->bind_param("ii", $credit_score_change_default, $loan['user_id']);
                                    $stmt_update_score_default->execute();
                                    $stmt_update_score_default->close();
                                    $success_messages[] = "Credit score severely impacted for user " . $loan['user_id'] . " due to loan default on Loan ID: " . $loan['id'];
                                } else {
                                     $error_messages[] = "Failed to prepare statement to update credit score for default for user " . $loan['user_id'];
                                }
                            }
                        } else {
                            $error_messages[] = "Error executing default status update for Loan ID " . $loan['id'] . ": " . $stmt_default->error;
                        }
                        $stmt_default->close();
                    } else {
                        $error_messages[] = "Failed to prepare statement to default Loan ID " . $loan['id'];
                    }
                }
                $db_conn->commit();
            } catch (Exception $e) {
                $db_conn->rollback();
                $error_messages[] = "Critical error processing updates for Loan ID " . $loan['id'] . ": " . $e->getMessage();
            }
        }
    }
    $stmt_loans->close();

    return ['success' => $success_messages, 'errors' => $error_messages];
}
?>