<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_super_admin()) { // Only Super Admins
    redirect(BASE_URL . 'login.php');
}

$current_super_admin_id = $_SESSION['user_id'];
$feedback_message = '';
$feedback_type = '';

// Handle Approve/Reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_id']) && isset($_POST['decision'])) {
    $action_id = (int)$_POST['action_id'];
    $decision = $_POST['decision']; // 'approve' or 'reject'
    $super_admin_remarks = isset($_POST['super_admin_remarks']) ? sanitize_input($_POST['super_admin_remarks']) : '';

    // Fetch the pending action details
    $stmt_action = $conn->prepare("SELECT target_user_id, target_object_id, action_type, proposed_changes FROM admin_actions_log WHERE id = ? AND status = 'pending'");
    if (!$stmt_action) {
        $feedback_message = "Error preparing to fetch action: " . $conn->error;
        $feedback_type = "error";
    } else {
        $stmt_action->bind_param("i", $action_id);
        $stmt_action->execute();
        $result_action = $stmt_action->get_result();

        if ($result_action->num_rows === 1) {
            $action_details = $result_action->fetch_assoc();
            $target_user_id = $action_details['target_user_id']; // User context (e.g. loan owner, or the user being edited if action_type is edit_user_details)
            $target_object_id = $action_details['target_object_id']; // Actual ID of loan, or user if editing user.
            $action_type = $action_details['action_type'];
            $proposed_changes = json_decode($action_details['proposed_changes'], true);

            $conn->begin_transaction();
            try {
                if ($decision === 'approve') {
                    if ($action_type === 'edit_user_details' && !empty($proposed_changes)) {
                        // Apply user detail changes to users table
                        $id_to_update = $target_user_id; // For user edits, target_user_id is the user being edited.
                        $update_fields = []; $update_params = []; $types = "";
                        foreach ($proposed_changes as $field => $value) {
                            $update_fields[] = "`{$field}` = ?"; $update_params[] = $value;
                            if (is_int($value)) $types .= "i"; elseif (is_double($value)) $types .= "d"; else $types .= "s";
                        }
                        $types .= "i"; $update_params[] = $id_to_update; 
                        $sql_update = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                        $stmt_update = $conn->prepare($sql_update);
                        if (!$stmt_update) throw new Exception("Error preparing user update: " . $conn->error);
                        $stmt_update->bind_param($types, ...$update_params);
                        if (!$stmt_update->execute()) throw new Exception("Error executing user update: " . $stmt_update->error);
                        $stmt_update->close();

                    } elseif ($action_type === 'edit_loan_details' && !empty($proposed_changes)) {
                        // Apply loan detail changes to loans table
                        $loan_id_to_update = $target_object_id; // For loan edits, target_object_id is the loan_id.
                        $loan_user_id = $target_user_id; // User who owns the loan, from the log.

                        // Handle settlement payment recording
                        if (isset($proposed_changes['settlement_payment_recorded']) && $proposed_changes['settlement_payment_recorded'] > 0) {
                            $settlement_amount = (float)$proposed_changes['settlement_payment_recorded'];
                            
                            $stmt_payment = $conn->prepare(
                                "INSERT INTO loan_payments (loan_id, user_id, amount_paid, payment_date, payment_method, notes, payment_type) " .
                                "VALUES (?, ?, ?, NOW(), 'Admin Settlement', 'Settlement payment recorded upon Super Admin approval.', 'settlement')"
                            );
                            if (!$stmt_payment) throw new Exception("Error preparing settlement payment insertion: " . $conn->error);
                            $stmt_payment->bind_param("iid", $loan_id_to_update, $loan_user_id, $settlement_amount);
                            if (!$stmt_payment->execute()) throw new Exception("Error executing settlement payment insertion: " . $stmt_payment->error);
                            $stmt_payment->close();
                            
                            // Remove from proposed_changes as it's not a direct column in loans table for the main update
                            unset($proposed_changes['settlement_payment_recorded']);
                        }

                        // Ensure remaining balance is set to 0 if status is being changed to a settled state
                        if (isset($proposed_changes['status']) && 
                            ($proposed_changes['status'] === 'settled_default' || $proposed_changes['status'] === 'paid_post_default')) {
                            $proposed_changes['remaining_balance'] = 0.00;
                        }

                        // Re-check if there are any changes left to apply after handling settlement
                        if (empty($proposed_changes)) {
                             // No actual changes to loans table itself, but settlement might have been recorded.
                             // The log update will still happen. We can skip loan table update.
                             error_log("[INFO] Loan edit approval for loan ID {$loan_id_to_update}: Only settlement payment recorded, no other direct loan field changes.");
                        } else {
                            $update_fields = []; $update_params = []; $types = "";
                            foreach ($proposed_changes as $field => $value) {
                                $update_fields[] = "`{$field}` = ?"; 
                                $update_params[] = $value;
                                // Refined type detection
                                if (in_array($field, ['status', 'next_payment_due_date', 'loan_end_term_date'])) {
                                    $types .= "s"; 
                                } elseif (is_int($value) && ($field === 'term_months' || $field === 'user_id' /*if it were editable*/)) { 
                                    $types .= "i";
                                } elseif (is_float($value) || is_numeric($value) && ($field === 'amount_requested' || $field === 'amount_approved' || $field === 'remaining_balance' || $field === 'interest_rate')) {
                                    $types .= "d";
                                } else {
                                    $types .= "s"; // Default to string if unsure or for other text fields
                                }
                            }
                            $types .= "i"; $update_params[] = $loan_id_to_update;
                            $sql_update = "UPDATE loans SET " . implode(", ", $update_fields) . " WHERE id = ?";
                            $stmt_update = $conn->prepare($sql_update);
                            if (!$stmt_update) throw new Exception("Error preparing loan update: " . $conn->error);
                            $stmt_update->bind_param($types, ...$update_params);
                            if (!$stmt_update->execute()) throw new Exception("Error executing loan update: " . $stmt_update->error);
                            $stmt_update->close();
                        }

                    } elseif ($action_type === 'request_loan_deletion') {
                        // Soft delete loan by updating its status
                        $id_to_update = $target_object_id; // For loan deletion, target_object_id is the loan_id.
                        $new_loan_status = 'cancelled_by_admin';
                        error_log("[DEBUG] Approving deletion for loan ID: {$id_to_update}. Attempting to set status to {$new_loan_status}."); // Log before

                        $stmt_update_loan_status = $conn->prepare("UPDATE loans SET status = ? WHERE id = ?");
                        if (!$stmt_update_loan_status) {
                            $error_msg = "[ERROR] Failed to prepare statement for loan status update (deletion approval) for loan ID {$id_to_update}: " . $conn->error;
                            error_log($error_msg);
                            throw new Exception($error_msg);
                        }
                        $stmt_update_loan_status->bind_param("si", $new_loan_status, $id_to_update);
                        if ($stmt_update_loan_status->execute()) {
                            $affected_rows = $stmt_update_loan_status->affected_rows;
                            error_log("[DEBUG] Successfully executed loan status update (deletion approval) for loan ID {$id_to_update}. Affected rows: {$affected_rows}.");
                            if ($affected_rows === 0) {
                                error_log("[WARNING] Loan status update (deletion approval) for loan ID {$id_to_update} affected 0 rows. Status might have already been '{$new_loan_status}' or loan ID was invalid.");
                                // Consider if this should be an exception based on strictness
                            }
                        } else {
                            $error_msg = sprintf("[ERROR] Failed to execute loan status update (deletion approval) for loan ID %d: [%d] %s", 
                                             $id_to_update, 
                                             $stmt_update_loan_status->errno, 
                                             $stmt_update_loan_status->error);
                            error_log($error_msg);
                            throw new Exception($error_msg);
                        }
                        $stmt_update_loan_status->close();
                    }
                }

                // Update admin_actions_log status for all action types
                $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
                $stmt_update_log = $conn->prepare("UPDATE admin_actions_log SET status = ?, reviewed_by_super_admin_id = ?, reviewed_at = NOW(), super_admin_remarks = ? WHERE id = ?");
                if (!$stmt_update_log) throw new Exception("Error preparing log update: " . $conn->error);
                $stmt_update_log->bind_param("sisi", $new_status, $current_super_admin_id, $super_admin_remarks, $action_id);
                if (!$stmt_update_log->execute()) throw new Exception("Error updating log: " . $stmt_update_log->error);
                $stmt_update_log->close();

                $conn->commit();
                $feedback_message = "The request (ID: {$action_id}, Type: {$action_type}) has been successfully " . htmlspecialchars($new_status) . ".";
                $feedback_type = "success";

            } catch (Exception $e) {
                $conn->rollback();
                $feedback_message = "An error occurred processing action ID {$action_id}: " . $e->getMessage();
                $feedback_type = "error";
            }
        } else {
            $feedback_message = "Pending action ID {$action_id} not found or already processed.";
            $feedback_type = "error";
        }
        if($stmt_action) $stmt_action->close();
    }
}


// Fetch ALL pending admin actions
$pending_actions = [];
$sql_pending = "SELECT aal.*, ru.username as requesting_admin_username, tu.username as target_user_username 
                FROM admin_actions_log aal
                JOIN users ru ON aal.admin_user_id = ru.id
                JOIN users tu ON aal.target_user_id = tu.id  -- target_user_id is always the user context (owner/edited user)
                WHERE aal.status = 'pending' 
                ORDER BY aal.requested_at ASC";
$result_pending = $conn->query($sql_pending);
if ($result_pending) {
    while ($row = $result_pending->fetch_assoc()) {
        $row['proposed_changes_array'] = json_decode($row['proposed_changes'], true);
        $row['current_values_array'] = json_decode($row['current_values'], true);
        // For loan actions, fetch loan details if not already in current_values in a user-friendly way
        if ($row['action_type'] === 'edit_loan_details' || $row['action_type'] === 'request_loan_deletion') {
            $stmt_loan_info = $conn->prepare("SELECT amount_requested, status FROM loans WHERE id = ?"); // Basic info
            if($stmt_loan_info){
                $stmt_loan_info->bind_param("i", $row['target_object_id']); // Use target_object_id for loan_id
                $stmt_loan_info->execute();
                $res_loan_info = $stmt_loan_info->get_result();
                if($res_loan_info->num_rows === 1){
                    $row['loan_info_for_display'] = $res_loan_info->fetch_assoc();
                }
                $stmt_loan_info->close();
            }
        }
        $pending_actions[] = $row;
    }
} else {
    $feedback_message = "Error fetching pending requests: " . $conn->error;
    $feedback_type = "error";
}

?>

<h2>Approve Admin Requests</h2>

<?php if ($feedback_message): ?>
    <p class="message <?php echo htmlspecialchars($feedback_type); ?>"><?php echo htmlspecialchars($feedback_message); ?></p>
<?php endif; ?>

<?php if (empty($pending_actions)): ?>
    <p>There are no pending admin requests at this time.</p>
<?php else: ?>
    <table border="1" style="width:100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr>
                <th>Log ID</th>
                <th>Requested By (Admin)</th>
                <th>Action Type</th>
                <th>Target</th> <!-- User or Loan ID -->
                <th>Requested At</th>
                <th>Details / Changes Requested</th>
                <th>Admin Reason</th>
                <th>Super Admin Remarks</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pending_actions as $action): ?>
                <tr>
                    <td><?php echo htmlspecialchars($action['id']); ?></td>
                    <td><?php echo htmlspecialchars($action['requesting_admin_username']); ?> (ID: <?php echo htmlspecialchars($action['admin_user_id']); ?>)</td>
                    <td style="text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $action['action_type'])); ?></td>
                    <td>
                        <?php 
                        echo "User: " . htmlspecialchars($action['target_user_username']) . " (ID: " . htmlspecialchars($action['target_user_id']) . ")";
                        if ($action['action_type'] === 'edit_loan_details' || $action['action_type'] === 'request_loan_deletion') {
                            echo "<br>Loan ID: " . htmlspecialchars($action['target_object_id']); // Use target_object_id for loan_id
                            if(isset($action['loan_info_for_display'])) {
                                echo " (Current Status: " . htmlspecialchars($action['loan_info_for_display']['status']) . ", Amt. Req.: ₱" . number_format($action['loan_info_for_display']['amount_requested'],2) . ")";
                            }
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($action['requested_at']))); ?></td>
                    <td>
                        <ul>
                        <?php 
                        if ($action['action_type'] === 'edit_user_details') {
                            echo "<li><strong>Summary:</strong> Modifying user profile details.</li>";
                            if (is_array($action['proposed_changes_array'])) {
                                foreach ($action['proposed_changes_array'] as $field => $proposed_value) {
                                    $current_val_display = '[Not Set]';
                                    if (isset($action['current_values_array'][$field])) {
                                        $cv = $action['current_values_array'][$field];
                                        if (in_array($field, ['next_payment_due_date', 'loan_end_term_date', 'request_date']) && $cv) {
                                            $current_val_display = date('Y-m-d', strtotime($cv));
                                        } else {
                                            $current_val_display = is_array($cv) || is_object($cv) ? json_encode($cv) : strval($cv);
                                        }
                                    }
                                    $pv = $proposed_value;
                                    if (in_array($field, ['next_payment_due_date', 'loan_end_term_date', 'request_date']) && $pv) {
                                        $proposed_val_display = date('Y-m-d', strtotime($pv));
                                    } else {
                                        $proposed_val_display = is_array($pv) || is_object($pv) ? json_encode($pv) : strval($pv);
                                    }
                                    echo "<li><strong>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $field))) . ":</strong> " . 
                                         htmlspecialchars($current_val_display) . " ➞ " . 
                                         htmlspecialchars($proposed_val_display) . "</li>";
                                }
                            } else {
                                echo "<li>Error decoding changes.</li>";
                            }
                        } elseif ($action['action_type'] === 'edit_loan_details') {
                            echo "<li><strong>Summary:</strong> Modifying loan record details.</li>";
                            if (is_array($action['proposed_changes_array'])) {
                                foreach ($action['proposed_changes_array'] as $field => $proposed_value) {
                                    $current_val_display = '[Not Set]';
                                    if (isset($action['current_values_array'][$field])) {
                                        $cv = $action['current_values_array'][$field];
                                        if (in_array($field, ['next_payment_due_date', 'loan_end_term_date', 'request_date']) && $cv) {
                                            $current_val_display = date('Y-m-d', strtotime($cv));
                                        } else {
                                            $current_val_display = is_array($cv) || is_object($cv) ? json_encode($cv) : strval($cv);
                                        }
                                    }
                                    $pv = $proposed_value;
                                    if (in_array($field, ['next_payment_due_date', 'loan_end_term_date', 'request_date']) && $pv) {
                                        $proposed_val_display = date('Y-m-d', strtotime($pv));
                                    } else {
                                        $proposed_val_display = is_array($pv) || is_object($pv) ? json_encode($pv) : strval($pv);
                                    }
                                    echo "<li><strong>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $field))) . ":</strong> " . 
                                         htmlspecialchars($current_val_display) . " ➞ " . 
                                         htmlspecialchars($proposed_val_display) . "</li>";
                                }
                            } else {
                                echo "<li>Error decoding changes.</li>";
                            }
                        } elseif ($action['action_type'] === 'request_loan_deletion') {
                            echo "<li><strong>Summary:</strong> Request to cancel Loan ID: " . htmlspecialchars($action['target_object_id']) . "</li>"; // Use target_object_id for loan_id
                            if (is_array($action['current_values_array']) && !empty($action['current_values_array'])) {
                                echo "<li>Current Details of Loan to be Cancelled:</li><ul>";
                                foreach($action['current_values_array'] as $key => $val) {
                                     echo "<li><strong>" . htmlspecialchars(ucfirst(str_replace('_',' ',$key))) . ":</strong> " . htmlspecialchars($val) . "</li>";
                                }
                                echo "</ul>";
                            }
                        }
                        ?>
                        </ul>
                    </td>
                    <td><?php echo nl2br(htmlspecialchars($action['admin_reason'])); ?></td>
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" style="display:inline;">
                        <input type="hidden" name="action_id" value="<?php echo htmlspecialchars($action['id']); ?>">
                        <td>
                            <textarea name="super_admin_remarks" rows="2" style="width:95%;" placeholder="Optional remarks..."></textarea>
                        </td>
                        <td>
                            <button type="submit" name="decision" value="approve" class="button success">Approve</button>
                            <button type="submit" name="decision" value="reject" class="button danger" style="margin-top:5px;">Reject</button>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?> 