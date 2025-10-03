// --- Consolidated Scripts ---
document.addEventListener('DOMContentLoaded', function() {

    // --- Add User Page (File 1) ---
    const roleSelect = document.getElementById('role');
    const collegeGroup = document.getElementById('college-group');
    const departmentGroup = document.getElementById('department-group');
    const collegeSelect = document.getElementById('college');
    const departmentSelect = document.getElementById('department');
    const collegeRequiredSpan = document.getElementById('college-required');
    const departmentRequiredSpan = document.getElementById('department-required');

    function toggleConditionalFields() {
        // Ensure elements exist before trying to access properties/methods
        if (!roleSelect) return; // Exit if the main role select isn't found

        const role = roleSelect.value;

        // Hide all conditional fields initially and reset required status
        if (collegeGroup) collegeGroup.classList.add('hidden');
        if (departmentGroup) departmentGroup.classList.add('hidden');
        if (collegeSelect) collegeSelect.required = false;
        if (departmentSelect) departmentSelect.required = false;
        if (collegeRequiredSpan) collegeRequiredSpan.style.display = 'none';
        if (departmentRequiredSpan) departmentRequiredSpan.style.display = 'none';

        if (role === 'department_head') {
            if (departmentGroup) departmentGroup.classList.remove('hidden');
            if (departmentSelect) departmentSelect.required = true;
            if (departmentRequiredSpan) departmentRequiredSpan.style.display = 'inline';

            if (collegeGroup) collegeGroup.classList.remove('hidden'); // Show college for context
            if (collegeSelect) collegeSelect.required = false; // Not strictly required
            if (collegeRequiredSpan) collegeRequiredSpan.style.display = 'none';

        } else if (role === 'college_dean') {
            if (collegeGroup) collegeGroup.classList.remove('hidden');
            if (collegeSelect) collegeSelect.required = true;
            if (collegeRequiredSpan) collegeRequiredSpan.style.display = 'inline';

            if (departmentGroup) departmentGroup.classList.add('hidden'); // Dept not needed
            if (departmentSelect) departmentSelect.required = false;
            if (departmentRequiredSpan) departmentRequiredSpan.style.display = 'none';

        } else if (role === 'user') { // Assuming 'user' means student/staff needing both
            if (collegeGroup) collegeGroup.classList.remove('hidden');
            if (departmentGroup) departmentGroup.classList.remove('hidden');
            if (collegeSelect) collegeSelect.required = true;
            if (departmentSelect) departmentSelect.required = true;
            if (collegeRequiredSpan) collegeRequiredSpan.style.display = 'inline';
            if (departmentRequiredSpan) departmentRequiredSpan.style.display = 'inline';
        }
        // Add conditions for other roles requiring college/dept if necessary
    }

    if (roleSelect) { // Check if roleSelect exists before adding listener
        roleSelect.addEventListener('change', toggleConditionalFields);
        toggleConditionalFields(); // Run on initial load
    }

    // Password Toggle (File 1)
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) { // Check if elements exist
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            // Toggle eye icon (assuming Font Awesome classes)
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    // --- Backup/Restore Page (File 2) ---
    // File input display
    const fileInput = document.getElementById('backupFile');
    const fileNameDisplay = document.getElementById('fileName');
    const fileLabel = document.getElementById('fileLabel');
    const fileLabelSpan = fileLabel ? fileLabel.querySelector('span') : null;

    if (fileInput && fileNameDisplay && fileLabel && fileLabelSpan) {
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileNameDisplay.textContent = this.files[0].name;
                fileNameDisplay.style.display = 'block';
                fileLabelSpan.textContent = 'File selected:'; // Update label text
            } else {
                fileNameDisplay.style.display = 'none';
                fileNameDisplay.textContent = '';
                fileLabelSpan.textContent = 'Choose backup file (.sql)'; // Reset label text
            }
        });
    }

    // Confirm before restore
    // More specific selector targeting the restore form based on button name/presence
    const restoreForm = document.querySelector('form input[name="backup_file"]')?.closest('form');
    if (restoreForm) {
        restoreForm.addEventListener('submit', function(e) {
            const message = restoreForm.getAttribute('data-confirm-message') || 'WARNING: This action will overwrite the current database with the selected backup file. All existing data will be lost. Are you absolutely sure you want to proceed?';
            if (!confirm(message)) {
                e.preventDefault(); // Stop form submission if user cancels
            }
        });
        // Optionally remove inline onsubmit if it exists
        if (restoreForm.hasAttribute('onsubmit')) {
            restoreForm.removeAttribute('onsubmit');
        }
    }

     // --- Manage Abusive Words Page (File 4) ---
    // Confirmation for delete buttons (using event delegation)
    // Use a more specific container selector if possible
    const abusiveWordsContainer = document.querySelector('.container'); // Adjust if needed
    if (abusiveWordsContainer && abusiveWordsContainer.querySelector('.abusive-words-table')) { // Check if the table exists
        abusiveWordsContainer.addEventListener('click', function(event) {
            const deleteButton = event.target.closest('a.btn-danger'); // Target the anchor tag
            // Ensure it's specifically for deleting an abusive word
            if (deleteButton && deleteButton.href.includes('manage_abusive_words.php?delete=')) {
                event.preventDefault(); // Prevent the default link behavior first
                const wordText = deleteButton.closest('tr')?.querySelector('td[data-label="Word"]')?.textContent || 'this word';
                if (confirm(`Are you sure you want to delete the word "${wordText.trim()}"? This action cannot be undone.`)) {
                    window.location.href = deleteButton.href; // Proceed if confirmed
                }
            }
        });
    }


    // --- Manage Users Page (File 5) ---
    // Filter auto-submit
    const filterForm = document.querySelector('.filter-form form');
    if (filterForm) {
        const filterSelects = filterForm.querySelectorAll('select[name="role"], select[name="status"]');
        filterSelects.forEach(select => {
             if (select.hasAttribute('onchange')) {
                 select.removeAttribute('onchange'); // Remove inline JS
             }
            select.addEventListener('change', function() {
                filterForm.submit();
            });
        });
    }

    // Delete user confirmation (using event delegation)
    // Use a more specific container selector if possible
    const manageUsersContainer = document.querySelector('.container'); // Adjust if needed
    if (manageUsersContainer && manageUsersContainer.querySelector('.manage-users-table')) { // Check if the table exists
        manageUsersContainer.addEventListener('click', function(event) {
            const targetButton = event.target.closest('button[name="delete"]');
            if (targetButton) {
                const deleteForm = targetButton.closest('form');
                if (deleteForm && deleteForm.closest('.manage-users-table')) { // Ensure it's the user delete form
                    event.preventDefault(); // Stop form submission
                    const usernameElement = deleteForm.closest('tr')?.querySelector('td[data-label="Username"] strong');
                    const username = usernameElement ? usernameElement.textContent.trim() : 'this user';
                    const confirmationMessage = `WARNING: Deleting user ${username} is permanent and cannot be undone. Associated records might prevent deletion. Continue?`;

                    if (confirm(confirmationMessage)) {
                        deleteForm.submit(); // Submit the form if confirmed
                    }
                }
            }
        });
         // Remove inline onsubmit from delete forms (if any exist)
         const deleteForms = manageUsersContainer.querySelectorAll('.manage-users-table form button[name="delete"]');
         deleteForms.forEach(button => {
             const form = button.closest('form');
             if (form && form.hasAttribute('onsubmit')) {
                 form.removeAttribute('onsubmit');
             }
         });
    }

    // --- General ---
    // Example: Close alert messages after a delay
    const alerts = document.querySelectorAll('.alert-success, .alert-warning, .alert-danger');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500); // Remove from DOM after fade out
        }, 7000); // Hide after 7 seconds
    });

}); // End DOMContentLoaded