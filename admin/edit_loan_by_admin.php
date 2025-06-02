<?php
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$loan_id_to_edit = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : 0;
$user_id_of_loan = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0; // For context and back links
$current_admin_id = $_SESSION['user_id'];

$feedback_message = '';
$feedback_type = '';
$loan_details = null;
$user_username = '';

// Available loan statuses (ensure this list is comprehensive for your needs)
$available_loan_statuses = ['pending', 'approved', 'active', 'paid', 'rejected', 'defaulted', 'cancelled_by_admin', 'settled_default', 'paid_post_default'];

if ($loan_id_to_edit > 0) {
    $stmt_loan = $conn->prepare(
        "SELECT l.*, u.username as user_username FROM loans l JOIN users u ON l.user_id = u.id WHERE l.id = ? AND l.user_id = ?"
    );
    if ($stmt_loan) {
        $stmt_loan->bind_param("ii", $loan_id_to_edit, $user_id_of_loan);
        $stmt_loan->execute();
        $result_loan = $stmt_loan->get_result();
        if ($result_loan->num_rows === 1) {
            $loan_details = $result_loan->fetch_assoc();
            $user_username = $loan_details['user_username'];
        } else {
            $feedback_message = "Loan not found or does not belong to the specified user.";
            $feedback_type = "error";
        }
        $stmt_loan->close();
    } else {
        $feedback_message = "Error preparing to fetch loan: " . $conn->error;
        $feedback_type = "error";
    }
} else {
    $feedback_message = "No Loan ID provided.";
    $feedback_type = "error";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loan_details) {
    // Sanitize and retrieve form data
    $new_values = [
        'amount_requested' => isset($_POST['amount_requested']) ? filter_var($_POST['amount_requested'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null,
        'amount_approved' => isset($_POST['amount_approved']) ? filter_var($_POST['amount_approved'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null,
        'term_months' => isset($_POST['term_months']) ? (int)$_POST['term_months'] : null,
        'status' => isset($_POST['status']) && in_array($_POST['status'], $available_loan_statuses) ? sanitize_input($_POST['status']) : null,
        'remaining_balance' => isset($_POST['remaining_balance']) ? filter_var($_POST['remaining_balance'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null,
        'next_payment_due_date' => isset($_POST['next_payment_due_date']) && !empty($_POST['next_payment_due_date']) ? sanitize_input($_POST['next_payment_due_date']) : null,
        'loan_end_term_date' => isset($_POST['loan_end_term_date']) && !empty($_POST['loan_end_term_date']) ? sanitize_input($_POST['loan_end_term_date']) : null,
    ];
    $settlement_amount_input = isset($_POST['settlement_amount']) ? filter_var($_POST['settlement_amount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null;

    $proposed_changes = [];
    $current_values = [];

    foreach ($new_values as $field => $new_value) {
        // Handle type consistency for comparison, especially for numeric fields that might be empty strings from form
        $current_field_value = $loan_details[$field];
        $is_numeric_field = in_array($field, ['amount_requested', 'amount_approved', 'term_months', 'remaining_balance']);
        
        if ($is_numeric_field) {
            $new_value = ($new_value === null || $new_value === '') ? null : (float)$new_value;
            $current_field_value = ($current_field_value === null || $current_field_value === '') ? null : (float)$current_field_value;
        }
        // For date fields, ensure they are in YYYY-MM-DD or null for comparison
        if (in_array($field, ['next_payment_due_date', 'loan_end_term_date'])) {
            $new_value = $new_value ? date('Y-m-d', strtotime($new_value)) : null;
            $current_field_value = $current_field_value ? date('Y-m-d', strtotime($current_field_value)) : null;
        }

        if ($new_value !== $current_field_value) {
            $proposed_changes[$field] = $new_value;
            $current_values[$field] = $loan_details[$field]; // Store original format for dates if different
        }
    }
    
    $admin_reason = isset($_POST['admin_reason']) ? sanitize_input($_POST['admin_reason']) : '';

    // Handling for settling defaulted loans
    if ($loan_details['status'] === 'defaulted' && 
        ($new_values['status'] === 'settled_default' || $new_values['status'] === 'paid_post_default')) {
        
        if ($settlement_amount_input !== null && $settlement_amount_input > 0) {
            $proposed_changes['settlement_payment_recorded'] = (float)$settlement_amount_input;
            // When approved, remaining_balance will be set to 0 after this payment is accounted for.
            // For logging purposes, we might log the intended final balance.
            $proposed_changes['remaining_balance'] = 0.00; 
            // Ensure current value of remaining_balance is logged if it wasn't already part of another change
            if (!isset($current_values['remaining_balance'])) {
                 $current_values['remaining_balance'] = $loan_details['remaining_balance'];
            }
        } elseif ($new_values['status'] !== $loan_details['status']) { // Status changed to settled but no payment amount given
            // Log that remaining balance is intended to be zeroed out
            $proposed_changes['remaining_balance'] = 0.00;
             if (!isset($current_values['remaining_balance'])) {
                 $current_values['remaining_balance'] = $loan_details['remaining_balance'];
            }
            if(empty($admin_reason)) {
                 $feedback_message = "A reason is required when marking a defaulted loan as settled without recording a new payment amount.";
                 $feedback_type = "error";
            }
        }
    }

    if (!empty($proposed_changes) && empty($feedback_message)) { // feedback_message check added
        if (empty($admin_reason) && !is_super_admin()) {
            $proposed_changes_json = json_encode($proposed_changes);
            $current_values_json = json_encode($current_values);

            // Super Admins can edit directly (for now, let's assume all loan edits go through approval for consistency, can change later)
            // if (is_super_admin()) { ... direct update ... } else { ... log for approval ... }
            
            $stmt_log = $conn->prepare(
                "INSERT INTO admin_actions_log (admin_user_id, target_user_id, target_object_id, action_type, proposed_changes, current_values, admin_reason, status) " .
                "VALUES (?, ?, ?, 'edit_loan_details', ?, ?, ?, 'pending')"
            );
            if ($stmt_log) {
                $stmt_log->bind_param("iiisss", $current_admin_id, $user_id_of_loan, $loan_id_to_edit, $proposed_changes_json, $current_values_json, $admin_reason);
                if ($stmt_log->execute()) {
                    $feedback_message = "Your proposed changes for loan ID {$loan_id_to_edit} have been submitted for Super Admin approval.";
                    $feedback_type = "success";
                    // Optionally, clear form or refresh loan_details if desired after successful submission
                } else {
                    $feedback_message = "Error submitting loan changes for approval: " . $stmt_log->error;
                    $feedback_type = "error";
                }
                $stmt_log->close();
            } else {
                $feedback_message = "Error preparing to log loan changes: " . $conn->error;
                $feedback_type = "error";
            }
        }
    } else {
        $feedback_message = "No changes were detected for the loan.";
        $feedback_type = "info";
    }
}

?>

<h2>Propose Edits for Loan ID: <?php echo $loan_id_to_edit; ?> (User: <?php echo htmlspecialchars($user_username); ?>)</h2>

<?php if ($feedback_message): ?>
    <p class="message <?php echo htmlspecialchars($feedback_type); ?>"><?php echo htmlspecialchars($feedback_message); ?></p>
<?php endif; ?>

<?php if ($loan_details && (empty($feedback_type) || $feedback_type === 'info' || $feedback_type === 'success')): // Show form if loan found and no critical error ?>
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?loan_id=' . $loan_id_to_edit . '&user_id=' . $user_id_of_loan; ?>" method="post">
        <table class="form-table">
            <tr>
                <th><label for="amount_requested">Amount Requested:</label></th>
                <td><input type="number" step="0.01" id="amount_requested" name="amount_requested" value="<?php echo htmlspecialchars($loan_details['amount_requested'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="amount_approved">Amount Approved:</label></th>
                <td><input type="number" step="0.01" id="amount_approved" name="amount_approved" value="<?php echo htmlspecialchars($loan_details['amount_approved'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="term_months">Term (Months):</label></th>
                <td><input type="number" id="term_months" name="term_months" value="<?php echo htmlspecialchars($loan_details['term_months'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="status">Status:</label></th>
                <td>
                    <select id="status" name="status">
                        <?php foreach ($available_loan_statuses as $status_option): ?>
                            <option value="<?php echo htmlspecialchars($status_option); ?>" <?php echo ($loan_details['status'] === $status_option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status_option))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="remaining_balance">Remaining Balance:</label></th>
                <td><input type="number" step="0.01" id="remaining_balance" name="remaining_balance" value="<?php echo htmlspecialchars($loan_details['remaining_balance'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="next_payment_due_date">Next Payment Due Date:</label></th>
                <td><input type="date" id="next_payment_due_date" name="next_payment_due_date" value="<?php echo $loan_details['next_payment_due_date'] ? htmlspecialchars(date('Y-m-d', strtotime($loan_details['next_payment_due_date']))) : ''; ?>"></td>
            </tr>
            <tr>
                <th><label for="loan_end_term_date">Loan End Term Date:</label></th>
                <td><input type="date" id="loan_end_term_date" name="loan_end_term_date" value="<?php echo $loan_details['loan_end_term_date'] ? htmlspecialchars(date('Y-m-d', strtotime($loan_details['loan_end_term_date']))) : ''; ?>"></td>
            </tr>

            <?php // Field for settlement payment if loan is defaulted ?>
            <?php if ($loan_details && $loan_details['status'] === 'defaulted'): ?>
            <tr id="settlement_payment_row" style="display: none; <?php /* JavaScript will show this if status changes to settled */ ?>">
                <th><label for="settlement_amount">Record Final Payment / Settlement Amount:</label></th>
                <td>
                    <input type="number" step="0.01" id="settlement_amount" name="settlement_amount" value="" placeholder="Enter amount if applicable">
                    <small style="display:block; margin-top:5px;">Enter the amount paid by the user to settle this defaulted loan. Remaining balance will be set to ₱0.</small>
                </td>
            </tr>
            <?php endif; ?>

             <tr>
                <th><label for="admin_reason">Reason for Changes:</label></th>
                <td><textarea id="admin_reason" name="admin_reason" rows="3" style="width:90%;" placeholder="Required for proposing changes" required></textarea></td>
            </tr>
            <tr>
                <th></th>
                <td><button type="submit" class="button">Submit Proposed Loan Changes</button></td>
            </tr>
        </table>
    </form>
<?php elseif (!$loan_details && !$feedback_message): ?>
    <p class="error">Loan details not found.</p>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const statusSelect = document.getElementById('status');
    const settlementPaymentRow = document.getElementById('settlement_payment_row');
    const loanStatusCurrently = '<?php echo $loan_details["status"] ?? ""; ?>';

    function toggleSettlementField() {
        if (settlementPaymentRow) {
            if (loanStatusCurrently === 'defaulted' && 
                (statusSelect.value === 'settled_default' || statusSelect.value === 'paid_post_default')) {
                settlementPaymentRow.style.display = 'table-row';
            } else {
                settlementPaymentRow.style.display = 'none';
            }
        }
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', toggleSettlementField);
        // Initial check in case the form is reloaded with a new status selected by admin but not yet submitted
        toggleSettlementField(); 
    }
});
</script>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>admin/edit_user.php?user_id=<?php echo $user_id_of_loan; ?>">« Back to User Details (<?php echo htmlspecialchars($user_username); ?>)</a></p>
<p style="margin-top: 10px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?> 