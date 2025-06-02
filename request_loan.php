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

// --- Fetch User's Current Credit Score AND Monthly Income ---
$current_credit_score = 300; // Poorest fallback
$current_monthly_income = 0;   // Fallback
$stmt_user_data = $conn->prepare("SELECT credit_score, monthly_income FROM users WHERE id = ?");
if ($stmt_user_data) {
    $stmt_user_data->bind_param("i", $user_id);
    $stmt_user_data->execute();
    $result_user_data = $stmt_user_data->get_result();
    if ($user_data_row = $result_user_data->fetch_assoc()) {
        $current_credit_score = $user_data_row['credit_score'] ?? 300;
        $current_monthly_income = $user_data_row['monthly_income'] ?? 0;
    }
    $stmt_user_data->close();
}

// --- Define Loan Options Based on Credit Score & Monthly Income (PHP Currency) ---
$min_eligible_loan_amount = 1000;
$max_loan_amount_by_score = $min_eligible_loan_amount;
$available_terms = [3];
$user_rating = "Very Poor";

// Tiering by Credit Score (determines base max amount and terms)
if ($current_credit_score < 450) { // Very Poor
    $max_loan_amount_by_score = 1000;
    $available_terms = [3];
    $user_rating = "Very Poor";
} elseif ($current_credit_score <= 549) { // Poor
    $max_loan_amount_by_score = 10000;
    $available_terms = [3, 6];
    $user_rating = "Poor";
} elseif ($current_credit_score <= 649) { // Fair
    $max_loan_amount_by_score = 25000;
    $available_terms = [3, 6, 9];
    $user_rating = "Fair";
} elseif ($current_credit_score <= 749) { // Good
    $max_loan_amount_by_score = 60000;
    $available_terms = [3, 6, 9, 12, 18];
    $user_rating = "Good";
} else { // >= 750 (Excellent)
    $max_loan_amount_by_score = 150000;
    $available_terms = [3, 6, 9, 12, 18, 24];
    $user_rating = "Excellent";
}

// Income-based cap (e.g., max loan is 2x monthly income, or a DTI consideration)
// This is a simplified cap. Real DTI also considers existing debts.
$max_loan_amount_by_income = $current_monthly_income * 2; // Example: Max loan is 2x monthly income
if ($current_monthly_income <= 0) { // If no income provided or zero, very restrictive
    $max_loan_amount_by_income = $min_eligible_loan_amount; // Allow only absolute minimum if income is problematic
}


// Final max loan amount is the LESSER of score-based and income-based limit
$max_loan_amount = min($max_loan_amount_by_score, $max_loan_amount_by_income);

// Ensure it's not below the absolute minimum eligible loan amount, unless income forces it lower than score allows
if ($max_loan_amount < $min_eligible_loan_amount) {
    // If income cap brought it below min_eligible, but score allowed more, it means income is the constraint.
    // If both score and income suggest less than min_eligible, then max_loan_amount might be very low or 0.
    // We want to allow at least min_eligible if score/income don't completely disqualify.
    // This logic might need refinement based on precise business rules for minimums.
    // For now, if calculated max is below system min, they might not be eligible for any loan
    // or capped at system min if score allows.
    if ($max_loan_amount_by_score >= $min_eligible_loan_amount) { // If score itself allows for min loan
        $max_loan_amount = max($max_loan_amount, 0); // Can't be negative
        if ($max_loan_amount < $min_eligible_loan_amount && $max_loan_amount > 0) {
             // If income made it positive but less than min, this scenario is tricky.
             // For now, let's say if income calculation results in less than min_eligible_loan_amount,
             // they can only borrow up to that income-capped amount, even if it's less than 1000.
             // Or, you decide they can't borrow at all if income cap is below system min.
             // Let's enforce the system minimum if they are eligible at all.
             // $max_loan_amount = ($max_loan_amount > 0) ? max($max_loan_amount, $min_eligible_loan_amount) : 0; NO - this is wrong
        }
    } else { // Score itself doesn't allow even the min loan
        $max_loan_amount = $max_loan_amount_by_score; // which could be less than min_eligible
    }
    // Simpler rule: If the final max_loan_amount (after considering income) is less than min_eligible_loan_amount,
    // but greater than 0, they can borrow that smaller amount. If it's 0, they can't borrow.
    // If $max_loan_amount_by_score was already less than $min_eligible_loan_amount, it remains that.
}
// If after all calculations, max_loan is positive but below system minimum,
// we should probably cap it at 0 unless we allow loans smaller than min_eligible_loan_amount.
// For now, the min attribute on input will handle if it becomes too low.
if ($max_loan_amount > 0 && $max_loan_amount < $min_eligible_loan_amount) {
    // Decision: either $max_loan_amount = 0 (cannot borrow) or allow this smaller amount.
    // Let's say for now, if it's positive, they can request it. The 'min' on input will be an issue.
    // A better approach: if the calculated $max_loan_amount < $min_eligible_loan_amount, then $max_loan_amount = 0 (unless specific cases)
}
if ($current_monthly_income <= 0 && $max_loan_amount > 0){ // Override if no income
    // $max_loan_amount = 0; // Stricter: No income, no loan above min or at all.
    // For now, rely on $max_loan_amount_by_income setting it low.
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
    } elseif (floatval($loan_amount_input) < $min_eligible_loan_amount && $max_loan_amount >= $min_eligible_loan_amount) {
        $errors['loan_amount'] = "Minimum loan amount is ₱" . number_format($min_eligible_loan_amount, 0) . ".";
    } elseif (floatval($loan_amount_input) <= 0) {
        $errors['loan_amount'] = "Loan amount must be greater than zero.";
    } elseif (floatval($loan_amount_input) > $max_loan_amount) {
        $errors['loan_amount'] = "Based on your profile, the maximum loan amount you can request is ₱" . number_format($max_loan_amount, 0) . ".";
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
    if (empty($errors['loan_amount']) && empty($errors['loan_term']) && $loan_amount_for_calc >= $min_eligible_loan_amount && $selected_term > 0) {
        $dynamic_interest_rate_for_db = calculate_dynamic_monthly_interest_rate(
            $current_credit_score,
            $loan_amount_for_calc,
            $selected_term
        );
        $calculated_dynamic_rate_display = number_format($dynamic_interest_rate_for_db * 100, 2) . "%";
    } elseif (($loan_amount_for_calc > 0 || $selected_term > 0)) {
        if($loan_amount_for_calc >= $min_eligible_loan_amount && $selected_term > 0 && function_exists('calculate_dynamic_monthly_interest_rate') && empty($errors['loan_amount']) && empty($errors['loan_term'])) {
             $temp_rate_val = calculate_dynamic_monthly_interest_rate($current_credit_score, $loan_amount_for_calc, $selected_term);
             $calculated_dynamic_rate_display = number_format($temp_rate_val * 100, 2) . "%"; // Show even if other errors exist
        } else {
             $calculated_dynamic_rate_display = "N/A (Enter valid amount & term)";
        }
    } else {
        $calculated_dynamic_rate_display = "N/A (Select amount and term)";
    }

    if (empty($errors)) {
        if ($dynamic_interest_rate_for_db === null) {
            $errors['general'] = "Could not calculate interest rate. Please ensure amount (min ₱".number_format($min_eligible_loan_amount,0).") and term are valid.";
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

<style>
.tooltip-trigger {
    cursor: help;
    position: relative;
    display: inline-block;
    border-bottom: 1px dotted black; /* Optional: to make it look more like a help link */
}

.tooltip-trigger:hover::after {
    content: attr(title);
    position: absolute;
    left: 0;
    bottom: 100%; /* Position above the trigger */
    margin-bottom: 5px; /* Space between trigger and tooltip */
    background-color: #555;
    color: #fff;
    text-align: center;
    padding: 5px;
    border-radius: 6px;
    z-index: 1;
    font-size: 0.9em;
    white-space: nowrap;
}
</style>

<h2>Request a New Loan</h2>
<p>Your current credit score: <strong><?php echo $current_credit_score; ?> (<?php echo $user_rating; ?>)</strong>. Your monthly income: <strong>₱<?php echo number_format($current_monthly_income, 2); ?></strong></p>

<?php if (isset($errors['delinquency'])): ?>
    <p class="error"><?php echo $errors['delinquency']; ?></p>
    <p><a href="<?php echo BASE_URL; ?>my_loans.php">View My Loans</a> to resolve issues.</p>
<?php elseif ($max_loan_amount < $min_eligible_loan_amount && !$is_delinquent) : ?>
    <p class="error">Based on your current profile (credit score and/or income), you are not eligible for a loan amount of at least ₱<?php echo number_format($min_eligible_loan_amount, 0);?> at this time.</p>
    <p>Max calculated loan amount: ₱<?php echo number_format($max_loan_amount,0); ?>. Please improve your credit score or update your income details if they have changed.</p>
<?php else: ?>
    <?php if ($success_message): ?><p class="success"><?php echo $success_message; ?></p><?php endif; ?>
    <?php if (!empty($errors['general'])): ?><p class="error"><?php echo $errors['general']; ?></p><?php endif; ?>

    <form id="loanRequestForm" action="<?php echo htmlspecialchars(BASE_URL . 'request_loan.php'); ?>" method="post" onsubmit="return confirm('Are you sure you want to submit this loan request?');">
        <div>
            <label for="loan_amount">Loan Amount (Min: ₱<?php echo number_format($min_eligible_loan_amount,0); ?>):</label>
            <input type="number" step="1" id="loan_amount" name="loan_amount" value="<?php echo htmlspecialchars($loan_amount_input); ?>"
                   required min="<?php echo $min_eligible_loan_amount; ?>" max="<?php echo $max_loan_amount > 0 ? $max_loan_amount : $min_eligible_loan_amount; /* Ensure max is at least min if calculated max is 0 */ ?>"
                   title="Enter an amount starting from ₱<?php echo number_format($min_eligible_loan_amount,0); ?>">
            <?php if (isset($errors['loan_amount'])): ?><p class="error"><?php echo $errors['loan_amount']; ?></p><?php endif; ?>
            <small style="display:block; margin-top: 5px;">The maximum loan amount you can currently request is ₱<?php echo number_format($max_loan_amount, 0); ?>, based on your credit profile and income.</small>
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
            <label for="calculated_emi">Estimated Monthly Payment (EMI) <span class="tooltip-trigger" title="Equated Monthly Installment">(?)</span>:</label>
            <input type="text" id="calculated_emi" name="calculated_emi_display" value="N/A" readonly style="font-weight:bold;">
            <small style="display:block; margin-top: 5px;">The EMI is an estimate calculated based on your credit score, requested loan amount, and loan term. Final terms are subject to approval.</small>
        </div>
        <div>
            <label for="calculated_total_repayment">Estimated Total Repayment:</label>
            <input type="text" id="calculated_total_repayment" name="calculated_total_repayment_display" value="N/A" readonly style="font-weight:bold;">
        </div>
        <div>
            <label for="loan_purpose">Purpose of Loan (Optional):</label>
            <textarea id="loan_purpose" name="loan_purpose" rows="3"><?php echo htmlspecialchars($loan_purpose); ?></textarea>
        </div>
        <button type="submit">Submit Loan Request</button>
    </form>
<?php endif; ?>

<!-- Optional: JavaScript for live rate update (see previous examples, requires AJAX setup) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('loan_amount');
    const termSelect = document.getElementById('loan_term');
    const rateDisplayInput = document.getElementById('calculated_interest_rate');
    const emiDisplayInput = document.getElementById('calculated_emi');
    const totalRepaymentDisplayInput = document.getElementById('calculated_total_repayment');

    function fetchAndUpdateRate() {
        const amount = parseFloat(amountInput.value);
        const term = parseInt(termSelect.value);
        const minAmount = parseFloat(amountInput.min); // Get min from attribute
        const maxAmount = parseFloat(amountInput.max); // Get max from attribute

        // Basic client-side validation before AJAX
        if (amount >= minAmount && amount <= maxAmount && term > 0 && termSelect.value !== "") {
            rateDisplayInput.value = "Calculating...";
            emiDisplayInput.value = "Calculating...";
            totalRepaymentDisplayInput.value = "Calculating...";
            fetch(BASE_URL + 'calculate_rate_ajax.php?amount=' + amount + '&term=' + term)
                .then(response => {
                    if (!response.ok) { throw new Error('Network response was not ok'); }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        rateDisplayInput.value = data.rate_display; // Already includes %
                        emiDisplayInput.value = data.emi_display;
                        totalRepaymentDisplayInput.value = data.total_repayment_display;
                    } else {
                        rateDisplayInput.value = data.rate_display || "Error calculating";
                        emiDisplayInput.value = "N/A";
                        totalRepaymentDisplayInput.value = "N/A";
                    }
                })
                .catch(error => {
                    console.error('Error fetching rate:', error);
                    rateDisplayInput.value = "Error fetching rate";
                    emiDisplayInput.value = "N/A";
                    totalRepaymentDisplayInput.value = "N/A";
                });
        } else {
            if (amountInput.value || termSelect.value) {
                rateDisplayInput.value = "N/A (Invalid amount/term)";
            } else {
                rateDisplayInput.value = "N/A (Select amount and term)";
            }
            emiDisplayInput.value = "N/A";
            totalRepaymentDisplayInput.value = "N/A";
        }
    }

    if (amountInput && termSelect && rateDisplayInput && emiDisplayInput && totalRepaymentDisplayInput && typeof BASE_URL !== 'undefined') { // Check if elements exist
        amountInput.addEventListener('input', fetchAndUpdateRate);
        termSelect.addEventListener('change', fetchAndUpdateRate);

        // Initial calculation if form is pre-filled by PHP on reload with valid values
        if (amountInput.value && termSelect.value &&
            (rateDisplayInput.value.includes("N/A") || rateDisplayInput.value.includes("% (Check input errors)"))) {
            // Only call if PHP didn't already provide a final rate (e.g., after successful submission)
            // And if the inputs are valid according to their attributes
            if (parseFloat(amountInput.value) >= parseFloat(amountInput.min) && parseFloat(amountInput.value) <= parseFloat(amountInput.max) && parseInt(termSelect.value) > 0) {
                 fetchAndUpdateRate();
            }
        }
    }
});
</script>


<p style="margin-top: 20px;"><a href="<?php echo BASE_URL; ?>dashboard.php">« Back to Dashboard</a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>