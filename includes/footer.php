    </div> <!-- End .container -->
    <footer>
        <p style="text-align: center; margin-top: 20px; padding: 10px; background-color: #333; color: #fff;">
            Loaning System © <?php echo date('Y'); ?>
        </p>
    </footer>
    <!-- You can include JavaScript files here if needed globally -->
    <!-- <script src="<?php echo BASE_URL; ?>js/main.js"></script> -->
</body>
</html>
<?php
// Close the database connection if it was opened
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>