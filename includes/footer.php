<?php // Keep this file as loan_project/includes/footer.php ?>
        </div> <!-- End .main-content-area (opened in header.php) -->
    
    <footer class="site-footer">
        <div class="container"> <!-- Optional: if you want footer content centered too -->
            <p style="text-align: center; margin-top: 20px; padding: 10px; background-color: #333; color: #fff;">
                <?php>  MarvinLoans &copy <php?>
            </p>
        </div>
    </footer>

    <script>
        const BASE_URL = '<?php echo BASE_URL; ?>'; // Make PHP BASE_URL available to JS
    </script>
    <script src="<?php echo BASE_URL; ?>js/main.js"></script> <?php // Ensure js/main.js exists ?>

</body>
</html>
<?php
// Close the database connection if it was opened
if (isset($conn) && $conn instanceof mysqli && $conn->ping()) { // Check if $conn is valid and connection is alive
    $conn->close();
}
?>