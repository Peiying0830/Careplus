document.addEventListener('DOMContentLoaded', function() {
    initializeMedicalRecords();
    setupViewRecordButtons();
});

function initializeMedicalRecords() {
    const filterSelects = document.querySelectorAll('.filters-form select');
    filterSelects.forEach(select => {
        select.addEventListener('change', function() {
            // document.getElementById('filtersForm').submit();
        });
    });

    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('filtersForm').submit();
            }
        });
    }
}

// Setup event listeners for all view buttons
function setupViewRecordButtons() {
    // Remove any existing listeners first
    const oldButtons = document.querySelectorAll('.view-record-btn');
    oldButtons.forEach(btn => {
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
    });

    // Add new listeners using event delegation
    document.body.addEventListener('click', handleViewRecordClick);
}

// Handle view record button clicks
function handleViewRecordClick(e) {
    // Check if clicked element or its parent is a view-record-btn
    const btn = e.target.closest('.view-record-btn');
    
    if (btn) {
        // Stop all event propagation immediately
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        
        const recordId = btn.getAttribute('data-record-id');
        
        if (recordId) {
            console.log('Opening record:', recordId); // Debug log
            viewRecord(parseInt(recordId));
        }
        
        return false; // Extra safety
    }
}

// View record details - NO event parameter
async function viewRecord(recordId) {
    console.log('viewRecord called with ID:', recordId); // Debug log
    
    const modal = document.getElementById('recordModal');
    const modalBody = document.getElementById('modalBody');
    
    if (!modal || !modalBody) {
        console.error('Modal elements not found');
        return;
    }
    
    // Show loading state
    modalBody.innerHTML = `
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>Loading record details...</p>
        </div>
    `;
    modal.classList.add('show');
    
    try {
        const formData = new FormData();
        formData.append('action', 'view_record');
        formData.append('record_id', recordId);
        
        const response = await fetch('medicalRecords.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        console.log('API Response:', data); // Debug log
        
        if (data.success) {
            displayRecordDetails(data.record);
        } else {
            showError(data.message || 'Error loading record details');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('Unable to connect to the server. Please try again.');
    }
}

// Display record details in modal
function displayRecordDetails(record) {
    const modalBody = document.getElementById('modalBody');
    
    if (!modalBody) return;
    
    const html = `
        <div class="record-modal-container">
            <!-- Quick Info Banner -->
            <div class="quick-info-banner">
                <div class="quick-info-item">
                    <span class="quick-info-icon">📅</span>
                    <div>
                        <div class="quick-info-label">Visit Date</div>
                        <div class="quick-info-value">${formatDate(record.appointment_date || record.visit_date)}</div>
                    </div>
                </div>
                <div class="quick-info-item">
                    <span class="quick-info-icon">👨‍⚕️</span>
                    <div>
                        <div class="quick-info-label">Doctor</div>
                        <div class="quick-info-value">Dr. ${escapeHtml(record.doctor_fname)} ${escapeHtml(record.doctor_lname)}</div>
                    </div>
                </div>
                <div class="quick-info-item">
                    <span class="quick-info-icon">🩺</span>
                    <div>
                        <div class="quick-info-label">Specialty</div>
                        <div class="quick-info-value">${escapeHtml(record.specialization || 'General Practice')}</div>
                    </div>
                </div>
            </div>

            <!-- TOP SECTION -->
            <div class="modal-content-grid">
                <!-- Left Column: Diagnosis & Symptoms -->
                <div class="modal-column">
                    <div class="info-card diagnosis-card">
                        <div class="info-card-header">
                            <span class="card-icon">💉</span>
                            <h4>Diagnosis</h4>
                        </div>
                        <div class="info-card-body">
                            <p class="diagnosis-text">${escapeHtml(record.diagnosis || 'No diagnosis provided')}</p>
                        </div>
                    </div>

                    ${record.symptoms ? `
                        <div class="info-card symptoms-card">
                            <div class="info-card-header">
                                <span class="card-icon">🤒</span>
                                <h4>Symptoms Reported</h4>
                            </div>
                            <div class="info-card-body">
                                <div class="symptoms-list">
                                    ${formatSymptomsList(record.symptoms)}
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>

                <!-- Right Column: Lab Results & ADDITIONAL NOTES -->
                <div class="modal-column">
                    ${record.lab_results ? `
                        <div class="info-card lab-card">
                            <div class="info-card-header">
                                <span class="card-icon">🔬</span>
                                <h4>Laboratory Results</h4>
                            </div>
                            <div class="info-card-body">
                                ${formatLabResults(record.lab_results)}
                            </div>
                        </div>
                    ` : ''}

                    ${record.notes ? `
                        <div class="info-card notes-card">
                            <div class="info-card-header">
                                <span class="card-icon">📝</span>
                                <h4>Additional Notes</h4>
                            </div>
                            <div class="info-card-body">
                                <p>${escapeHtml(record.notes)}</p>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>

            <!-- MIDDLE SECTION: PRESCRIPTION (Full Width) -->
            ${record.prescription ? `
                <div class="info-card prescription-card" style="margin: 0.5rem 0;">
                    <div class="info-card-header">
                        <span class="card-icon">💊</span>
                        <h4>Prescription</h4>
                    </div>
                    <div class="info-card-body">
                        ${formatPrescriptionList(record.prescription)}
                    </div>
                </div>
            ` : ''}

            <!-- BOTTOM SECTION: Treatment (Left) / Follow-up (Right) -->
            <div class="modal-content-grid">
                <div class="modal-column">
                    ${record.treatment_plan ? `
                        <div class="info-card treatment-card">
                            <div class="info-card-header">
                                <span class="card-icon">📋</span>
                                <h4>Treatment Plan</h4>
                            </div>
                            <div class="info-card-body">
                                <p>${escapeHtml(record.treatment_plan)}</p>
                            </div>
                        </div>
                    ` : ''}
                </div>

                <div class="modal-column">
                    ${record.follow_up_notes ? `
                        <div class="info-card followup-card">
                            <div class="info-card-header">
                                <span class="card-icon">📌</span>
                                <h4>Follow-up Notes</h4>
                            </div>
                            <div class="info-card-body">
                                <div class="alert-box">
                                    <span class="alert-icon">⚠️</span>
                                    <p>${escapeHtml(record.follow_up_notes)}</p>
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>

            <!-- Buttons & Footer -->
            <div class="modal-actions">
                <button class="btn-download" onclick="downloadRecord(${record.record_id})">
                    <span class="btn-icon">📄</span>
                    <span>Download PDF</span>
                </button>
                <button class="btn-close" onclick="closeModal()">
                    <span class="btn-icon">✕</span>
                    <span>Close</span>
                </button>
            </div>

            <!-- Footer Metadata -->
            <div class="modal-footer-meta">
                <div class="meta-chip">
                    <span class="meta-icon">🆔</span>
                    <span>Record #${record.record_id}</span>
                </div>
                <div class="meta-chip">
                    <span class="meta-icon">📅</span>
                    <span>Created: ${formatDateTime(record.created_at)}</span>
                </div>
                ${record.updated_at !== record.created_at ? `
                    <div class="meta-chip">
                        <span class="meta-icon">🔄</span>
                        <span>Updated: ${formatDateTime(record.updated_at)}</span>
                    </div>
                ` : ''}
            </div>
        </div>
    `;
    
    modalBody.innerHTML = html;
}

// Format symptoms as a list
function formatSymptomsList(symptoms) {
    if (!symptoms) return '';
    
    const lines = symptoms.split('\n').filter(line => line.trim());
    
    if (lines.length === 0) return escapeHtml(symptoms);
    
    return '<ul class="custom-list">' +
        lines.map(line => {
            const cleaned = line.trim().replace(/^\d+\.\s*/, '').replace(/^[-•]\s*/, '');
            return `<li><span class="list-bullet">•</span><span>${escapeHtml(cleaned)}</span></li>`;
        }).join('') +
        '</ul>';
}

// Format prescription as structured list
function formatPrescriptionList(prescription) {
    if (!prescription) return '';
    
    const lines = prescription.split('\n').filter(line => line.trim());
    if (lines.length === 0) return '';

    let html = '<div class="prescription-grouped-grid">';
    let currentCard = null;

    lines.forEach(line => {
        const text = line.trim();
        
        const isDetail = /^[-\u2022*]/.test(text) || 
                         /^(Dosage|Frequency|Duration|Qty|Quantity|Instructions?):/i.test(text);

        if (!isDetail) {
            if (currentCard) {
                html += `
                    <div class="medicine-group-card">
                        <div class="medicine-name">💊 ${currentCard.name}</div>
                        <div class="medicine-details">${currentCard.details}</div>
                    </div>`;
            }
            
            const cleanName = text.replace(/^\d+[\.)]\s*/, '');
            currentCard = { name: cleanName, details: '' };
            
        } else {
            if (!currentCard) {
                currentCard = { name: 'General Instructions', details: '' };
            }
            
            const detailText = text.replace(/^[-\u2022*]\s*/, '');
            currentCard.details += `<div class="detail-row">${escapeHtml(detailText)}</div>`;
        }
    });

    if (currentCard) {
        html += `
            <div class="medicine-group-card">
                <div class="medicine-name">💊 ${currentCard.name}</div>
                <div class="medicine-details">${currentCard.details}</div>
            </div>`;
    }

    html += '</div>';
    return html;
}

// Format lab results
function formatLabResults(labResults) {
    if (!labResults) return '';
    
    const lines = labResults.split('\n').filter(line => line.trim());
    
    if (lines.length === 0) return `<p>${escapeHtml(labResults)}</p>`;
    
    return '<ul class="custom-list lab-list">' +
        lines.map(line => {
            const cleaned = line.trim().replace(/^\d+\.\s*/, '').replace(/^[-•]\s*/, '');
            return `<li><span class="list-bullet">✓</span><span>${escapeHtml(cleaned)}</span></li>`;
        }).join('') +
        '</ul>';
}

// Show error message in modal
function showError(message) {
    const modalBody = document.getElementById('modalBody');
    
    if (!modalBody) return;
    
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 3rem; color: #ef4444;">
            <p style="font-size: 3rem; margin-bottom: 1rem;">⚠️</p>
            <p style="font-size: 1.2rem; font-weight: 600;">${escapeHtml(message)}</p>
            <button class="btn btn-primary" onclick="closeModal()" style="margin-top: 1.5rem;">
                Close
            </button>
        </div>
    `;
}

// Close modal
function closeModal() {
    const modal = document.getElementById('recordModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Download record as PDF
async function downloadRecord(recordId) {
    const btn = document.querySelector(`button[onclick="downloadRecord(${recordId})"]`);
    const originalText = btn ? btn.innerHTML : '';
    if (btn) btn.innerHTML = '<span>⌛</span> Generating...';

    try {
        const formData = new FormData();
        formData.append('action', 'view_record');
        formData.append('record_id', recordId);

        const response = await fetch('medicalRecords.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (!data.success) throw new Error(data.message);
        const record = data.record;

        const element = document.createElement('div');
        element.style.width = '700px'; 
        element.innerHTML = generatePDFTemplate(record);

        const opt = {
            margin:       [10, 10, 10, 10],
            filename:     `Medical_Record_${record.record_id}.pdf`,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { 
                scale: 2, 
                useCORS: true, 
                scrollY: 0,
                letterRendering: true
            },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] } 
        };

        await html2pdf().set(opt).from(element).save();
        showAlert('PDF downloaded successfully!', 'success');

    } catch (error) {
        console.error('Download Error:', error);
        showAlert('Failed to generate PDF.', 'error');
    } finally {
        if (btn) btn.innerHTML = originalText;
    }
}

// Generate PDF template
function generatePDFTemplate(record) {
    const date = formatDate(record.appointment_date || record.visit_date);
    
    return `
        <div style="font-family: 'Helvetica', sans-serif; color: #333; padding: 20px; line-height: 1.5; box-sizing: border-box;">
            <table style="width: 100%; border-bottom: 2px solid #009688; margin-bottom: 20px; padding-bottom: 10px;">
                <tr>
                    <td style="vertical-align: top; width: 60%;">
                        <h1 style="color: #009688; margin: 0; font-size: 22px;">CarePlus</h1>
                        <p style="margin: 5px 0 0; font-size: 11px; color: #666;">
                            Klinik Careclinics, Ipoh, Perak, Malaysia<br>
                            Phone: +60 12-345 6789 | Email: support@careplus.com
                        </p>
                    </td>
                    <td style="vertical-align: top; width: 40%; text-align: right;">
                        <h3 style="margin: 0; color: #555; font-size: 16px;">MEDICAL RECORD</h3>
                        <p style="margin: 5px 0 0; font-size: 12px;">Ref ID: #${record.record_id}</p>
                        <p style="margin: 2px 0 0; font-size: 12px;">Date: ${date}</p>
                    </td>
                </tr>
            </table>

            <div style="background: #f9fafb; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #009688;">
                <strong style="color: #009688; font-size: 12px; display: block; margin-bottom: 5px;">ATTENDING PHYSICIAN</strong>
                <div style="font-size: 14px; font-weight: bold;">Dr. ${escapeHtml(record.doctor_fname)} ${escapeHtml(record.doctor_lname)}</div>
                <div style="font-size: 12px; color: #555;">${escapeHtml(record.specialization)}</div>
                ${record.license_number ? `<div style="font-size: 11px; color: #888;">Lic: ${escapeHtml(record.license_number)}</div>` : ''}
            </div>

            <div style="margin-bottom: 20px;">
                <h4 style="background: #e0f2f1; color: #00695c; padding: 8px 10px; margin: 0 0 10px 0; font-size: 14px;">Diagnosis</h4>
                <div style="padding: 0 10px; font-size: 13px;">${escapeHtml(record.diagnosis || 'N/A')}</div>
            </div>

            ${record.symptoms ? `
            <div style="margin-bottom: 20px;">
                <h4 style="background: #e0f2f1; color: #00695c; padding: 8px 10px; margin: 0 0 10px 0; font-size: 14px;">Symptoms Reported</h4>
                <div style="padding: 0 10px; font-size: 13px;">${formatSymptomsList(record.symptoms)}</div>
            </div>
            ` : ''}

            ${record.prescription ? `
            <div style="margin-bottom: 20px;">
                <h4 style="background: #e0f2f1; color: #00695c; padding: 8px 10px; margin: 0 0 10px 0; font-size: 14px;">Prescription</h4>
                <div style="padding: 0 10px; font-size: 13px;">${formatPrescriptionList(record.prescription)}</div>
            </div>
            ` : ''}

            ${record.lab_results ? `
            <div style="margin-bottom: 20px;">
                <h4 style="background: #e0f2f1; color: #00695c; padding: 8px 10px; margin: 0 0 10px 0; font-size: 14px;">Lab Results</h4>
                <div style="padding: 0 10px; font-size: 13px;">${formatLabResults(record.lab_results)}</div>
            </div>
            ` : ''}

            ${record.treatment_plan ? `
            <div style="margin-bottom: 20px;">
                <h4 style="background: #e0f2f1; color: #00695c; padding: 8px 10px; margin: 0 0 10px 0; font-size: 14px;">Treatment Plan</h4>
                <div style="padding: 0 10px; font-size: 13px;">${escapeHtml(record.treatment_plan)}</div>
            </div>
            ` : ''}

            <div style="margin-top: 40px; border-top: 1px solid #ddd; padding-top: 15px;">
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 60%; font-size: 9px; color: #999; vertical-align: top;">
                            Electronically generated by CarePlus System.<br>
                            This document is confidential.
                        </td>
                        <td style="width: 40%; text-align: center; vertical-align: top;">
                            <div style="height: 40px;"></div>
                            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto;"></div>
                            <div style="font-size: 10px; font-weight: bold; margin-top: 5px;">Physician's Signature</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    `;
}

// Show alert message
function showAlert(message, type = 'info') {
    const existingAlerts = document.querySelectorAll('.temp-alert');
    existingAlerts.forEach(alert => alert.remove());
    
    const alert = document.createElement('div');
    alert.className = `temp-alert`;
    alert.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10001;
        padding: 1rem 1.5rem;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        max-width: 400px;
        animation: slideInRight 0.3s ease;
    `;
    
    if (type === 'success') {
        alert.style.background = '#d4edda';
        alert.style.color = '#155724';
        alert.style.borderLeft = '4px solid #28a745';
        alert.textContent = '✓ ' + message;
    } else if (type === 'error') {
        alert.style.background = '#f8d7da';
        alert.style.color = '#721c24';
        alert.style.borderLeft = '4px solid #dc3545';
        alert.textContent = '✖ ' + message;
    } else {
        alert.style.background = '#d1ecf1';
        alert.style.color = '#0c5460';
        alert.style.borderLeft = '4px solid #17a2b8';
        alert.textContent = 'ℹ️ ' + message;
    }
    
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => alert.remove(), 300);
    }, 4000);
}

// Format date
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

// Format date and time
function formatDateTime(dateTimeString) {
    if (!dateTimeString) return 'N/A';
    const date = new Date(dateTimeString);
    const options = {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return date.toLocaleDateString('en-US', options);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add animation styles
if (!document.getElementById('medical-records-animations')) {
    const style = document.createElement('style');
    style.id = 'medical-records-animations';
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
}

// Export functions for global access
window.viewRecord = viewRecord;
window.closeModal = closeModal;
window.downloadRecord = downloadRecord;