document.addEventListener('DOMContentLoaded', function() {
    // Initializing listeners for keyboard shortcuts
    setupEventListeners();
});

// Modal Management 
function openAddModal() {
    const modal = document.getElementById('doctorModal');
    document.getElementById('modalTitle').innerText = "👨‍⚕️ Register New Doctor";
    document.getElementById('formAction').value = "add";
    document.getElementById('doctorForm').reset();
    
    // Reset Image Preview to default orange box
    document.getElementById('imagePreview').innerHTML = '<span>+</span>';
    
    // Setup password field for new doctor (Required)
    const passInput = document.getElementById('pass');
    document.getElementById('passLabel').innerHTML = 'Password <span style="color: red;">*</span>';
    passInput.required = true;
    passInput.placeholder = "";
    document.getElementById('passHelper').innerText = "Minimum 6 characters";
    
    clearAllErrors();
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Lock background scroll
}

/** @param {Object} doc - The doctor data object from the database */
function editDoctor(doc) {
    const modal = document.getElementById('doctorModal');
    document.getElementById('modalTitle').innerText = "✏️ Edit Doctor Profile";
    document.getElementById('formAction').value = "edit";
    
    // Populate Form Fields
    document.getElementById('docId').value = doc.doctor_id;
    document.getElementById('userId').value = doc.user_id;
    document.getElementById('fName').value = doc.first_name;
    document.getElementById('lName').value = doc.last_name;
    document.getElementById('email').value = doc.email;
    document.getElementById('spec').value = doc.specialization;
    document.getElementById('license').value = doc.license_number;
    document.getElementById('ic').value = doc.ic_number;
    document.getElementById('phone').value = doc.phone;
    document.getElementById('fee').value = doc.consultation_fee;

    // Password field setup for Edit (Optional)
    const passInput = document.getElementById('pass');
    document.getElementById('passLabel').innerText = 'Password (leave blank to keep current)';
    passInput.required = false;
    passInput.placeholder = "Enter new password only if changing";
    document.getElementById('passHelper').innerText = "Leave blank to keep current password";

    // Show Image Preview (Uses ../ relative to admin folder)
    const preview = document.getElementById('imagePreview');
    if (doc.profile_picture) {
        preview.innerHTML = `<img src="../${doc.profile_picture}" style="width:100%; height:100%; object-fit:cover; border-radius:12px;">`;
    } else {
        preview.innerHTML = `<span>${doc.first_name.charAt(0)}</span>`;
    }
    
    clearAllErrors();
    modal.classList.add('active');
    document.body.style.overflow = 'hidden'; // Lock background scroll
}

/* Closes the doctor management modal */
function closeModal() {
    document.getElementById('doctorModal').classList.remove('active');
    document.body.style.overflow = 'auto'; // Restore scroll
}

// Form Validation & Submission
/* Validates the doctor form fields */
function validateDoctorForm() {
    let isValid = true;
    clearAllErrors();

    const action = document.getElementById('formAction').value;

    // Required Fields Configuration
    const checks = [
        { id: 'fName', msg: 'First name is required' },
        { id: 'lName', msg: 'Last name is required' },
        { id: 'email', msg: 'Valid email is required', regex: /^[^\s@]+@[^\s@]+\.[^\s@]+$/ },
        { id: 'phone', msg: 'Valid phone is required' },
        { id: 'ic', msg: 'IC Number is required' },
        { id: 'license', msg: 'Medical license is required' },
        { id: 'spec', msg: 'Specialization is required' },
        { id: 'fee', msg: 'Consultation fee is required' }
    ];

    checks.forEach(check => {
        const input = document.getElementById(check.id);
        const val = input.value.trim();
        if (!val || (check.regex && !check.regex.test(val))) {
            showFieldError(check.id, check.msg);
            isValid = false;
        }
    });

    // Password Validation
    const passInput = document.getElementById('pass');
    if (action === 'add' && passInput.value.length < 6) {
        showFieldError('pass', 'Password must be at least 6 characters');
        isValid = false;
    } else if (action === 'edit' && passInput.value && passInput.value.length < 6) {
        showFieldError('pass', 'New password must be at least 6 characters');
        isValid = false;
    }

    return isValid;
}

function showFieldError(fieldId, message) {
    const errorEl = document.getElementById(fieldId + '_error');
    const inputEl = document.getElementById(fieldId);
    if (errorEl) errorEl.textContent = message;
    if (inputEl) inputEl.classList.add('error');
}

function clearAllErrors() {
    document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
    document.querySelectorAll('.modal-body input, .modal-body select').forEach(el => el.classList.remove('error'));
}

/* Handles the actual form submission */
function submitDoctorForm(e) {
    if (e) e.preventDefault();
    if (validateDoctorForm()) {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> Saving...';
        document.getElementById('doctorForm').submit();
    }
}

// Image & UI Helpers
/* Displays a live preview of the selected profile picture */
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover; border-radius:12px;">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

/* Displays a professional toast notification */
function showNotification(message, type = 'info') {
    const existing = document.querySelectorAll('.toast-notification');
    existing.forEach(n => n.remove());
    
    const colors = { success: '#66BB6A', error: '#EF5350', warning: '#FF9800', info: '#42A5F5' };
    const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
    
    const notification = document.createElement('div');
    notification.className = `toast-notification toast-${type}`;
    notification.style.cssText = `
        position: fixed; top: 20px; right: 20px; background: ${colors[type]};
        color: white; padding: 1rem 1.5rem; border-radius: 10px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.3); z-index: 10001;
        font-weight: 600; display: flex; align-items: center; gap: 0.5rem;
    `;
    
    notification.innerHTML = `<span>${icons[type]}</span><span>${message}</span>`;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.5s';
        setTimeout(() => notification.remove(), 500);
    }, 3000);
}

//  Export Functions 
/* Exports the doctor table to a CSV file */
function exportDoctorsCSV() {
    showNotification('Preparing CSV export...', 'info');
    const rows = document.querySelectorAll('.doctors-table tbody tr');
    if (rows.length === 0) return showNotification('No doctors to export', 'warning');
    
    let csv = 'Name,Email,Specialization,License No.,Phone,Status\n';
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const name = cells[1].querySelector('strong').textContent.trim();
        const email = cells[1].querySelector('small').textContent.trim();
        csv += `"${name}","${email}","${cells[2].textContent.trim()}","${cells[3].textContent.trim()}","${cells[4].textContent.trim()}","${cells[5].textContent.trim()}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `CarePlus_Doctors_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    showNotification('Doctors CSV exported successfully!', 'success');
}

/* Generates a professional PDF report of the doctors */
// Export doctors to a professional PDF report (Direct Download - Portrait)
function exportDoctorsPDF() {
    showNotification('Preparing professional doctors report...', 'info');
    
    // Check if jsPDF is loaded
    if (typeof window.jspdf === 'undefined') {
        showNotification('PDF library not loaded. Please refresh the page.', 'error');
        return;
    }
    
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'a4'); // 'p' for Portrait (Vertical)

    // Colors & Styling 
    const primaryColor = [255, 140, 66]; // CarePlus Orange
    const secondaryColor = [44, 62, 80]; // Dark Navy
    const lightGray = [245, 245, 245];
    const white = [255, 255, 255];

    // Header Banner 
    doc.setFillColor(...primaryColor);
    doc.rect(0, 0, 210, 40, 'F');

    doc.setTextColor(...white);
    doc.setFontSize(22);
    doc.setFont(undefined, 'bold');
    doc.text('CarePlus', 15, 18);

    doc.setFontSize(10);
    doc.setFont(undefined, 'normal');
    doc.text('Medical Staff & Doctor Directory Report', 15, 26);

    // Metadata (Right aligned at 195mm)
    doc.setFontSize(9);
    doc.text(`Generated: ${new Date().toLocaleString()}`, 195, 15, { align: 'right' });
    doc.text('Doctors Administration Module', 195, 20, { align: 'right' });

    // Statistics Summary Box
    const statsCards = document.querySelectorAll('.stat-card');
    let totalDocs = statsCards[0]?.querySelector('h3')?.textContent || '0';
    let activeDocs = statsCards[1]?.querySelector('h3')?.textContent || '0';
    let specializationCount = statsCards[2]?.querySelector('h3')?.textContent || '0';

    doc.setFillColor(...lightGray);
    doc.roundedRect(15, 45, 180, 22, 3, 3, 'F'); 
    
    doc.setTextColor(...secondaryColor);
    doc.setFontSize(8);
    doc.setFont(undefined, 'bold');

    // Position labels
    doc.text('TOTAL DOCTORS', 20, 52);
    doc.text('ACTIVE STAFF', 80, 52);
    doc.text('SPECIALIZATIONS', 140, 52);

    doc.setFontSize(11);
    doc.setTextColor(...primaryColor);
    doc.text(totalDocs, 20, 60);
    doc.text(activeDocs, 80, 60);
    doc.text(specializationCount, 140, 60);

    // Prepare Table Data
    const rows = document.querySelectorAll('.doctors-table tbody tr');
    const tableBody = [];

    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            // Index mapping based on your table structure:
            // 1: Name & Email, 2: Specialization, 3: License, 4: Phone, 5: Status
            const name = cells[1].querySelector('strong').textContent.trim();
            const email = cells[1].querySelector('small').textContent.trim();
            
            tableBody.push([
                name,
                email,
                cells[2].textContent.trim(), // Specialization
                cells[3].textContent.trim(), // License
                cells[4].textContent.trim(), // Phone
                cells[5].textContent.trim().toUpperCase() // Status
            ]);
        }
    });

    if (tableBody.length === 0) {
        showNotification('No doctors found to export', 'warning');
        return;
    }

    // Generate Table
    doc.autoTable({
        startY: 75,
        head: [['Doctor Name', 'Email Address', 'Specialization', 'License', 'Phone', 'Status']],
        body: tableBody,
        theme: 'striped',
        headStyles: { 
            fillColor: primaryColor, 
            textColor: 255, 
            fontSize: 9,
            fontStyle: 'bold'
        },
        styles: { 
            fontSize: 8, 
            cellPadding: 3,
            valign: 'middle'
        },
        columnStyles: {
            0: { cellWidth: 35 },
            1: { cellWidth: 45 },
            2: { cellWidth: 35 },
            3: { cellWidth: 20 },
            4: { cellWidth: 25 },
            5: { cellWidth: 20, fontStyle: 'bold' }
        },
        didParseCell: function(data) {
            // Apply color coding to the Status column (Index 5)
            if (data.section === 'body' && data.column.index === 5) {
                const status = data.cell.raw;
                if (status === 'ACTIVE') {
                    data.cell.styles.textColor = [22, 101, 52]; // Green
                } else if (status === 'INACTIVE') {
                    data.cell.styles.textColor = [178, 34, 34]; // Red
                }
            }
        },
        margin: { left: 15, right: 15 }
    });

    // Footer
    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        const pageHeight = doc.internal.pageSize.height;
        doc.setFontSize(8);
        doc.setTextColor(150);
        doc.text('CarePlus Smart Clinic Management Portal - Professional Staff Report', 15, pageHeight - 15);
        doc.text('Confidential: Internal Use Only.', 15, pageHeight - 10);
        doc.text(`Page ${i} of ${pageCount}`, 195, pageHeight - 10, { align: 'right' });
    }

    // Direct Download
    const timestamp = new Date().toISOString().split('T')[0];
    doc.save(`CarePlus_Doctors_Report_${timestamp}.pdf`);
    
    showNotification('Doctors report downloaded successfully!', 'success');
}

// Data Actions
/* Toggles doctor account status (active/inactive) */
function toggleStatus(doctorId, currentStatus) {
    const next = currentStatus === 'active' ? 'inactive' : 'active';
    const action = next === 'active' ? 'activate (unlock)' : 'deactivate (lock)';
    
    if (confirm(`Are you sure you want to ${action} this doctor?`)) {
        document.getElementById('statDocId').value = doctorId;
        document.getElementById('statNewVal').value = next;
        document.getElementById('statusForm').submit();
    }
}

/* Deletes a doctor account permanently */
function deleteDoctor(userId, doctorName) {
    if (confirm(`Are you sure you want to delete ${doctorName}? This action cannot be undone and will remove all associated data.`)) {
        document.getElementById('delUserId').value = userId;
        document.getElementById('deleteForm').submit();
    }
}

// Event Listeners & Utilities
function setupEventListeners() {
    // Close modal when clicking on backdrop
    const modal = document.getElementById('doctorModal');
    modal.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal-backdrop')) {
            closeModal();
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
        if (e.ctrlKey && e.key === 'n') { e.preventDefault(); openAddModal(); }
        if (e.ctrlKey && e.key === 'e') { e.preventDefault(); exportDoctorsCSV(); }
    });

    // Auto-hide PHP alert messages
    const alert = document.querySelector('.alert');
    if (alert) {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            alert.style.transition = '0.5s';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    }
}