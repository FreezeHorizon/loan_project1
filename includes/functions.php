<?php
// Ensure db_connect.php is included, as it starts the session.
// If not already included by the calling script, include it.
if (!isset($conn)) {
    require_once 'db_connect.php';
}

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data); // Prevents XSS
    return $data;
}

// Function to validate username (alphanumeric, underscores, hyphens)
function is_valid_username($username) {
    // Allow letters, numbers, underscores, hyphens, between 3 and 30 characters
    if (preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
        return true;
    }
    return false;
}

// Function to validate full name (letters, spaces, hyphens)
function is_valid_full_name($name) {
    // Allow letters, spaces, hyphens, apostrophes (for names like O'Malley)
    if (preg_match("/^[a-zA-Z-' ]*$/", $name)) {
        return true;
    }
    return false;
}


// Function to check if a user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Function to check if the logged-in user is an admin
function is_admin() {
    return (is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
}

// Function to redirect to a different page
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Function to calculate Equated Monthly Installment (EMI)
function calculate_emi($principal, $annual_interest_rate_percentage, $term_months) {
    if ($principal <= 0 || $term_months <= 0) {
        return 0; // Or handle error appropriately
    }
    if ($annual_interest_rate_percentage == 0) { // No interest loan
        return round($principal / $term_months, 2);
    }

    $monthly_interest_rate = ($annual_interest_rate_percentage / 100) / 12;
    // If you store monthly rate directly (e.g., 0.005 for 0.5%), use that:
    // $monthly_interest_rate = $stored_monthly_rate;


    // EMI = P * r * (1+r)^n / ((1+r)^n - 1)
    $emi = ($principal * $monthly_interest_rate * pow(1 + $monthly_interest_rate, $term_months)) / (pow(1 + $monthly_interest_rate, $term_months) - 1);
    return round($emi, 2);
}

// If you are consistently using the monthly rate (e.g., 0.005 for 0.5%) as stored in DB:
function calculate_emi_from_monthly_rate($principal, $monthly_interest_rate, $term_months) {
    if ($principal <= 0 || $term_months <= 0) {
        return 0;
    }
    if ($monthly_interest_rate == 0) {
        return round($principal / $term_months, 2);
    }

    $r = $monthly_interest_rate; // This is the direct monthly rate like 0.005
    $n = $term_months;
    $P = $principal;

    $emi = ($P * $r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);
    return round($emi, 2);
}
function get_simulated_date($db_connection) { // Make sure it accepts $db_connection
    // The global $conn variable should be available if db_connect.php was included before this function is called.
    // If you are unsure or want to be explicit, pass $conn as an argument like it is now.
    
    $stmt = $db_connection->prepare("SELECT current_simulated_date FROM system_time WHERE id = 1");
    // It's better to use prepared statements here too, though less critical for a fixed query
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['current_simulated_date']; // This is now DATETIME
        }
        if($stmt) $stmt->close(); // Close statement if open
    }
    return date('Y-m-d H:i:s'); // Fallback, returns DATETIME string
}

function calculate_dynamic_monthly_interest_rate($credit_score, $loan_amount, $loan_term_months) {
    $base_monthly_rate = 0.0050; // 0.50%

    // Adjustments (as decimals, e.g., 0.05% = 0.0005)
    $score_adjustment = 0.0;
    if ($credit_score >= 750) { // Excellent
        $score_adjustment = -0.0005; 
    } elseif ($credit_score >= 550 && $credit_score <= 649) { // Fair
        $score_adjustment = 0.0005;
    } elseif ($credit_score >= 450 && $credit_score <= 549) { // Poor
        $score_adjustment = 0.0010;
    } elseif ($credit_score < 450) { // Very Poor
        $score_adjustment = 0.0015;
    }
    // Good (650-749) has no score_adjustment from base

    $amount_adjustment = 0.0;
    // Adjusted thresholds for PHP currency
    if ($loan_amount > 50000) { // Example: Large loan in PHP
        $amount_adjustment = -0.0002; 
    } elseif ($loan_amount <= 5000) { // Example: Small loan in PHP
        $amount_adjustment = 0.0002;  
    }
    // Medium loan (e.g., ₱5001 - ₱50000) has no amount_adjustment

    $term_adjustment = 0.0;
    if ($loan_term_months >= 18) { // Long
        $term_adjustment = 0.0005;
    } elseif ($loan_term_months <= 6) { // Short
        $term_adjustment = -0.0002;
    }
    // Medium term (9-12 months) has no term_adjustment

    $effective_rate = $base_monthly_rate + $score_adjustment + $amount_adjustment + $term_adjustment;

    // Apply Min/Max Rate Caps
    $min_rate = 0.0030; // 0.30% monthly
    $max_rate = 0.0100; // 1.00% monthly (adjust if needed for PHP context)

    if ($effective_rate < $min_rate) {
        $effective_rate = $min_rate;
    }
    if ($effective_rate > $max_rate) {
        $effective_rate = $max_rate;
    }

    return round($effective_rate, 4);
}
?>