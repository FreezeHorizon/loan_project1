</div> <!-- End .container (from header) -->
<footer>
    <p style="text-align: center; margin-top: 20px; padding: 10px; background-color: #333; color: #fff;">
        Marvin Loaning System © <?php echo date('Y'); ?>
    </p>
</footer>

<script>
    const BASE_URL = '<?php echo BASE_URL; ?>'; // Make PHP BASE_URL available to JS
</script>
<script src="<?php echo BASE_URL; ?>js/main.js"></script>
</body>
</html>
<?php
// Close the database connection if it was opened
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>