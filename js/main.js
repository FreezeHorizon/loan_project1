// loan_project/js/main.js

// Function to update pending loans count for admin
function checkPendingLoans() {
    const pendingCountElement = document.getElementById('adminPendingLoanCount');
    const pendingCountLinkText = document.getElementById('adminPendingLoanLinkText'); // For the link text

    if (pendingCountElement) {
        fetch(BASE_URL + 'admin/get_pending_loans_count.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && typeof data.count !== 'undefined') {
                    pendingCountElement.textContent = data.count;
                    if (pendingCountLinkText) {
                        pendingCountLinkText.textContent = data.count; // Update link text too
                    }
                    // Optional: Visual cue if count changed, e.g., highlight
                    // if (parseInt(pendingCountElement.textContent) > 0) {
                    //     pendingCountElement.style.fontWeight = 'bold';
                    // } else {
                    //     pendingCountElement.style.fontWeight = 'normal';
                    // }
                }
            })
            .catch(error => {
                console.error('Error fetching pending loans count:', error);
            });
    }
}

// Function to update status of individual pending loans for user
function checkUserLoanStatuses() {
    const loanStatusCells = document.querySelectorAll('.user-loan-status[data-status="pending"]');

    loanStatusCells.forEach(cell => {
        const loanId = cell.dataset.loanId;
        if (loanId) {
            fetch(BASE_URL + 'get_loan_status.php?loan_id=' + loanId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.status && data.status !== 'pending') {
                        cell.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1); // Capitalize
                        cell.dataset.status = data.status; // Update status to stop polling this one
                        // Potentially refresh other parts of the row or show a "Pay" button if now active
                        // For simplicity, just updating status text for now.
                        // A more robust solution might involve re-rendering the action cell.
                        const actionCell = cell.closest('tr').querySelector('.loan-action-cell');
                        if(actionCell){
                            if(data.status === 'approved'){
                                actionCell.innerHTML = 'Approved (Awaiting start)';
                            } else if(data.status === 'rejected'){
                                actionCell.innerHTML = 'Rejected';
                            }
                            // If active, the page would typically need more data to show payment button
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching loan status for ID ' + loanId + ':', error);
                });
        }
    });
}


// --- Initialize Polling ---
// Ensure BASE_URL is available (e.g., from a script tag in header.php or footer.php)
// For admin page
if (document.getElementById('adminPendingLoanCount')) {
    checkPendingLoans(); // Initial check
    setInterval(checkPendingLoans, 15000); // Poll every 15 seconds
}

// For user's my_loans page
if (document.querySelector('.user-loan-status[data-status="pending"]')) {
    checkUserLoanStatuses(); // Initial check
    setInterval(checkUserLoanStatuses, 10000); // Poll every 10 seconds
}