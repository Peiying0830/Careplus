// Global variables
let allRecords = [];
let allPatients = [];

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Medical Records system initialized');
    
    // Load records data
    loadRecordsData();
    
    // Initialize event listeners
    initEventListeners();
    
    // Initialize animations
    initAnimations();
    
    // Set default date to today
    const visitDateInput = document.getElementById('visitDate');
    if (visitDateInput) {
        visitDateInput.valueAsDate = new Date();
    }
});

// Load records data from hidden div
function loadRecordsData() {
    const recordsDataElement = document.getElementById('records-data');
    if (recordsDataElement) {
        try {
            allRecords = JSON.parse(recordsDataElement.textContent);
            console.log(`Loaded ${allRecords.length} medical records`);
        } catch (e) {
            console.error('Error parsing records data:', e);
            allRecords = [];
        }
    }
}

// Initialize event listeners
function initEventListeners() {
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(handleSearch, 300));
    }
    
    // Filter functionality
    const filterDate = document.getElementById('filterDate');
    if (filterDate) {
        filterDate.addEventListener('change', handleFilter);
    }
    
    // Form submission
    const recordForm = document.getElementById('recordForm');
    if (recordForm) {
        recordForm.addEventListener('submit', handleRecordSubmit);
    }
    
    // Click outside modal to close
    const modal = document.getElementById('recordModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeRecordModal();
            }
        });
    }
}

// Handle search
function handleSearch(e) {
    const query = e.target.value.toLowerCase();
    applyFilters({ search: query });
}

// Handle filters
function handleFilter() {
    const date = document.getElementById('filterDate').value;
    
    // Build URL with query parameters
    const params = new URLSearchParams();
    
    const searchQuery = document.getElementById('searchInput').value;
    if (searchQuery) params.append('search', searchQuery);
    if (date) params.append('date', date);
    
    // Reload page with filters
    window.location.href = `medicalRecords.php${params.toString() ? '?' + params.toString() : ''}`;
}

// Clear all filters
function clearFilters() {
    window.location.href = 'medicalRecords.php';
}

// Apply filters (client-side filtering)
function applyFilters(filters = {}) {
    const recordCards = document.querySelectorAll('.record-card');
    
    recordCards.forEach(card => {
        let show = true;
        
        // Search filter
        if (filters.search) {
            const text = card.textContent.toLowerCase();
            if (!text.includes(filters.search)) {
                show = false;
            }
        }
        
        // Show or hide card
        if (show) {
            card.style.display = 'block';
            card.classList.add('fade-in');
        } else {
            card.style.display = 'none';
        }
    });
    
    // Check if no results
    const visibleCards = document.querySelectorAll('.record-card[style*="display: block"]');
    if (visibleCards.length === 0 && filters.search) {
        showNotification('No records found matching your search', 'info');
    }
}

// Debounce function for search
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Add this inside your DOMContentLoaded or initEventListeners function
const patientSelect = document.getElementById('patientSelect');
if (patientSelect) {
    patientSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const previewDiv = document.getElementById('selectedPatientPreview');
        const previewPhoto = document.getElementById('previewPhoto');
        const previewName = document.getElementById('previewName');
        
        if (!this.value) {
            previewDiv.style.display = 'none';
            return;
        }

        const photo = selectedOption.getAttribute('data-photo');
        const name = selectedOption.getAttribute('data-name');
        const initials = selectedOption.getAttribute('data-initials');

        previewDiv.style.display = 'flex';
        previewName.textContent = name;

        if (photo && photo.trim() !== '' && photo !== '../') {
            previewPhoto.innerHTML = `<img src="${photo}" onerror="this.style.display='none'; this.parentNode.innerHTML='${initials}';">`;
        } else {
            previewPhoto.innerHTML = initials;
        }
    });
}

function openAddRecordModal() {
    const modal = document.getElementById('recordModal');
    const form = document.getElementById('recordForm');
    const previewDiv = document.getElementById('selectedPatientPreview');
    
    if (modal && form) {
        form.reset();
        if (previewDiv) previewDiv.style.display = 'none';
        document.getElementById('modalTitle').innerHTML = '📋 Add Medical Record';
        document.getElementById('recordId').value = '';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

// Close record modal
function closeRecordModal() {
    const modal = document.getElementById('recordModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// View record details
function viewRecord(recordId) {
    const record = allRecords.find(r => r.record_id == recordId);
    
    if (!record) {
        showNotification('Record not found', 'error');
        return;
    }
    
    // Create detailed view modal
    const detailsHTML = `
        <div class="modal active" id="viewRecordModal" onclick="if(event.target === this) closeViewModal()">
            <div class="modal-content large">
                <div class="modal-header">
                    <h2>📋 Medical Record Details</h2>
                    <button class="modal-close" onclick="closeViewModal()">×</button>
                </div>
                <div class="modal-body">
                    <div class="record-details-view">
                        <div class="detail-section">
                            <h3>👤 Patient Information</h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Name</span>
                                    <span class="detail-value">${record.patient_fname} ${record.patient_lname}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Phone</span>
                                    <span class="detail-value">${record.phone || 'N/A'}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Blood Type</span>
                                    <span class="detail-value">${record.blood_type || 'N/A'}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Date of Birth</span>
                                    <span class="detail-value">${record.date_of_birth ? formatDate(record.date_of_birth) : 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h3>📝 Record Information</h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Visit Date</span>
                                    <span class="detail-value">${formatDate(record.visit_date)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Doctor</span>
                                    <span class="detail-value">Dr. ${record.doctor_fname} ${record.doctor_lname}</span>
                                </div>
                                ${record.appointment_id ? `
                                <div class="detail-item">
                                    <span class="detail-label">Appointment ID</span>
                                    <span class="detail-value">#${record.appointment_id}</span>
                                </div>
                                ` : ''}
                                <div class="detail-item">
                                    <span class="detail-label">Record ID</span>
                                    <span class="detail-value">#${record.record_id}</span>
                                </div>
                            </div>
                        </div>
                        
                        ${record.symptoms ? `
                        <div class="detail-section">
                            <h3>💊 Symptoms</h3>
                            <p class="detail-text">${nl2br(record.symptoms)}</p>
                        </div>
                        ` : ''}
                        
                        <div class="detail-section">
                            <h3>🔬 Diagnosis</h3>
                            <p class="detail-text">${nl2br(record.diagnosis)}</p>
                        </div>
                        
                        ${record.prescription ? `
                        <div class="detail-section">
                            <h3>💉 Prescription</h3>
                            <p class="detail-text">${nl2br(record.prescription)}</p>
                        </div>
                        ` : ''}
                        
                        ${record.lab_results ? `
                        <div class="detail-section">
                            <h3>🧪 Lab Results</h3>
                            <p class="detail-text">${nl2br(record.lab_results)}</p>
                        </div>
                        ` : ''}
                        
                        ${record.notes ? `
                        <div class="detail-section">
                            <h3>📝 Additional Notes</h3>
                            <p class="detail-text">${nl2br(record.notes)}</p>
                        </div>
                        ` : ''}
                        
                        <div class="detail-actions">
                            <button class="btn-edit" onclick="editRecord(${recordId})">
                                <span>✏️</span> Edit Record
                            </button>
                            <button class="btn-download" onclick="printRecord(${recordId})">
                                <span>📄</span> Download Medical Record
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', detailsHTML);
    document.body.style.overflow = 'hidden';
}

// Close view modal
function closeViewModal() {
    const modal = document.getElementById('viewRecordModal');
    if (modal) {
        modal.remove();
        document.body.style.overflow = 'auto';
    }
}

// Edit record
function editRecord(recordId) {
    const record = allRecords.find(r => r.record_id == recordId);
    if (!record) return;
    
    // Close view modal if open
    closeViewModal();
    
    // Open edit modal
    const modal = document.getElementById('recordModal');
    const modalTitle = document.getElementById('modalTitle');
    
    if (modal && modalTitle) {
        modalTitle.innerHTML = '✏️ Edit Medical Record';
        
        // Populate form
        document.getElementById('recordId').value = record.record_id;
        document.getElementById('patientSelect').value = record.patient_id;
        document.getElementById('visitDate').value = record.visit_date;
        document.getElementById('symptoms').value = record.symptoms || '';
        document.getElementById('diagnosis').value = record.diagnosis || '';
        document.getElementById('prescription').value = record.prescription || '';
        document.getElementById('labResults').value = record.lab_results || '';
        document.getElementById('notes').value = record.notes || '';
        
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

// Handle record form submission
function handleRecordSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const recordId = formData.get('record_id');
    const isEdit = recordId && recordId !== '';
    
    // Show loading
    showNotification('Saving record...', 'info');
    
    // Convert FormData to JSON
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    
    // Determine endpoint (relative to current page location)
    const endpoint = isEdit ? 'update_medical_record.php' : 'add_medical_record.php';
    
    // Submit data
    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => {
        // Check if response is ok
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification(isEdit ? 'Record updated successfully!' : 'Record added successfully!', 'success');
            closeRecordModal();
            
            // Reload page after short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showNotification(data.message || 'Failed to save record', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred. Please try again.', 'error');
    });
}

// Filter by patient
function filterByPatient(patientId) {
    window.location.href = `medicalRecords.php?patient_id=${patientId}`;
}

// Show notification toast
function showNotification(message, type = 'info') {
    // Remove any existing notifications
    const existingNotifications = document.querySelectorAll('.toast-notification');
    existingNotifications.forEach(n => n.remove());
    
    const notification = document.createElement('div');
    notification.className = `toast-notification toast-${type}`;
    
    const colors = {
        success: '#7FE5B8',
        error: '#FF9090',
        warning: '#FF8C42',
        info: '#6FD9D2'
    };
    
    const icons = {
        success: '✔️',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${colors[type] || colors.info};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 14px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        z-index: 10000;
        animation: slideInRight 0.5s ease;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 250px;
    `;
    
    notification.innerHTML = `
        <span style="font-size: 1.2rem;">${icons[type] || icons.info}</span>
        <span>${message}</span>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transition = 'opacity 0.5s';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 500);
    }, 3000);
}

// Initialize animations
function initAnimations() {
    const fadeElements = document.querySelectorAll('.fade-in');
    fadeElements.forEach((element, index) => {
        element.style.animationDelay = `${index * 0.1}s`;
    });
}

// Helper functions
function formatDate(dateString) {
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

function ucFirst(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
}

function nl2br(text) {
    return text.replace(/\n/g, '<br>');
}

// Add CSS for animations and details view
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    .record-details-view {
        display: flex;
        flex-direction: column;
        gap: 2rem;
    }
    
    .detail-section {
        padding: 1.5rem;
        background: linear-gradient(135deg, #E0F7F7 0%, #CCF3F3 100%);
        border-radius: 16px;
        border-left: 4px solid #6FD9D2;
    }
    
    .detail-section h3 {
        font-size: 1.2rem;
        color: #2D3748;
        margin-bottom: 1rem;
        font-weight: 700;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .detail-item {
        background: white;
        padding: 1rem;
        border-radius: 12px;
        border: 2px solid rgba(111, 217, 210, 0.15);
    }
    
    .detail-label {
        display: block;
        font-size: 0.85rem;
        color: #718096;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }
    
    .detail-value {
        display: block;
        font-size: 1rem;
        color: #2D3748;
        font-weight: 600;
    }
    
    .detail-text {
        background: white;
        padding: 1.25rem;
        border-radius: 12px;
        line-height: 1.6;
        color: #4A5568;
        border: 2px solid rgba(111, 217, 210, 0.15);
    }
    
    .detail-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        padding-top: 1rem;
        border-top: 2px solid rgba(111, 217, 210, 0.15);
    }
    
    @media (max-width: 768px) {
        .detail-grid {
            grid-template-columns: 1fr;
        }
        
        .detail-actions {
            flex-direction: column;
        }
    }
`;
document.head.appendChild(style);

// Download Medical Record as Professional PDF 
function printRecord(recordId) {
    const record = allRecords.find(r => r.record_id == recordId);
    
    if (!record) {
        showNotification('Record not found', 'error');
        return;
    }
    
    // Check if jsPDF is loaded
    if (typeof window.jspdf === 'undefined') {
        showNotification('PDF library not loaded. Please refresh the page.', 'error');
        return;
    }
    
    showNotification('Generating PDF report...', 'info');
    
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'a4');

    // Color Theme
    const brandColor = [0, 96, 230];      // Primary Blue #0060e6
    const lightGray = [240, 242, 245];    // Background Gray
    const darkText = [40, 40, 40];        // Dark Grey Text

    // Header Section
    doc.setFillColor(...brandColor);
    doc.rect(0, 0, 210, 40, 'F');

    doc.setTextColor(255, 255, 255);
    doc.setFontSize(22);
    doc.setFont("helvetica", "bold");
    doc.text('CarePlus', 15, 17);

    doc.setFontSize(10);
    doc.setFont("helvetica", "normal");
    doc.text('Medical Record Report', 15, 24);

    // Metadata
    doc.setFontSize(9);
    doc.text(`Record ID: #${record.record_id}`, 195, 15, { align: 'right' });
    doc.text(`Date: ${new Date().toLocaleDateString()}`, 195, 20, { align: 'right' });

    // Patient Details
    doc.autoTable({
        startY: 45,
        head: [['Category', 'Details']],
        body: [
            ['Patient Name', cleanText(`${record.patient_fname} ${record.patient_lname}`)],
            ['Phone Number', cleanText(record.phone || 'Not specified')],
            ['Blood Type', cleanText(record.blood_type || 'Not specified')],
            ['Date of Birth', record.date_of_birth ? formatDate(record.date_of_birth) : 'Not specified'],
            ['Gender', record.gender ? (record.gender === 'male' ? 'Male' : 'Female') : 'Not specified'],
            ['Visit Date', formatDate(record.visit_date)],
            ['Attending Doctor', cleanText(`Dr. ${record.doctor_fname} ${record.doctor_lname}`)],
            ['Appointment ID', record.appointment_id ? `#${record.appointment_id}` : 'N/A']
        ],
        theme: 'grid',
        headStyles: { 
            fillColor: brandColor, 
            textColor: 255, 
            fontStyle: 'bold',
            lineWidth: 0
        },
        styles: { 
            fontSize: 10, 
            cellPadding: 4, 
            valign: 'middle',
            textColor: 60
        },
        columnStyles: {
            0: { cellWidth: 40, fontStyle: 'bold', fillColor: lightGray },
            1: { cellWidth: 'auto' }
        },
        margin: { left: 15, right: 15 }
    });

    // Medical Info
    let currentY = doc.lastAutoTable.finalY + 15;
    
    // Main Section Title
    doc.setFontSize(14);
    doc.setTextColor(...brandColor);
    doc.setFont("helvetica", "bold");
    doc.text('Medical Information', 15, currentY);
    doc.setDrawColor(...brandColor);
    doc.setLineWidth(0.5);
    doc.line(15, currentY + 2, 195, currentY + 2);
    
    currentY += 10;

    // Helper function to add section with title
    function addMedicalSection(sectionTitle, content) {
        if (!content || !content.trim()) return;

        const cleanedContent = cleanText(content);
        if (!cleanedContent) return;

        // Page Break Logic
        if (currentY > 265) {
            doc.addPage();
            currentY = 20;
        }

        // Section Title
        doc.setFont("helvetica", "bold");
        doc.setTextColor(...brandColor);
        doc.setFontSize(12);
        doc.text(sectionTitle, 15, currentY);
        currentY += 7;

        // Reset to normal text
        doc.setFont("helvetica", "normal");
        doc.setTextColor(...darkText);
        doc.setFontSize(10);

        // Parse content lines
        const lines = cleanedContent.split('\n');
        let medicineCounter = 0; // Counter for medicine numbering
        let lastWasMedicine = false; // Track if last line was a medicine name
        
        lines.forEach((line, index) => {
            if (!line.trim()) return;

            // Page Break Logic
            if (currentY > 270) {
                doc.addPage();
                currentY = 20;
            }

            // Remove any bullet/number prefix for checking
            let lineToCheck = line.trim().replace(/^[\-\*\•]\s*/, '').replace(/^[\d]+\.\s*/, '');
            
            // Check if line contains dosage-related keywords (these are NOT medicine names)
            const isDosageInfo = lineToCheck.includes('Dosage:') ||
                                lineToCheck.includes('Frequency:') ||
                                lineToCheck.includes('Duration:') ||
                                lineToCheck.includes('Quantity:') ||
                                lineToCheck.includes('Instructions:') ||
                                lineToCheck.toLowerCase().includes('take ') ||
                                lineToCheck.toLowerCase().includes('mg') ||
                                lineToCheck.toLowerCase().includes('ml') ||
                                lineToCheck.toLowerCase().includes('times') ||
                                lineToCheck.toLowerCase().includes('daily') ||
                                lineToCheck.toLowerCase().includes('tablets') ||
                                lineToCheck.toLowerCase().includes('capsules') ||
                                lineToCheck.includes(':');

            // For Prescription section, detect medicine names
            // Medicine name = short line, no keywords, comes before dosage info
            const nextLine = index + 1 < lines.length ? lines[index + 1] : '';
            const nextHasDosageInfo = nextLine.toLowerCase().includes('dosage') || 
                                      nextLine.toLowerCase().includes('frequency');
            
            const isMedicineName = sectionTitle === 'Prescription' && 
                                    !isDosageInfo &&
                                    lineToCheck.length < 50 && 
                                    lineToCheck.length > 2 &&
                                    (nextHasDosageInfo || !lastWasMedicine);

            if (isMedicineName) {
                // Medicine Name
                medicineCounter++;
                lastWasMedicine = true;
                
                currentY += 3;
                if (currentY > 270) { doc.addPage(); currentY = 20; }
                
                doc.setFont("helvetica", "bold");
                doc.setTextColor(...darkText);
                doc.setFontSize(10);
                
                // Add numbering before medicine name
                const numberedMedicine = `${medicineCounter}. ${lineToCheck}`;
                const splitText = doc.splitTextToSize(numberedMedicine, 180);
                doc.text(splitText, 15, currentY);
                currentY += (splitText.length * 5) + 1;
                
                // Reset to normal
                doc.setFont("helvetica", "normal");
                
            } else if (isDosageInfo && sectionTitle === 'Prescription') {
                // Dosage/ Instructions
                lastWasMedicine = false;
                
                const splitBullet = doc.splitTextToSize(lineToCheck, 170);
                
                // Draw bullet dot with indent
                doc.text('•', 20, currentY);
                // Draw text indented (more indent for prescription details)
                doc.text(splitBullet, 25, currentY);
                
                currentY += (splitBullet.length * 5) + 2;
                
            } else {

                lastWasMedicine = false;
                
                const hasBullet = line.trim().match(/^[\d]+\./) || line.trim().match(/^[\-\*\•]/);
                
                if (hasBullet || sectionTitle !== 'Prescription') {
                    const splitBullet = doc.splitTextToSize(lineToCheck, 175);
                    doc.text('•', 15, currentY);
                    doc.text(splitBullet, 20, currentY);
                    currentY += (splitBullet.length * 5) + 2;
                } else {
                    const splitText = doc.splitTextToSize(line.trim(), 180);
                    doc.text(splitText, 15, currentY);
                    currentY += (splitText.length * 5) + 2;
                }
            }
        });

        // Add spacing after section
        currentY += 5;
    }

    // Add all medical sections with proper titles
    if (record.symptoms) {
        addMedicalSection('Symptoms', record.symptoms);
    }
    
    if (record.diagnosis) {
        addMedicalSection('Diagnosis', record.diagnosis);
    }
    
    if (record.prescription) {
        addMedicalSection('Prescription', record.prescription);
    }
    
    if (record.lab_results) {
        addMedicalSection('Lab Results', record.lab_results);
    }
    
    if (record.notes) {
        addMedicalSection('Additional Notes', record.notes);
    }

    // Footer
    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        const pageHeight = doc.internal.pageSize.height;
        
        doc.setDrawColor(200);
        doc.line(15, pageHeight - 15, 195, pageHeight - 15);

        doc.setFontSize(8);
        doc.setTextColor(150);
        doc.text('MEDICAL DISCLAIMER: This is a confidential medical record.', 15, pageHeight - 10);
        doc.text('Contains sensitive patient information. Handle with care.', 15, pageHeight - 6);
        doc.text(`Page ${i} of ${pageCount}`, 195, pageHeight - 10, { align: 'right' });
    }

    // Download
    const timestamp = new Date().toISOString().split('T')[0];
    const fileName = `Medical_Record_${record.patient_fname}_${record.patient_lname}_${timestamp}.pdf`;
    doc.save(fileName);
    
    showNotification('Medical record downloaded successfully!', 'success');
}

// Strict Text Clean
function cleanText(text) {
    if (!text) return '';
    
    // Remove Markdown markers from start
    let clean = text.replace(/^[#*\-\s]+/, ''); 
    
    // Remove Markdown bold markers everywhere
    clean = clean.replace(/\*\*/g, '');

    // Allow ONLY letters, numbers, and common punctuation
    clean = clean.replace(/[^a-zA-Z0-9\s.,!?:;()'"\/\-%\+°]/g, '');

    // Trim extra whitespace
    return clean.trim();
}

// Export functions for testing
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        openAddRecordModal,
        closeRecordModal,
        viewRecord,
        editRecord,
        printRecord,
        filterByPatient,
        showNotification
    };
}