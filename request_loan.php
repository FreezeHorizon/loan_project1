<?php
require_once __DIR__ . '/includes/header.php'; // Includes functions.php for calculate_dynamic_monthly_interest_rate

// Check if user is logged in, otherwise redirect to login page
if (!is_logged_in()) {
    redirect(BASE_URL . 'login.php');
}

// Prevent admins from requesting loans
if (is_admin()) {
    $_SESSION['admin_message'] = "Administrators cannot request loans.";
    redirect(BASE_URL . 'admin/index.php');
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

// --- Define Loan Options Based on Credit Score (PHP Currency) ---
$min_eligible_loan_amount = 1000; // Absolute minimum loan
$max_loan_amount = $min_eligible_loan_amount; // Default to absolute minimum
$available_terms = [3];
$user_rating = "Very Poor";

if ($current_credit_score < 450) { // Very Poor
    $max_loan_amount = 1000;
    $available_terms = [3];
    $user_rating = "Very Poor";
} elseif ($current_credit_score <= 549) { // Poor (Default score 500 falls here)
    $max_loan_amount = 10000; // Max ₱10,000 for default score range
    $available_terms = [3, 6];
    $user_rating = "Poor";
} elseif ($current_credit_score <= 649) { // Fair
    $max_loan_amount = 25000;
    $available_terms = [3, 6, 9];
    $user_rating = "Fair";
} elseif ($current_credit_score <= 749) { // Good
    $max_loan_amount = 60000;
    $available_terms = [3, 6, 9, 12, 18];
    $user_rating = "Good";
} else { // >= 750 (Excellent)
    $max_loan_amount = 150000;
    $available_terms = [3, 6, 9, 12, 18, 24];
    $user_rating = "Excellent";
}
// Ensure max_loan_amount is not less than the system minimum if eligible at all
if ($max_loan_amount < $min_eligible_loan_amount && $max_loan_amount > 0) {
    $max_loan_amount = $min_eligible_loan_amount;
}


$calculated_dynamic_rate_display = "N/A (Select amount and term)";

// --- Check for Delinquency ---
$is_delinquent = false;
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
    $loan_amount_for_calc = 0;
    $loan_amount_for_db = 0;

    if (empty($loan_amount_input)) {
        $errors['loan_amount'] = "Loan amount is required.";
    } elseif (!is_numeric($loan_amount_input)) {
        $errors['loan_amount'] = "Loan amount must be a number.";
    } elseif ($loan_amount_input < $min_eligible_loan_amount && $max_loan_amount >= $min_eligible_loan_amount) {
        // Check against absolute minimum only if they are eligible for loans at all
        $errors['loan_amount'] = "Minimum loan amount is ₱" . number_format($min_eligible_loan_amount, 2) . ".";
    } elseif ($loan_amount_input <= 0) { // This check is somewhat redundant due to min_eligible_loan_amount now
        $errors['loan_amount'] = "Loan amount must be greater than zero.";
    } elseif ($loan_amount_input > $max_loan_amount) {
        $errors['loan_amount'] = "Based on your credit score, the maximum loan amount you can currently request is ₱" . number_format($max_loan_amount, 2) . ".";
    } else {
        $loan_amount_for_db = floatval($loan_amount_input);
        $loan_amount_for_calc = $loan_amount_for_db;
    }

    if (empty($selected_term)) {
        $errors['loan_term'] = "Loan term is required.";
    } elseif (!in_array($selected_term, $available_terms)) {
        $errors['loan_term'] = "Invalid loan term selected for your credit profile.";
    }

    $dynamic_interest_rate_for_db = null;
    if (empty($errors['loan_amount']) && empty($errors['loan_term']) && $loan_amount_for_calc > 0 && $selected_term > 0) {
        $dynamic_interest_rate_for_db = calculate_dynamic_monthly_interest_rate(
            $current_credit_score,
            $loan_amount_for_calc,
            $selected_term
        );
        $calculated_dynamic_rate_display = number_format($dynamic_interest_rate_for_db * 100, 2) . "%";
    } elseif (($loan_amount_for_calc > 0 || $selected_term > 0) && (isset($errors['loan_amount']) || isset($errors['loan_term']))) {
        // An input was made but it's invalid, keep display as N/A or try to calc if one is valid
        $calculated_dynamic_rate_display = "N/A (Invalid amount/term)";
        if($loan_amount_for_calc > 0 && $selected_term > 0 && function_exists('calculate_dynamic_monthly_interest_rate')) { // if both available despite errors
             $temp_rate_val = calculate_dynamic_monthly_interest_rate($current_credit_score, $loan_amount_for_calc, $selected_term);
             $calculated_dynamic_rate_display = number_format($temp_rate_val * 100, 2) . "% (Check input errors)";
        }
    } else {
        $calculated_dynamic_rate_display = "N/A (Select amount and term)";
    }

    if (empty($errors)) {
        if ($dynamic_interest_rate_for_db === null) {
            $errors['general'] = "Could not calculate interest rate. Please ensure amount and term are valid.";
        } else {
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
} elseif ($_SERVER["REQUEST_METHOD"] !== "POST" && !$is_delinquent) {
    $calculated_dynamic_rate_display = "N/A (Select amount and term)";
}
?>

<h2>Request a New Loan</h2>
<p>Your current credit score: <strong><?php echo $current_credit_score; ?> (<?php echo $user_rating; ?>)</strong></p>

<?php if (isset($errors['delinquency'])): ?>
    <p class="error"><?php echo $errors['delinquency']; ?></p>
    <p><a href="<?php echo BASE_URL; ?>my_loans.php">View My Loans</a> to resolve issues.</p>
<?php else: ?>
    <?php if ($success_message): ?><p class="success"><?php echo $success_message; ?></p><?php endif; ?>
    <?php if (!empty($errors['general'])): ?><p class="error"><?php echo $errors['general']; ?></p><?php endif; ?>

    <form id="loanRequestForm" action="<?php echo htmlspecialchars(BASE_URL . 'request_loan.php'); ?>" method="post" onsubmit="return confirm('Are you sure you want to submit this loan request?');">
        <div>
            <label for="loan_amount">Loan Amount (Min: ₱<?php echo number_format($min_eligible_loan_amount,0); ?>; Max: ₱<?php echo number_format($max_loan_amount, 0); ?>):</label>
            <input type="number" step="1000" id="loan_amount" name="loan_amount" value="<?php echo htmlspecialchars($loan_amount_input); ?>" 
                   required <?php if ($max_loan_amount >= $min_eligible_loan_amount) echo 'min="'.$min_eligible_loan_amount.'"'; else echo 'min="1000"'; ?> 
                   max="<?php echo $max_loan_amount; ?>">
            <?php if (isset($errors['loan_amount'])): ?><p class="error"><?php echo $errors['loan_amount']; ?></p><?php endif; ?>
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
            <?php if (isset($errors['loan_term'])): ?><p class="error"><?php echo $errors['loan_term']; ?></p><?php endif; ?>
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
        <button type="submit" <?php if ($max_loan_amount < $min_eligible_loan_amount) echo 'disabled'; ?>>Submit Loan Request</button>
    </form>
<?php endif; ?>

<!-- Optional: JavaScript for live rate update (see previous examples, requires AJAX setup) -->

<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>dashboard.php">« Back to Dashboard</a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>