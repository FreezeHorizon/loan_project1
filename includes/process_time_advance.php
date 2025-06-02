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
    $standard_penalty_amount = 200.00;         // e.g., ₱200
    $escalated_weekly_penalty_amount = 250.00; // Changed to ₱250 weekly post-term
    $default_annual_interest_rate = 0.24; // 24% APR for defaulted loans
    $daily_default_rate = $default_annual_interest_rate / 365;
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
        $apply_default_interest = false;
        $days_for_default_interest_calc = 0;

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
            $number_of_weekly_penalties_to_apply = 0;

            if ($new_sim_datetime_obj > $theoretical_end_obj) { // Past theoretical end date
                if ($loan['status'] !== 'defaulted') {
                    $last_escalated_obj = $loan['last_escalated_penalty_date'] ? new DateTime($loan['last_escalated_penalty_date']) : null;
                    
                    // Determine the starting point for counting weekly penalties
                    // If never penalized weekly, start counting from theoretical_end_date.
                    // Otherwise, start from last_escalated_penalty_date.
                    $penalty_count_start_date_obj = $last_escalated_obj ? clone $last_escalated_obj : clone $theoretical_end_obj;

                    // Ensure we only start penalizing after the term effectively ends.
                    if ($new_sim_datetime_obj > $penalty_count_start_date_obj) {
                        $interval_since_last_penalty = $new_sim_datetime_obj->diff($penalty_count_start_date_obj);
                        $days_overdue_for_weekly = $interval_since_last_penalty->days;

                        if ($last_escalated_obj === null) {
                            // If never penalized before, the first penalty applies if it's simply past theoretical_end_date
                            // and we are at least one day into the post-term period for this check cycle.
                            // More accurately, how many full weeks past theoretical_end_date are we?
                            // Let's count full weeks from theoretical_end_date if no last_escalated_obj
                            $days_past_theoretical_end = $new_sim_datetime_obj->diff($theoretical_end_obj)->days;
                            if ($days_past_theoretical_end >= 1) { // Must be at least 1 day past to consider first week
                                $number_of_weekly_penalties_to_apply = floor($days_past_theoretical_end / 7);
                                // If last_escalated_obj was null, this counts all weeks since term end.
                                // We need to ensure it only applies *new* penalties if script runs multiple times within a week.
                                // The last_escalated_penalty_date update will prevent re-application for the same week.
                                // If $days_past_theoretical_end < 7, floor is 0, so first penalty is after 7 full days.
                                // This should be: if it's 1-7 days past, apply 1 if not applied for this week.
                                // Let's adjust: first penalty if new_sim > theoretical_end. Then subsequent based on 7 day intervals.
                                if ($new_sim_datetime_obj > $theoretical_end_obj && $last_escalated_obj === null) {
                                    $number_of_weekly_penalties_to_apply = 1; // First one if past end and never penalized weekly
                                } elseif ($last_escalated_obj !== null) {
                                    $date_for_next_penalty = clone $last_escalated_obj;
                                    $date_for_next_penalty->add(new DateInterval('P7D'));
                                    if ($new_sim_datetime_obj >= $date_for_next_penalty) {
                                        // Calculate how many 7-day intervals have passed since last penalty date
                                        $number_of_weekly_penalties_to_apply = floor($new_sim_datetime_obj->diff($last_escalated_obj)->days / 7);
                                    }
                                }
                            }
                        } else { // $last_escalated_obj is not null
                            $date_for_next_penalty = clone $last_escalated_obj;
                            $date_for_next_penalty->add(new DateInterval('P7D'));
                            if ($new_sim_datetime_obj >= $date_for_next_penalty) {
                                // Calculate how many 7-day intervals have passed since last penalty date
                                $number_of_weekly_penalties_to_apply = floor($new_sim_datetime_obj->diff($last_escalated_obj)->days / 7);
                            }
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

        // --- Default Interest Logic (NEW) ---
        if ($loan['status'] === 'defaulted' && $loan['remaining_balance'] > 0.009) {
            $last_accrual_date_str = $loan['last_default_interest_accrual_date'];
            $default_interest_start_date_obj = null;

            if ($last_accrual_date_str) {
                $default_interest_start_date_obj = new DateTime($last_accrual_date_str);
                // Move start date to the day AFTER last accrual to avoid double charging for the same day
                $default_interest_start_date_obj->add(new DateInterval('P1D')); 
            } else {
                // If never accrued, find out when it was defaulted. 
                // This requires knowing the date it was marked defaulted. 
                // For simplicity now, if last_default_interest_accrual_date is NULL,
                // we might assume it was defaulted *before* the current processing window start if $days_advanced > 0.
                // A more robust way would be to store `defaulted_on_date` in loans table.
                // Let's assume for now: if null, and $days_advanced > 0, calculate for $days_advanced.
                // Or, if new_sim_datetime_obj is simply later than when it could have defaulted.
                // For a simpler first pass, let's calculate from theoretical_end_date + $days_past_term_for_default, or from loan start if defaulted early (not current case)
                // Or, if $last_accrual_date_str is NULL, we calculate interest from the beginning of the current processing period if it became defaulted before that.
                // Let's use $new_sim_datetime_obj and $days_advanced for now, assuming it applies to the period being advanced.
                // This means if it was defaulted long ago and this is the first run, it only gets for current advance period.
                // This needs refinement for historical catch-up if the script hasn't run for a while.
                
                // Simplified: If last_default_interest_accrual_date is null, consider interest for the days advanced in this run.
                // More accurate would be from the date it was marked defaulted.
                // We will assume if it is null, and we advance N days, it applies for N days.
                // The $default_interest_start_date_obj remains null, and days_for_default_interest_calc will use $days_advanced.
                 $days_for_default_interest_calc = $days_advanced; // Default to days_advanced if no prior accrual date
            }

            if ($default_interest_start_date_obj && $new_sim_datetime_obj > $default_interest_start_date_obj) {
                $days_for_default_interest_calc = $new_sim_datetime_obj->diff($default_interest_start_date_obj)->days + 1; // +1 to include end date
            } elseif (!$last_accrual_date_str && $days_advanced > 0) { // If NULL and script advanced time
                 $days_for_default_interest_calc = $days_advanced;
            } else {
                $days_for_default_interest_calc = 0; // No period to calculate for or already up to date.
            }
            
            if ($days_for_default_interest_calc > 0) {
                $apply_default_interest = true;
            }
        }

        // --- Apply Penalties and Updates ---
        if ($apply_standard_penalty || $number_of_weekly_penalties_to_apply > 0 || $mark_as_defaulted || $apply_default_interest) {
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

                if ($number_of_weekly_penalties_to_apply > 0 && $loan['status'] !== 'defaulted') {
                    $total_escalated_penalty_this_cycle = 0;
                    $latest_penalty_application_date_str = $loan['last_escalated_penalty_date'] ?: $loan['theoretical_end_date'];
                    $date_iter = new DateTime($latest_penalty_application_date_str);
                    
                    $applied_in_loop = 0;
                    for ($i = 0; $i < $number_of_weekly_penalties_to_apply; $i++) {
                        // Determine the actual date this specific weekly penalty instance corresponds to.
                        // If last_escalated_penalty_date is null, first penalty is for first week past theoretical_end_date.
                        // Otherwise, it's 7 days after the last one.
                        if ($i == 0 && $loan['last_escalated_penalty_date'] === null) {
                            // First penalty application post-term, ensure it aligns with a week boundary or just after term end.
                            // Let's consider the penalty applies for the week *ending* on or after 7 days past theoretical_end_date.
                            // Or simplify: if it's due, it's due. The $number_of_weekly_penalties_to_apply handles accumulation.
                            // The $new_sim_date_only_str will be used as the last_escalated_penalty_date for this batch.
                        } else {
                            // For subsequent penalties in a large skip, they correspond to subsequent weeks.
                        }

                        $current_remaining_balance += $escalated_weekly_penalty_amount;
                        $total_escalated_penalty_this_cycle += $escalated_weekly_penalty_amount;
                        $penalty_notes = "Escalated weekly penalty (₱" . number_format($escalated_weekly_penalty_amount,2) . ") for week after " . $date_iter->format('Y-m-d');
                        
                        // We should record each penalty instance if needed, or one summary record for this cycle.
                        // For now, let's assume the single log for the sum is ok, and update loan balance and last date.
                        $applied_in_loop++;
                    }

                    if ($applied_in_loop > 0) {
                        $penalty_notes_summary = $applied_in_loop . "x Escalated weekly penalty (₱" . number_format($escalated_weekly_penalty_amount,2) . " ea.) applied. Total: ₱" . number_format($total_escalated_penalty_this_cycle,2) . ". Effective date: " . $new_sim_date_only_str;

                        $stmt_insert_esc_penalty = $db_conn->prepare("INSERT INTO loan_payments (loan_id, amount_paid, payment_date, payment_type, notes) VALUES (?, ?, ?, 'escalated_penalty', ?)");
                        if(!$stmt_insert_esc_penalty) throw new Exception("Prepare insert escalated penalty failed: " . $db_conn->error);
                        // We are logging total penalty for this cycle as one payment record.
                        $stmt_insert_esc_penalty->bind_param("idss", $loan['id'], $total_escalated_penalty_this_cycle, $new_current_simulated_datetime_str, $penalty_notes_summary);
                        if(!$stmt_insert_esc_penalty->execute()) throw new Exception("Execute insert escalated penalty failed: " . $stmt_insert_esc_penalty->error);
                        $stmt_insert_esc_penalty->close();

                        $stmt_update_loan_esc = $db_conn->prepare("UPDATE loans SET remaining_balance = ?, last_escalated_penalty_date = ? WHERE id = ?");
                        if(!$stmt_update_loan_esc) throw new Exception("Prepare update loan for esc penalty failed: " . $db_conn->error);
                        // Update with the final remaining_balance and set the last_escalated_penalty_date to the current simulated date
                        $stmt_update_loan_esc->bind_param("dsi", $current_remaining_balance, $new_sim_date_only_str, $loan['id']);
                        if(!$stmt_update_loan_esc->execute()) throw new Exception("Execute update loan for esc penalty failed: " . $stmt_update_loan_esc->error);
                        $stmt_update_loan_esc->close();
                        
                        $success_messages[] = $applied_in_loop . " escalated weekly penalty/penalties (total ₱" . number_format($total_escalated_penalty_this_cycle,2) . ") applied to Loan ID: " . $loan['id'];
                        $penalty_applied_this_cycle = true; // Ensure credit score is affected
                    }
                }
                
                // --- Apply Default Interest (NEW) ---
                if ($apply_default_interest) {
                    $interest_to_add = 0;
                    $calculated_interest_for_period = ($loan['remaining_balance'] * $daily_default_rate * $days_for_default_interest_calc);
                    $interest_to_add = round($calculated_interest_for_period, 2); // Round to 2 decimal places

                    if ($interest_to_add > 0) {
                        $new_balance_after_default_interest = $current_remaining_balance + $interest_to_add;
                        $default_interest_notes = sprintf("Default interest accrued for %d day(s) at %.4f%% daily (24%% APR).", $days_for_default_interest_calc, $daily_default_rate * 100);

                        $stmt_insert_def_int = $db_conn->prepare("INSERT INTO loan_payments (loan_id, amount_paid, payment_date, payment_type, notes) VALUES (?, ?, ?, 'default_interest', ?)");
                        if(!$stmt_insert_def_int) throw new Exception("Prepare insert default interest failed: " . $db_conn->error);
                        $stmt_insert_def_int->bind_param("idss", $loan['id'], $interest_to_add, $new_current_simulated_datetime_str, $default_interest_notes);
                        if(!$stmt_insert_def_int->execute()) throw new Exception("Execute insert default interest failed: " . $stmt_insert_def_int->error);
                        $stmt_insert_def_int->close();

                        $stmt_update_loan_def_int = $db_conn->prepare("UPDATE loans SET remaining_balance = ?, last_default_interest_accrual_date = ? WHERE id = ?");
                        if(!$stmt_update_loan_def_int) throw new Exception("Prepare update loan for default interest failed: " . $db_conn->error);
                        $stmt_update_loan_def_int->bind_param("dsi", $new_balance_after_default_interest, $new_sim_date_only_str, $loan['id']);
                        if(!$stmt_update_loan_def_int->execute()) throw new Exception("Execute update loan for default interest failed: " . $stmt_update_loan_def_int->error);
                        $stmt_update_loan_def_int->close();
                        
                        $current_remaining_balance = $new_balance_after_default_interest; // Update for this cycle
                        $success_messages[] = "Default interest (₱" . number_format($interest_to_add,2) . ") applied to Loan ID: " . $loan['id'];
                        // No direct credit score hit here for default interest itself, the main hit was at time of defaulting.
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