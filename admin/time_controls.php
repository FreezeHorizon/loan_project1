<?php
require_once __DIR__ . '/../includes/header.php'; // Includes db_connect.php, functions.php

if (!is_logged_in() || !is_admin()) {
    redirect(BASE_URL . 'login.php');
}

$current_simulated_datetime_str = get_simulated_date($conn); // Fetches current "YYYY-MM-DD HH:MM:SS"
$current_simulated_datetime_obj = new DateTime($current_simulated_datetime_str);

$message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['advance_action'])) {
    $action = $_POST['advance_action'];
    $new_simulated_datetime_obj = clone $current_simulated_datetime_obj; // Start with current time

    $days_to_advance = 0; // For penalty processing trigger

    switch ($action) {
        case 'advance_1_day':
            $new_simulated_datetime_obj->add(new DateInterval('P1D'));
            $days_to_advance = 1;
            $message = "Simulated time advanced by 1 day.";
            break;
        case 'advance_7_days':
            $new_simulated_datetime_obj->add(new DateInterval('P7D'));
            $days_to_advance = 7;
            $message = "Simulated time advanced by 7 days.";
            break;
        case 'advance_1_month':
            $new_simulated_datetime_obj->add(new DateInterval('P1M'));
            // For monthly advance, we might want to set the day processing trigger to a larger number
            // to ensure monthly checks are run. Or handle it based on month change.
            // For simplicity, let's treat it as approx 30 days for now for penalty check frequency.
            $days_to_advance = 30; 
            $message = "Simulated time advanced by 1 month.";
            break;
        case 'advance_custom_days':
            if (isset($_POST['custom_days']) && is_numeric($_POST['custom_days']) && intval($_POST['custom_days']) > 0) {
                $custom_days = intval($_POST['custom_days']);
                $new_simulated_datetime_obj->add(new DateInterval('P' . $custom_days . 'D'));
                $days_to_advance = $custom_days;
                $message = "Simulated time advanced by $custom_days day(s).";
            } else {
                $error_message = "Invalid number of custom days specified.";
            }
            break;
        default:
            $error_message = "Invalid time advance action.";
            break;
    }

    if (empty($error_message)) {
        $new_simulated_datetime_str_db = $new_simulated_datetime_obj->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("UPDATE system_time SET current_simulated_date = ? WHERE id = 1");
        if ($stmt) {
            $stmt->bind_param("s", $new_simulated_datetime_str_db);
            if ($stmt->execute()) {
                // Update current time for display after successful DB update
                $current_simulated_datetime_str = $new_simulated_datetime_str_db;
                $current_simulated_datetime_obj = new DateTime($current_simulated_datetime_str); // Re-fetch or update

                // --- Call the processing script ---
                // We pass the number of days advanced to help the script decide what checks to run
                // And the new current simulated date string
                require_once __DIR__ . '/../includes/process_time_advance.php';
                $processing_messages = process_loan_updates($conn, $days_to_advance, $current_simulated_datetime_str);
                
                if (!empty($processing_messages['success'])) {
                    $message .= " Processing successful: " . implode("; ", $processing_messages['success']);
                }
                if (!empty($processing_messages['errors'])) {
                    $error_message .= " Processing errors: " . implode("; ", $processing_messages['errors']);
                }
                // --- End calling processing script ---

            } else {
                $error_message = "Failed to update simulated time in database: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Database statement preparation failed: " . $conn->error;
        }
    }
}
?>

<h2>Advance System Time</h2>

<p>Current Simulated System DateTime: <strong><?php echo htmlspecialchars($current_simulated_datetime_obj->format('Y-m-d H:i:s')); ?></strong></p>

<?php if ($message): ?><p class="success"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
<?php if ($error_message): ?><p class="error"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>

<div style="margin-bottom: 20px;">
    <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/time_controls.php'); ?>" method="post" style="display:inline-block; margin-right:10px;">
        <button type="submit" name="advance_action" value="advance_1_day">Advance 1 Day</button>
    </form>
    <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/time_controls.php'); ?>" method="post" style="display:inline-block; margin-right:10px;">
        <button type="submit" name="advance_action" value="advance_7_days">Advance 7 Days</button>
    </form>
    <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/time_controls.php'); ?>" method="post" style="display:inline-block;">
        <button type="submit" name="advance_action" value="advance_1_month">Advance 1 Month</button>
    </form>
</div>

<div>
    <form action="<?php echo htmlspecialchars(BASE_URL . 'admin/time_controls.php'); ?>" method="post">
        <label for="custom_days">Advance by Custom Days:</label>
        <input type="number" id="custom_days" name="custom_days" min="1" value="1" style="width: 70px;">
        <button type="submit" name="advance_action" value="advance_custom_days">Advance</button>
    </form>
</div>

<p style="margin-top: 30px;"><a href="<?php echo BASE_URL; ?>admin/index.php">« Back to Admin Dashboard</a></p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>