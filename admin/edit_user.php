<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$user_id_to_edit = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$current_admin_id = $_SESSION['user_id']; // This is the logged-in admin's ID

// Safeguard: Super Admins cannot edit their own profile via this page.
if (is_super_admin() && $user_id_to_edit === $current_admin_id) {
    $_SESSION['feedback_message_manage_users'] = "Super Admins cannot edit their own profile through this interface."; // Use a distinct session key
    $_SESSION['feedback_type_manage_users'] = "error";
    redirect(BASE_URL . 'admin/manage_users.php'); 
    exit; 
}

$current_admin_s_role = isset($_SESSION['role']) ? $_SESSION['role'] : null; // Use $_SESSION['role'] and check if set
$feedback_message = '';
$feedback_type = '';
$user_details = null;
$editing_an_admin_or_super_admin = false; // Flag to indicate if we are editing an admin/super_admin

// Fetch user details for editing
if ($user_id_to_edit > 0) {
    $stmt = $conn->prepare("SELECT id, username, email, full_name, role, credit_score, monthly_income FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id_to_edit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $user_details = $result->fetch_assoc();
            // Check if the user being edited is an admin or super_admin
            if (in_array($user_details['role'], ['admin', 'super_admin'])) {
                $editing_an_admin_or_super_admin = true;
            }
        } else {
            $feedback_message = "User not found.";
            $feedback_type = "error";
        }
        $stmt->close();
    } else {
        $feedback_message = "Error preparing to fetch user: " . $conn->error;
        $feedback_type = "error";
    }
} else {
    $feedback_message = "No user ID provided.";
    $feedback_type = "error";
}

// Fetch user's loan records (added for display)
$user_loans = [];
if ($user_id_to_edit > 0 && !$editing_an_admin_or_super_admin) { // Only fetch loans if it's a regular user
    $stmt_loans = $conn->prepare(
        "SELECT l.id, l.amount_requested, l.amount_approved, l.term_months, l.status, l.request_date as loan_requested_date, l.next_payment_due_date, l.remaining_balance, l.loan_end_term_date, " .
        // Reverted to original subquery
        "(SELECT GROUP_CONCAT(aal.action_type SEPARATOR ',') FROM admin_actions_log aal WHERE aal.target_object_id = l.id AND aal.status = 'pending' AND (aal.action_type = 'edit_loan_details' OR aal.action_type = 'request_loan_deletion')) as pending_actions " .
        "FROM loans l WHERE l.user_id = ? ORDER BY l.request_date DESC"
    );
    if ($stmt_loans) {
        $stmt_loans->bind_param("i", $user_id_to_edit);
        $stmt_loans->execute();
        $result_loans = $stmt_loans->get_result();
        while ($row_loan = $result_loans->fetch_assoc()) {
            $user_loans[] = $row_loan;
        }
        $stmt_loans->close();
    } else {
        // Optionally add feedback if loans can't be fetched, but primary focus is user edit.
    }
}

// --- Available roles ---
// Consider fetching this from DB or a config if roles become more dynamic
$available_roles = ['user', 'admin', 'super_admin'];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_details) {
    // Sanitize and retrieve form data
    $new_username = sanitize_input($_POST['username']);
    $new_email = sanitize_input($_POST['email']);
    $new_full_name = sanitize_input($_POST['full_name']);
    $new_role = isset($_POST['role']) ? sanitize_input($_POST['role']) : null; // For editing admin roles

    // Credit score is not applicable when editing admins/super_admins directly by a super_admin
    $new_credit_score = null;
    if (!$editing_an_admin_or_super_admin || $current_admin_s_role !== 'super_admin') {
         // Only process credit score if not editing an admin by a super admin, or if it's a regular user edit
        $new_credit_score = isset($_POST['credit_score']) && $_POST['credit_score'] !== '' ? (int)$_POST['credit_score'] : null;
    }

    $proposed_changes = [];
    $current_values = [];

    // Compare and collect changes
    if ($new_username !== $user_details['username']) {
        $proposed_changes['username'] = $new_username;
        $current_values['username'] = $user_details['username'];
    }
    if ($new_email !== $user_details['email']) {
        $proposed_changes['email'] = $new_email;
        $current_values['email'] = $user_details['email'];
    }
    if ($new_full_name !== $user_details['full_name']) {
        $proposed_changes['full_name'] = $new_full_name;
        $current_values['full_name'] = $user_details['full_name'];
    }
    if ($new_credit_score !== null && $new_credit_score != $user_details['credit_score']) { // != because one can be null
        $proposed_changes['credit_score'] = $new_credit_score;
        $current_values['credit_score'] = $user_details['credit_score'];
    }
    
    // Handle role change if super_admin is editing an admin/super_admin
    if ($editing_an_admin_or_super_admin && $current_admin_s_role === 'super_admin' && $new_role && $new_role !== $user_details['role']) {
        if (in_array($new_role, ['admin', 'super_admin'])) { // Ensure valid role change
            $proposed_changes['role'] = $new_role;
            $current_values['role'] = $user_details['role'];
        } else {
            $feedback_message = "Invalid role selected for admin/super admin.";
            $feedback_type = "error";
        }
    }
    
    $admin_reason = isset($_POST['admin_reason']) ? sanitize_input($_POST['admin_reason']) : '';


    if (empty($feedback_message) && !empty($proposed_changes)) { 
        if ($current_admin_s_role === 'super_admin') {
            if ($editing_an_admin_or_super_admin) {
                // Super Admin editing another Admin/Super Admin: Apply ALL changes directly
                $update_fields = []; $update_params = []; $types = "";
                foreach ($proposed_changes as $field => $value) {
                    $update_fields[] = "`{$field}` = ?"; $update_params[] = $value;
                    if (is_int($value)) $types .= "i"; elseif (is_double($value)) $types .= "d"; else $types .= "s";
                }
                $types .= "i"; $update_params[] = $user_id_to_edit;
                $sql_direct_update = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                $stmt_direct_update = $conn->prepare($sql_direct_update);
                if ($stmt_direct_update) {
                    $stmt_direct_update->bind_param($types, ...$update_params);
                    if ($stmt_direct_update->execute()) {
                        $feedback_message = "User details updated successfully by Super Admin.";
                        $feedback_type = "success";
                        // Refresh user_details to show updated values
                        $user_details = array_merge($user_details, $proposed_changes);
                    } else {
                        $feedback_message = "Error updating user details: " . $stmt_direct_update->error;
                        $feedback_type = "error";
                    }
                    $stmt_direct_update->close();
                } else {
                    $feedback_message = "Error preparing user update: " . $conn->error;
                    $feedback_type = "error";
                }
            } else { // Super Admin editing a REGULAR USER
                $direct_changes = [];
                $logged_changes = [];
                $direct_current_values = []; // Not strictly needed for direct update, but good for consistency if we did log them
                $logged_current_values = [];

                foreach ($proposed_changes as $field => $value) {
                    if (in_array($field, ['credit_score'])) { // Add other sensitive fields like 'monthly_income' if they become editable here
                        $logged_changes[$field] = $value;
                        $logged_current_values[$field] = $current_values[$field];
                    } else {
                        $direct_changes[$field] = $value;
                        // $direct_current_values[$field] = $current_values[$field];
                    }
                }

                $direct_update_successful = true;
                $logged_action_successful = true;
                $feedback_messages = [];

                // 1. Apply direct changes if any
                if (!empty($direct_changes)) {
                    $update_fields = []; $update_params = []; $types = "";
                    foreach ($direct_changes as $field => $value) {
                        $update_fields[] = "`{$field}` = ?"; $update_params[] = $value;
                        if (is_int($value)) $types .= "i"; elseif (is_double($value)) $types .= "d"; else $types .= "s";
                    }
                    $types .= "i"; $update_params[] = $user_id_to_edit;
                    $sql_direct_update = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                    $stmt_direct_update = $conn->prepare($sql_direct_update);
                    if ($stmt_direct_update) {
                        $stmt_direct_update->bind_param($types, ...$update_params);
                        if ($stmt_direct_update->execute()) {
                            $feedback_messages[] = "Non-sensitive user details updated directly.";
                            $user_details = array_merge($user_details, $direct_changes); // Refresh details
                        } else {
                            $feedback_messages[] = "Error updating non-sensitive details: " . $stmt_direct_update->error;
                            $direct_update_successful = false;
                        }
                        $stmt_direct_update->close();
                    } else {
                        $feedback_messages[] = "Error preparing direct user update: " . $conn->error;
                        $direct_update_successful = false;
                    }
                }

                // 2. Log sensitive changes if any
                if (!empty($logged_changes)) {
                    $proposed_changes_json = json_encode($logged_changes);
                    $current_values_json = json_encode($logged_current_values);
                    // Super admin is making the change, but it's logged for audit. Reason might be optional or fixed.
                    $reason_for_sensitive_change = $admin_reason ?: "Super admin changing sensitive field(s)."; 

                    $stmt_log = $conn->prepare("INSERT INTO admin_actions_log (admin_user_id, target_user_id, action_type, proposed_changes, current_values, admin_reason, status) VALUES (?, ?, 'edit_user_details', ?, ?, ?, 'pending')");
                    if ($stmt_log) {
                        $stmt_log->bind_param("iisss", $current_admin_id, $user_id_to_edit, $proposed_changes_json, $current_values_json, $reason_for_sensitive_change);
                        if ($stmt_log->execute()) {
                            $feedback_messages[] = "Sensitive changes (e.g., credit score) submitted for logging/audit.";
                        } else {
                            $feedback_messages[] = "Error submitting sensitive changes for logging: " . $stmt_log->error;
                            $logged_action_successful = false;
                        }
                        $stmt_log->close();
                    } else {
                        $feedback_messages[] = "Error preparing to log sensitive changes: " . $conn->error;
                        $logged_action_successful = false;
                    }
                }
                
                $feedback_message = implode(" ", $feedback_messages);
                $feedback_type = ($direct_update_successful && $logged_action_successful) ? "success" : "error";
                if (empty($direct_changes) && empty($logged_changes) && empty($feedback_message)) { // Should be caught by initial !empty($proposed_changes)
                     $feedback_message = "No changes were specified."; $feedback_type = "info";
                }
            }
        } else if ($current_admin_s_role === 'admin' && !$editing_an_admin_or_super_admin) { // Regular admin editing a 'user'
            $proposed_changes_json = json_encode($proposed_changes);
            $current_values_json = json_encode($current_values);

            $stmt_log = $conn->prepare("INSERT INTO admin_actions_log (admin_user_id, target_user_id, action_type, proposed_changes, current_values, admin_reason, status) VALUES (?, ?, 'edit_user_details', ?, ?, ?, 'pending')");
            if ($stmt_log) {
                $stmt_log->bind_param("iisss", $current_admin_id, $user_id_to_edit, $proposed_changes_json, $current_values_json, $admin_reason);
                if ($stmt_log->execute()) {
                    $feedback_message = "Your proposed changes have been submitted for Super Admin approval.";
                    $feedback_type = "success";
                } else {
                    $feedback_message = "Error submitting changes for approval: " . $stmt_log->error;
                    $feedback_type = "error";
                }
                $stmt_log->close();
            } else {
                $feedback_message = "Error preparing to log changes: " . $conn->error;
                $feedback_type = "error";
            }
        }
    } elseif (empty($feedback_message) && empty($proposed_changes)) {
        $feedback_message = "No changes were detected.";
        $feedback_type = "info";
    }
}

?>

<style>
td.loan-actions {
    display: flex; /* Use flexbox for alignment */
    align-items: center; /* Vertically align items */
    gap: 5px; /* Space between flex items (Edit link and Delete form) */
    white-space: nowrap; /* Try to keep them on one line */
}

td.loan-actions .button-small,
td.loan-actions form button.button-small {
    padding: 3px 7px;
    font-size: 0.85em;
    line-height: 1.4;
    margin: 0; /* Remove default margins if they interfere with flex gap */
    display: inline-block; /* Keep this for button-like appearance */
}

td.loan-actions form {
    margin: 0; /* Remove default margins from form if flex gap is used */
    /* display: inline; is fine here as it becomes a flex item */
}
</style>

<h2>Edit User <?php echo $user_details ? 'Details for "' . htmlspecialchars($user_details['username']) . '" (ID: ' . $user_id_to_edit . ')' : ''; ?></h2>

<?php if ($feedback_message): ?>
    <p class="message <?php echo htmlspecialchars($feedback_type); ?>"><?php echo htmlspecialchars($feedback_message); ?></p>
<?php endif; ?>

<?php if ($user_details && empty($feedback_type) || $feedback_type !== 'error' || $feedback_message === "No changes were detected." || $feedback_message === "Your proposed changes have been submitted for Super Admin approval."): // Show form if user found or if success/info message ?>
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?user_id=' . $user_id_to_edit; ?>" method="post">
        <table class="form-table">
            <tr>
                <th><label for="username">Username:</label></th>
                <td><input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_details['username']); ?>" required></td>
            </tr>
            <tr>
                <th><label for="email">Email:</label></th>
                <td><input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_details['email']); ?>" required></td>
            </tr>
            <tr>
                <th><label for="full_name">Full Name:</label></th>
                <td><input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user_details['full_name']); ?>" required></td>
            </tr>
            <tr>
                <th><label for="credit_score">Credit Score:</label></th>
                <td><input type="number" id="credit_score" name="credit_score" value="<?php echo htmlspecialchars($user_details['credit_score'] ?? ''); ?>" min="300" max="850" placeholder="Leave blank to not change"></td>
            </tr>
            <?php if ($editing_an_admin_or_super_admin && $current_admin_s_role === 'super_admin' && $user_id_to_edit !== $current_admin_id): // Super admin editing another admin/super_admin (cannot edit own role here) ?>
            <tr>
                <th><label for="role">Role:</label></th>
                <td>
                    <select id="role" name="role">
                        <option value="admin" <?php echo ($user_details['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                        <option value="super_admin" <?php echo ($user_details['role'] === 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                    </select>
                </td>
            </tr>
            <?php endif; // End super admin editing admin role ?>
            <?php if ($current_admin_s_role !== 'super_admin' && !$editing_an_admin_or_super_admin ): // Show reason field for regular admins editing users, or super_admins editing users (not other admins) ?>
                 <tr>
                    <th><label for="admin_reason">Reason for Changes:</label></th>
                    <td><textarea id="admin_reason" name="admin_reason" rows="3" style="width:90%;" placeholder="Required if you are not a Super Admin"></textarea></td>
                </tr>
            <?php endif; ?>
            <tr>
                <th></th>
                <td><button type="submit" class="button">
                    <?php 
                    if ($current_admin_s_role === 'super_admin') {
                        if ($editing_an_admin_or_super_admin) {
                            echo 'Save Admin/Super Admin Details Directly';
                        } else {
                            echo 'Submit user details'; 
                        }
                    } else { // Must be a regular admin editing a user
                        echo 'Submit User Changes for Approval';
                    }
                    ?>
                </button></td>
            </tr>
        </table>
    </form>
<?php elseif (!$user_details && !$feedback_message): // Should not happen if logic is correct ?>
    <p class="error">User not found and no specific error message was set.</p>
<?php endif; ?>

<hr style="margin: 30px 0;">

<?php if (!$editing_an_admin_or_super_admin): // Only show loan records for 'user' role users ?>
<h3>Loan Records for <?php echo $user_details ? htmlspecialchars($user_details['username']) : 'this User'; ?></h3>
<?php if (!empty($user_loans)): ?>
    <table border="1" style="width:100%; border-collapse: collapse; margin-top: 10px;">
        <thead>
            <tr>
                <th>Loan ID</th>
                <th>Amount Req.</th>
                <th>Amount Appr.</th>
                <th>Term</th>
                <th>Status</th>
                <th>Remaining Bal.</th>
                <th>Next Due Date</th>
                <th>Requested On</th>
                <th>End Term Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($user_loans as $loan): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loan['id']); ?></td>
                    <td>₱<?php echo htmlspecialchars(number_format($loan['amount_requested'], 2)); ?></td>
                    <td><?php echo $loan['amount_approved'] ? '₱' . htmlspecialchars(number_format($loan['amount_approved'], 2)) : 'N/A'; ?></td>
                    <td><?php echo htmlspecialchars($loan['term_months']); ?> months</td>
                    <td style="text-transform: capitalize;">
                        <?php 
                        // DEBUGGING PENDING ACTIONS
                        if ($loan['id'] == 12) { // Specifically for loan_id 12 for targeted debugging
                            echo "<!-- DEBUG FOR LOAN 12 (original query): pending_actions raw value: ";
                            var_dump($loan['pending_actions']);
                            echo " -->";
                        }
                        // END DEBUGGING
                        echo !empty($loan['status']) ? htmlspecialchars($loan['status']) : 'N/A'; 
                        if (!empty($loan['pending_actions'])) {
                            $actions = explode(',', $loan['pending_actions']);
                            $display_actions = [];
                            if (in_array('edit_loan_details', $actions)) $display_actions[] = 'Pending Edit';
                            if (in_array('request_loan_deletion', $actions)) $display_actions[] = 'Pending Deletion';
                            if (!empty($display_actions)) {
                                echo ' <small style="color: orange; font-weight: bold;">(' . implode(', ', $display_actions) . ')</small>';
                            }
                        }
                        ?>
                    </td>
                    <td><?php echo $loan['remaining_balance'] !== null ? '₱' . htmlspecialchars(number_format($loan['remaining_balance'], 2)) : ($loan['status'] === 'paid' || $loan['status'] === 'rejected' || $loan['status'] === 'cancelled_by_admin' ? 'N/A' : 'Calculating...'); ?></td>
                    <td><?php echo $loan['next_payment_due_date'] ? htmlspecialchars(date('Y-m-d', strtotime($loan['next_payment_due_date']))) : 'N/A'; ?></td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($loan['loan_requested_date']))); ?></td>
                    <td><?php echo $loan['loan_end_term_date'] ? htmlspecialchars(date('Y-m-d', strtotime($loan['loan_end_term_date']))) : 'N/A'; ?></td>
                    <td class="loan-actions">
                        <a href="edit_loan_by_admin.php?loan_id=<?php echo $loan['id']; ?>&user_id=<?php echo $user_id_to_edit; ?>" class="button-small">Edit Loan</a>
                        <?php if ($loan['status'] !== 'cancelled_by_admin'): // Don't show cancel for already cancelled ?>
                        <form action="admin_request_loan_deletion.php" method="post" style="display:inline; margin-left: 5px;" onsubmit="return confirm('Are you sure you want to request cancellation for loan ID <?php echo $loan['id']; ?>? This will require Super Admin approval.');">
                            <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $user_id_to_edit; ?>">
                            <input type="hidden" name="action_log_type" value="request_loan_deletion">
                            <input type="hidden" name="redirect_url_base" value="<?php echo htmlspecialchars(BASE_URL . 'admin/edit_user.php?user_id=' . $user_id_to_edit); ?>">
                            <button type="submit" class="button-small danger">Request Cancellation</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No loan records found for this user.</p>
<?php endif; ?>
<?php endif; // End if not editing an admin/super_admin for loan records ?>


<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/manage_users.php">« Back to Manage Users</a></p>
<p style="margin-top: 10px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?> 