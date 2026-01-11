let currentPrescriptionId = null;

// Search prescriptions
function searchPrescriptions() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const prescriptionCards = document.querySelectorAll('.prescription-card');
    
    prescriptionCards.forEach(card => {
        const text = card.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

// View prescription details in modal
async function viewPrescriptionDetails(prescriptionId) {
    currentPrescriptionId = prescriptionId;
    const modal = document.getElementById('prescriptionModal');
    const modalBody = document.getElementById('modalBody');
    
    try {
        // Fetch prescription details
        const response = await fetch(`get_prescription_details.php?prescription_id=${prescriptionId}`);
        const data = await response.json();
        
        if (!data.success) {
            showNotification('Failed to load prescription details', 'error');
            return;
        }
        
        const prescription = data.prescription;
        
        // Build modal content
        let modalContent = `
            <div class="prescription-details">
                <!-- Prescription Info -->
                <div class="detail-section">
                    <h3>📋 Prescription Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="label">Prescription #:</span>
                            <span class="value">Rx #${String(prescription.prescription_id).padStart(6, '0')}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Date Issued:</span>
                            <span class="value">${new Date(prescription.prescription_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </div>
                    </div>
                </div>
                
                <!-- Doctor Info -->
                <div class="detail-section">
                    <h3>👨‍⚕️ Prescribing Doctor</h3>
                    <div class="doctor-info-grid">
                        <div class="doctor-image-container">
                            ${prescription.doctor_profile_picture ? `
                                <img src="../${prescription.doctor_profile_picture}" 
                                     alt="Dr. ${prescription.doctor_name}" 
                                     class="doctor-modal-image"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="doctor-fallback-avatar" style="display: none;">
                                    ${prescription.doctor_gender === 'female' ? '👩‍⚕️' : '👨‍⚕️'}
                                </div>
                            ` : `
                                <div class="doctor-fallback-avatar">
                                    ${prescription.doctor_gender === 'female' ? '👩‍⚕️' : '👨‍⚕️'}
                                </div>
                            `}
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="label">Doctor:</span>
                                <span class="value">Dr. ${prescription.doctor_name}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Specialization:</span>
                                <span class="value">${prescription.specialization}</span>
                            </div>
                            <div class="info-item">
                                <span class="label">Contact:</span>
                                <span class="value">${prescription.doctor_phone}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Diagnosis -->
                <div class="detail-section diagnosis">
                    <h3>🔍 Diagnosis</h3>
                    <p>${prescription.diagnosis}</p>
                </div>
                
                <!-- Medications -->
                <div class="detail-section medications">
                    <h3>💊 Prescribed Medications</h3>
                    <div class="medications-table">
                        ${prescription.medications.map((med, index) => `
                            <div class="med-card">
                                <div class="med-number">${index + 1}</div>
                                <div class="med-content">
                                    <div class="med-header">
                                        <h4>${med.medication_name}</h4>
                                        <span class="dosage-badge">${med.dosage}</span>
                                    </div>
                                    <div class="med-info">
                                        <div class="med-row">
                                            <span class="icon">⏰</span>
                                            <span class="label">Frequency:</span>
                                            <span class="value">${med.frequency}</span>
                                        </div>
                                        <div class="med-row">
                                            <span class="icon">📆</span>
                                            <span class="label">Duration:</span>
                                            <span class="value">${med.duration}</span>
                                        </div>
                                        ${med.instructions ? `
                                            <div class="med-row instructions">
                                                <span class="icon">📝</span>
                                                <span class="label">Instructions:</span>
                                                <span class="value">${med.instructions}</span>
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                
                ${prescription.notes ? `
                    <div class="detail-section notes">
                        <h3>📌 Additional Notes</h3>
                        <p>${prescription.notes.replace(/\n/g, '<br>')}</p>
                    </div>
                ` : ''}
            </div>
        `;
        
        modalBody.innerHTML = modalContent;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
    } catch (error) {
        console.error('Error loading prescription:', error);
        showNotification('Failed to load prescription details', 'error');
    }
}

// Download prescription as PDF
async function downloadPrescription(prescriptionId) {
    try {
        showNotification('Generating PDF...', 'info');
        
        // Fetch prescription details
        const response = await fetch(`get_prescription_details.php?prescription_id=${prescriptionId}`);
        const data = await response.json();
        
        if (!data.success) {
            showNotification('Failed to fetch prescription details', 'error');
            return;
        }
        
        const prescription = data.prescription;
        
        // Generate PDF using jsPDF
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        // Colors
        const primaryColor = [156, 39, 176]; // Purple
        const textColor = [26, 26, 26];
        const grayColor = [102, 102, 102];
        
        // Header
        doc.setFillColor(...primaryColor);
        doc.rect(0, 0, 210, 45, 'F');
        
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(26);
        doc.setFont(undefined, 'bold');
        doc.text('MEDICAL PRESCRIPTION', 105, 20, { align: 'center' });
        
        doc.setFontSize(12);
        doc.setFont(undefined, 'normal');
        doc.text('Official Medical Document', 105, 30, { align: 'center' });
        doc.text(`Rx #${String(prescription.prescription_id).padStart(6, '0')}`, 105, 37, { align: 'center' });
        
        let yPos = 55;
        
        // Prescription info box
        doc.setFillColor(245, 245, 245);
        doc.rect(15, yPos, 180, 20, 'F');
        
        doc.setTextColor(...textColor);
        doc.setFontSize(10);
        doc.setFont(undefined, 'bold');
        doc.text('Date Issued:', 20, yPos + 8);
        doc.setFont(undefined, 'normal');
        doc.text(new Date(prescription.prescription_date).toLocaleDateString(), 50, yPos + 8);
        
        doc.setFont(undefined, 'bold');
        doc.text('Doctor:', 120, yPos + 8);
        doc.setFont(undefined, 'normal');
        doc.text(`Dr. ${prescription.doctor_name}`, 140, yPos + 8);
        
        doc.setFont(undefined, 'bold');
        doc.text('Specialization:', 20, yPos + 15);
        doc.setFont(undefined, 'normal');
        doc.text(prescription.specialization, 55, yPos + 15);
        
        yPos += 30;
        
        // Patient info
        doc.setFontSize(12);
        doc.setFont(undefined, 'bold');
        doc.text('Patient Information', 15, yPos);
        yPos += 8;
        
        doc.setFontSize(10);
        doc.setFont(undefined, 'normal');
        doc.text(`Name: ${prescription.patient_name}`, 20, yPos);
        yPos += 7;
        doc.text(`Email: ${prescription.patient_email}`, 20, yPos);
        yPos += 10;
        
        // Diagnosis
        doc.setFillColor(255, 243, 224);
        doc.rect(15, yPos, 180, 15, 'F');
        
        doc.setFontSize(11);
        doc.setFont(undefined, 'bold');
        doc.text('Diagnosis:', 20, yPos + 6);
        doc.setFont(undefined, 'normal');
        doc.text(prescription.diagnosis, 20, yPos + 11);
        
        yPos += 25;
        
        // Medications header
        doc.setFontSize(13);
        doc.setFont(undefined, 'bold');
        doc.setTextColor(...primaryColor);
        doc.text('Prescribed Medications', 15, yPos);
        yPos += 8;
        
        // Draw medications
        doc.setTextColor(...textColor);
        prescription.medications.forEach((med, index) => {
            // Check if we need a new page
            if (yPos > 250) {
                doc.addPage();
                yPos = 20;
            }
            
            // Medication box
            doc.setFillColor(243, 229, 245);
            doc.rect(15, yPos, 180, 35, 'F');
            
            // Medication number
            doc.setFillColor(...primaryColor);
            doc.circle(25, yPos + 8, 5, 'F');
            doc.setTextColor(255, 255, 255);
            doc.setFontSize(10);
            doc.setFont(undefined, 'bold');
            doc.text(String(index + 1), 25, yPos + 10, { align: 'center' });
            
            // Medication name and dosage
            doc.setTextColor(...textColor);
            doc.setFontSize(11);
            doc.text(med.medication_name, 35, yPos + 8);
            
            doc.setFillColor(38, 166, 154);
            doc.roundedRect(140, yPos + 3, 50, 8, 2, 2, 'F');
            doc.setTextColor(255, 255, 255);
            doc.setFontSize(9);
            doc.text(med.dosage, 165, yPos + 8, { align: 'center' });
            
            // Details
            doc.setTextColor(...grayColor);
            doc.setFontSize(9);
            doc.setFont(undefined, 'normal');
            doc.text(`Frequency: ${med.frequency}`, 35, yPos + 16);
            doc.text(`Duration: ${med.duration}`, 35, yPos + 22);
            
            if (med.instructions) {
                doc.text(`Instructions: ${med.instructions}`, 35, yPos + 28);
            }
            
            yPos += 40;
        });
        
        // Notes if available
        if (prescription.notes) {
            if (yPos > 240) {
                doc.addPage();
                yPos = 20;
            }
            
            doc.setFillColor(227, 242, 253);
            const noteHeight = 20 + (prescription.notes.length / 80) * 5;
            doc.rect(15, yPos, 180, noteHeight, 'F');
            
            doc.setTextColor(21, 101, 192);
            doc.setFontSize(11);
            doc.setFont(undefined, 'bold');
            doc.text('Additional Notes:', 20, yPos + 7);
            
            doc.setFont(undefined, 'normal');
            doc.setFontSize(9);
            const notesLines = doc.splitTextToSize(prescription.notes, 170);
            doc.text(notesLines, 20, yPos + 13);
            
            yPos += noteHeight + 5;
        }
        
        // Footer
        doc.setTextColor(...grayColor);
        doc.setFontSize(8);
        doc.text('This is a computer-generated prescription and is valid without signature.', 105, 285, { align: 'center' });
        doc.text(`Generated on: ${new Date().toLocaleString()}`, 105, 290, { align: 'center' });
        
        // Save PDF
        const filename = `Prescription_${String(prescription.prescription_id).padStart(6, '0')}_${Date.now()}.pdf`;
        doc.save(filename);
        
        showNotification('Prescription downloaded successfully!', 'success');
        
    } catch (error) {
        console.error('Error downloading prescription:', error);
        showNotification('Failed to download prescription', 'error');
    }
}

// Download current prescription from modal
function downloadCurrentPrescription() {
    if (currentPrescriptionId) {
        downloadPrescription(currentPrescriptionId);
    }
}

// Print prescription
function printPrescription(prescriptionId) {
    // Open print dialog
    window.print();
}

// Close modal
function closeModal() {
    const modal = document.getElementById('prescriptionModal');
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
    currentPrescriptionId = null;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('prescriptionModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 2000;
        background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : '#2196f3'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideInRight 0.3s ease;
        max-width: 400px;
    `;
    
    const icons = {
        success: '✅',
        error: '❌',
        info: 'ℹ️',
        warning: '⚠️'
    };
    
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span style="font-size: 1.5rem;">${icons[type] || icons.info}</span>
            <span style="font-weight: 500;">${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Add animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .detail-section {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
    
    .detail-section h3 {
        font-size: 1.2rem;
        color: #1a1a1a;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    .info-item {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .info-item .label {
        font-size: 0.85rem;
        color: #666;
        font-weight: 600;
    }
    
    .info-item .value {
        font-size: 1rem;
        color: #1a1a1a;
        font-weight: 500;
    }
    
    .detail-section.diagnosis {
        background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    }
    
    .detail-section.diagnosis p {
        color: #5d4037;
        line-height: 1.6;
    }
    
    .detail-section.medications {
        background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
    }
    
    .medications-table {
        display: grid;
        gap: 1rem;
    }
    
    .med-card {
        background: white;
        border-radius: 12px;
        padding: 1.25rem;
        display: flex;
        gap: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    
    .med-number {
        width: 40px;
        height: 40px;
        min-width: 40px;
        background: linear-gradient(135deg, #9c27b0 0%, #ce93d8 100%);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
    }
    
    .med-content {
        flex: 1;
    }
    
    .med-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #f0f0f0;
    }
    
    .med-header h4 {
        font-size: 1.1rem;
        color: #1a1a1a;
    }
    
    .dosage-badge {
        background: linear-gradient(135deg, #26a69a 0%, #6fd4ca 100%);
        color: white;
        padding: 0.4rem 1rem;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
    }
    
    .med-info {
        display: grid;
        gap: 0.75rem;
    }
    
    .med-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .med-row .icon {
        font-size: 1.1rem;
    }
    
    .med-row .label {
        font-weight: 600;
        color: #666;
        min-width: 100px;
    }
    
    .med-row .value {
        color: #1a1a1a;
    }
    
    .med-row.instructions {
        background: #f8f9fa;
        padding: 0.75rem;
        border-radius: 8px;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .detail-section.notes {
        background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    }
    
    .detail-section.notes p {
        color: #1565c0;
        line-height: 1.6;
    }
`;
document.head.appendChild(style);

// Initialize animations
function initAnimations() {
    const cards = document.querySelectorAll('.fade-in');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Prescription management initialized');
    initAnimations();
});