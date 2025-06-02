<?php
require_once __DIR__ . '/includes/header.php'; // For DB, functions, session

// Authenticate: User must be logged in. Admin can also view.
if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}

if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    echo "<p class='error'>Invalid Loan ID.</p>";
    require_once __DIR__ . '/includes/footer.php';
    exit();
}

$loan_id = intval($_GET['loan_id']);
$current_user_id = $_SESSION['user_id'];
$is_current_user_admin = is_admin();

$loan = null;
$user = null;

// Fetch loan details
// User can only see their own loan receipts, admin can see any
$sql = "SELECT l.*, u.full_name AS user_full_name, u.username AS user_username, u.email AS user_email
        FROM loans l
        JOIN users u ON l.user_id = u.id
        WHERE l.id = ?";
if (!$is_current_user_admin) {
    $sql .= " AND l.user_id = ?"; // Non-admin can only see their own
}

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!$is_current_user_admin) {
        $stmt->bind_param("ii", $loan_id, $current_user_id);
    } else {
        $stmt->bind_param("i", $loan_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $loan = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$loan) {
    echo "<p class='error'>Loan not found or you do not have permission to view this receipt.</p>";
    // No footer here if header wasn't fully rendered for this specific error page.
    // If header.php is always fully rendered before this check, then include footer.
    // For simplicity, let's assume header was rendered.
    require_once __DIR__ . '/includes/footer.php';
    exit();
}

// Calculate EMI for display (if applicable)
$monthly_payment_display = 'N/A';
if (($loan['status'] === 'approved' || $loan['status'] === 'active' || $loan['status'] === 'paid_off') && $loan['amount_approved'] > 0) {
    $monthly_payment_display = calculate_emi_from_monthly_rate(
        $loan['amount_approved'],
        $loan['interest_rate_monthly'],
        $loan['term_months']
    );
}

// For a simple payment schedule (assuming EMI)
$payment_schedule = [];
if (is_numeric($monthly_payment_display) && $loan['start_date'] && $loan['status'] !== 'pending' && $loan['status'] !== 'rejected') {
    $current_due_date = new DateTime($loan['start_date']);
    // First payment is due 1 month after start date
    $current_due_date->add(new DateInterval('P1M'));

    for ($i = 1; $i <= $loan['term_months']; $i++) {
        $payment_schedule[] = [
            'installment' => $i,
            'due_date' => $current_due_date->format('Y-m-d'),
            'amount' => $monthly_payment_display
        ];
        $current_due_date->add(new DateInterval('P1M'));
    }
    // Adjust final payment if total repayment doesn't exactly match sum of EMIs due to rounding
    if (!empty($payment_schedule) && $loan['total_repayment_amount']) {
        $total_emi_sum = $monthly_payment_display * $loan['term_months'];
        if (abs($total_emi_sum - $loan['total_repayment_amount']) > 0.01) { // If there's a difference
            $last_payment_index = count($payment_schedule) - 1;
            $payment_schedule[$last_payment_index]['amount'] = round($loan['total_repayment_amount'] - ($monthly_payment_display * ($loan['term_months'] -1)),2) ;
        }
    }


}

?>
<style>
    .receipt-container { max-width: 800px; margin: 20px auto; padding: 20px; border: 1px solid #ccc; font-family: Arial, sans-serif; }
    .receipt-header { text-align: center; margin-bottom: 20px; }
    .receipt-header h1 { margin: 0; }
    .receipt-details, .user-details, .loan-terms { margin-bottom: 15px; }
    .receipt-details p, .user-details p, .loan-terms p { margin: 5px 0; }
    .receipt-details strong, .user-details strong, .loan-terms strong { min-width: 150px; display: inline-block; }
    .payment-schedule table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .payment-schedule th, .payment-schedule td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .print-button-container { text-align: center; margin-top: 30px; }
    @media print {
        body * { visibility: hidden; }
        .receipt-container, .receipt-container * { visibility: visible; }
        .receipt-container { position: absolute; left: 0; top: 0; width: 100%; margin: 0; padding: 0; border: none; }
        .print-button-container { display: none; }
        /* Ensure header/footer from main template are hidden */
        body > header, body > footer, body > .container > div:first-child /* To hide simulated time display */ { display: none !important; }
        .main-content-area { padding-top: 0 !important; } /* Adjust if you added padding */
    }
</style>

<div class="receipt-container">
    <div class="receipt-header">
        <h1>Loan Agreement Summary / Receipt</h1>
        <p>Loan ID: <?php echo htmlspecialchars($loan['id']); ?></p>
    </div>

    <div class="user-details">
        <h3>Borrower Information</h3>
        <p><strong>Full Name:</strong> <?php echo htmlspecialchars($loan['user_full_name']); ?></p>
        <p><strong>Username:</strong> <?php echo htmlspecialchars($loan['user_username']); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($loan['user_email']); ?></p>
    </div>

    <div class="loan-terms">
        <h3>Loan Terms</h3>
        <p><strong>Loan Status:</strong> <span style="text-transform:capitalize;"><?php echo htmlspecialchars($loan['status']); ?></span></p>
        <p><strong>Amount Approved:</strong> ₱<?php echo htmlspecialchars(number_format($loan['amount_approved'], 2)); ?></p>
        <p><strong>Loan Term:</strong> <?php echo htmlspecialchars($loan['term_months']); ?> months</p>
        <p><strong>Monthly Interest Rate:</strong> <?php echo htmlspecialchars(number_format($loan['interest_rate_monthly'] * 100, 2)); ?>%</p>
        <p><strong>Calculated Monthly Payment (EMI):</strong> ₱<?php echo htmlspecialchars(number_format($monthly_payment_display, 2)); ?></p>
        <p><strong>Total Repayment Amount:</strong> ₱<?php echo htmlspecialchars(number_format($loan['total_repayment_amount'], 2)); ?></p>
        <p><strong>Loan Start Date:</strong> <?php echo $loan['start_date'] ? htmlspecialchars($loan['start_date']) : 'N/A'; ?></p>
        <p><strong>Request Date:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($loan['request_date']))); ?></p>
        <p><strong>Approval Date:</strong> <?php echo $loan['approval_date'] ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($loan['approval_date']))) : 'N/A'; ?></p>
    </div>

    <?php if (!empty($payment_schedule)): ?>
    <div class="payment-schedule">
        <h3>Indicative Payment Schedule</h3>
        <table>
            <thead>
                <tr>
                    <th>Installment #</th>
                    <th>Due Date</th>
                    <th>Amount Due</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_schedule as $payment): ?>
                <tr>
                    <td><?php echo $payment['installment']; ?></td>
                    <td><?php echo $payment['due_date']; ?></td>
                    <td>₱<?php echo number_format($payment['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="print-button-container">
        <button onclick="window.print();">Print Receipt</button>
    </div>
    <p style="text-align:center; margin-top:20px; font-size:0.8em;">
        This is a summary. Actual payments made can be viewed in your loan history.
    </p>
</div>

<?php
// Note: We don't include the standard site footer here to keep the receipt clean for printing.
// The </ D I V> for .container and </ B O D Y> </ H T M L> are closed by the standard footer
// which we are avoiding. If header.php was fully rendered, we need to close its tags.
// However, the @media print CSS should hide the standard footer.
// For a cleaner non-print view of just the receipt, you might close body/html here
// and ensure header.php doesn't output its own footer if a certain flag is set.
// For now, relying on @media print to hide standard footer.
?>