<?php
require_once __DIR__ . '/includes/header.php';

// Check if user is logged in, otherwise redirect to login page
if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';
$loan_amount_input = ''; // For repopulating form
$loan_purpose = '';    // For repopulating form
$selected_term = '';   // For repopulating form

// --- Fetch User's Current Credit Score ---
$current_credit_score = 500; // Default fallback
$stmt_score = $conn->prepare("SELECT credit_score FROM users WHERE id = ?");
if ($stmt_score) {
    $stmt_score->bind_param("i", $user_id);
    $stmt_score->execute();
    $result_score = $stmt_score->get_result();
    if ($user_data = $result_score->fetch_assoc()) {
        $current_credit_score = $user_data['credit_score'];
    }
    $stmt_score->close();
}

// --- Define Loan Options Based on Credit Score ---
$max_loan_amount = 200; // Default for very poor
$available_terms = [3]; // Default term
$user_rating = "Very Poor";

if ($current_credit_score < 450) {
    $max_loan_amount = 200;
    $available_terms = [3];
    $user_rating = "Very Poor";
} elseif ($current_credit_score <= 549) {
    $max_loan_amount = 500;
    $available_terms = [3];
    $user_rating = "Poor";
} elseif ($current_credit_score <= 649) {
    $max_loan_amount = 2000;
    $available_terms = [3, 6];
    $user_rating = "Fair";
} elseif ($current_credit_score <= 749) {
    $max_loan_amount = 5000;
    $available_terms = [3, 6, 9, 12];
    $user_rating = "Good";
} else { // >= 750
    $max_loan_amount = 10000;
    $available_terms = [3, 6, 9, 12, 18, 24];
    $user_rating = "Excellent";
}

$calculated_dynamic_rate_display = "N/A (Select amount and term)"; // Placeholder for display
// --- Check for Delinquency (Simple Check for now) ---
$is_delinquent = false;
// Example: if user has any loan with status 'defaulted' or more than 2 penalties on active loans (more complex)
$stmt_delinquency = $conn->prepare("SELECT COUNT(*) as defaulted_count FROM loans WHERE user_id = ? AND status = 'defaulted'");
if($stmt_delinquency) {
    $stmt_delinquency->bind_param("i", $user_id);
    $stmt_delinquency->execute();
    $res_del = $stmt_delinquency->get_result()->fetch_assoc();
    if ($res_del['defaulted_count'] > 0) {
        $is_delinquent = true;
        $max_loan_amount = 0; // Prevent new loans if defaulted
        $errors['delinquency'] = "You have a defaulted loan. New loan requests are currently restricted.";
    }
    $stmt_delinquency->close();
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && !$is_delinquent) {
    $loan_amount_input = sanitize_input($_POST['loan_amount']);
    $loan_purpose = sanitize_input($_POST['loan_purpose']);
    $selected_term = isset($_POST['loan_term']) ? intval($_POST['loan_term']) : null;
    $loan_amount_for_calc = 0; // Initialize
    $loan_amount_for_db = 0;   // Initialize

    // Validate Loan Amount
    if (empty($loan_amount_input)) {
        $errors['loan_amount'] = "Loan amount is required.";
    } elseif (!is_numeric($loan_amount_input)) {
        $errors['loan_amount'] = "Loan amount must be a number.";
    } elseif ($loan_amount_input <= 0) {
        $errors['loan_amount'] = "Loan amount must be greater than zero.";
    } elseif ($loan_amount_input > $max_loan_amount) {
        $errors['loan_amount'] = "Based on your credit score, the maximum loan amount you can currently request is $" . number_format($max_loan_amount, 2) . ".";
    } else {
        $loan_amount_for_db = floatval($loan_amount_input);
        $loan_amount_for_calc = $loan_amount_for_db; // <<< --- ASSIGN TO _for_calc HERE ---
    }

    // Validate Loan Term
    if (empty($selected_term)) {
        $errors['loan_term'] = "Loan term is required.";
    } elseif (!in_array($selected_term, $available_terms)) {
        $errors['loan_term'] = "Invalid loan term selected for your credit profile.";
    }

    $dynamic_interest_rate_for_db = null;
    // Calculate dynamic interest rate IF there are no validation errors for amount/term yet AND inputs are valid
    if (empty($errors['loan_amount']) && empty($errors['loan_term']) && $loan_amount_for_calc > 0 && $selected_term > 0) {
        $dynamic_interest_rate_for_db = calculate_dynamic_monthly_interest_rate(
            $current_credit_score,
            $loan_amount_for_calc,
            $selected_term
        );
        $calculated_dynamic_rate_display = number_format($dynamic_interest_rate_for_db * 100, 2) . "%";
    } elseif ($loan_amount_for_calc <= 0 || $selected_term <= 0) {
        // If amount/term are not valid, don't attempt to calculate, keep display as N/A
        $calculated_dynamic_rate_display = "N/A (Select amount and term)";
    }


    if (empty($errors)) { // If NO errors at all (including amount, term, and others if any)
        if ($dynamic_interest_rate_for_db === null) {
            // This case should be rare now if previous block ran, but as a safeguard
            $errors['general'] = "Could not calculate interest rate. Please ensure amount and term are valid.";
        } else {
            // ... (Your INSERT INTO loans logic - this looks okay) ...
            $stmt = $conn->prepare("INSERT INTO loans (user_id, amount_requested, interest_rate_monthly, term_months, purpose, request_date) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("iddis", $user_id, $loan_amount_for_db, $dynamic_interest_rate_for_db, $selected_term, $loan_purpose);
                if ($stmt->execute()) {
                    $success_message = "Your loan request has been submitted successfully with a calculated monthly rate of " . number_format($dynamic_interest_rate_for_db * 100, 2) . "%.";
                    $loan_amount_input = ''; $loan_purpose = ''; $selected_term = '';
                    $calculated_dynamic_rate_display = "N/A (Select amount and term)";
                } else {
                    $errors['general'] = "Loan request failed. Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $errors['general'] = "Database statement preparation failed: " . $conn->error;
            }
        }
    }
    // No 'else' here for repopulating $calculated_dynamic_rate_display if errors exist,
    // as it's already populated above based on whether amount/term were valid enough for calculation.

} elseif ($_SERVER["REQUEST_METHOD"] !== "POST" && !$is_delinquent) {
    // Initial page load, display default "N/A"
    $calculated_dynamic_rate_display = "N/A (Select amount and term)";
}
?>

<h2>Request a New Loan</h2>
<p>Your current credit score: <strong><?php echo $current_credit_score; ?> (<?php echo $user_rating; ?>)</strong></p>
<?php // ... (delinquency message, success/error messages) ... ?>

<form id="loanRequestForm" action="<?php echo htmlspecialchars(BASE_URL . 'request_loan.php'); ?>" method="post" onsubmit="return confirm('Are you sure you want to submit this loan request?');">
    <div>
        <label for="loan_amount">Loan Amount (Max: $<?php echo number_format($max_loan_amount, 2); ?>):</label>
        <input type="number" step="50" id="loan_amount" name="loan_amount" value="<?php echo htmlspecialchars($loan_amount_input); ?>" required min="100">
        <?php // ... error display ... ?>
    </div>
    <div>
        <label for="loan_term">Loan Term:</label>
        <select id="loan_term" name="loan_term" required>
            <option value="">-- Select Term --</option>
            <?php foreach ($available_terms as $term): ?>
                <option value="<?php echo $term; ?>" <?php echo ($selected_term == $term) ? 'selected' : ''; ?>>
                    <?php echo $term; ?> Months
                </option>
            <?php endforeach; ?>
        </select>
        <?php // ... error display ... ?>
    </div>
    <div>
        <label for="calculated_interest_rate">Calculated Monthly Interest Rate:</label>
        <input type="text" id="calculated_interest_rate" name="calculated_interest_rate_display" value="<?php echo htmlspecialchars($calculated_dynamic_rate_display); ?>" readonly style="font-weight:bold;">
        <small>This rate is calculated based on your credit score, loan amount, and term.</small>
    </div>
    <div>
        <label for="loan_purpose">Purpose of Loan (Optional):</label>
        <textarea id="loan_purpose" name="loan_purpose" rows="3"><?php echo htmlspecialchars($loan_purpose); ?></textarea>
    </div>
    <button type="submit">Submit Loan Request</button>
</form>

<!-- Optional: JavaScript to update rate display dynamically on input change (more advanced) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('loan_amount');
    const termSelect = document.getElementById('loan_term');
    const rateDisplay = document.getElementById('calculated_interest_rate');
    const creditScore = <?php echo $current_credit_score; ?>; // Pass PHP var to JS

    function updateRateDisplay() {
        const amount = parseFloat(amountInput.value);
        const term = parseInt(termSelect.value);

        if (amount > 0 && term > 0 && !isNaN(creditScore)) {
            // This would ideally be an AJAX call to a PHP script that runs
            // calculate_dynamic_monthly_interest_rate() to keep logic server-side.
            // For a pure JS implementation, you'd replicate the logic, which isn't ideal for maintenance.
            // Placeholder for now:
            // rateDisplay.value = "Calculating...";
            
            // Example AJAX call structure:
            // fetch(BASE_URL + 'calculate_rate_ajax.php?amount=' + amount + '&term=' + term + '&score=' + creditScore)
            // .then(response => response.json())
            // .then(data => {
            //     if(data.success) rateDisplay.value = data.rate_display + "%";
            //     else rateDisplay.value = "Error calculating";
            // });
            rateDisplay.value = "Live update via JS/AJAX is complex (see code comments)"; // Simple placeholder
        } else {
            rateDisplay.value = "N/A (Select amount and term)";
        }
    }

    // To enable live updates, uncomment these lines:
    // amountInput.addEventListener('input', updateRateDisplay);
    // termSelect.addEventListener('change', updateRateDisplay);

    // Initial call if values are pre-filled (e.g. on form error re-population)
    // This part is tricky because the PHP value is already set.
    // A better approach for pre-fill would be to have PHP output the rate if inputs are valid,
    // and JS only tries to update on user interaction.
    // if (amountInput.value && termSelect.value && rateDisplay.value.startsWith("N/A")) { 
    //    updateRateDisplay(); 
    // }
});
</script>

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>dashboard.php">« Back to Dashboard</a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>