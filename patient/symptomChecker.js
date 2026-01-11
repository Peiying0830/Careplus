document.addEventListener('DOMContentLoaded', function() {
    initializeSymptomChecker();
});

function initializeSymptomChecker() {
    // Character counter
    const symptomsTextarea = document.getElementById('symptoms');
    const charCount = document.getElementById('charCount');
    const charCounter = document.querySelector('.char-counter');
    
    if (symptomsTextarea && charCount) {
        symptomsTextarea.addEventListener('input', function() {
            const length = this.value.length;
            charCount.textContent = length;
            
            if (length > 900) {
                charCounter.classList.add('danger');
                charCounter.classList.remove('warning');
            } else if (length > 800) {
                charCounter.classList.add('warning');
                charCounter.classList.remove('danger');
            } else {
                charCounter.classList.remove('warning', 'danger');
            }
        });
    }
    
    // Common symptoms selection
    const symptomChips = document.querySelectorAll('.symptom-chip');
    symptomChips.forEach(chip => {
        chip.addEventListener('click', function() {
            this.classList.toggle('selected');
            updateSymptomsFromChips();
        });
    });
    
    // Form submission
    const form = document.getElementById('symptomForm');
    if (form) {
        form.addEventListener('submit', handleSubmit);
    }
}

// Update symptoms textarea from selected chips
function updateSymptomsFromChips() {
    const selectedChips = document.querySelectorAll('.symptom-chip.selected');
    const symptomsTextarea = document.getElementById('symptoms');
    
    if (selectedChips.length > 0 && symptomsTextarea) {
        const symptoms = Array.from(selectedChips).map(chip => chip.dataset.symptom);
        const currentText = symptomsTextarea.value.trim();
        
        // Only update if textarea is empty or append symptoms
        if (!currentText) {
            symptomsTextarea.value = 'I am experiencing: ' + symptoms.join(', ') + '. ';
        } else {
            // Check if symptoms are already mentioned
            const symptomsToAdd = symptoms.filter(s => !currentText.toLowerCase().includes(s.toLowerCase()));
            if (symptomsToAdd.length > 0) {
                symptomsTextarea.value = currentText + ' ' + symptomsToAdd.join(', ');
            }
        }
        
        // Trigger input event to update character counter
        symptomsTextarea.dispatchEvent(new Event('input'));
    }
}

function updateHistoryListDynamically(data, originalSymptoms) {
    const historyList = document.getElementById('historyList');
    const emptyState = document.getElementById('emptyState'); // Using ID to match your PHP
    
    // Hide empty state if it's there
    if (emptyState) {
        emptyState.style.display = 'none';
    }

    //  Show the historyList if it was hidden (first check case)
    if (historyList) {
        historyList.style.display = 'flex'; 
        historyList.style.flexDirection = 'column';
    } else {
        // Fallback if container is totally missing
        location.reload(); 
        return;
    }

    // Increment Stats Counters
    const statId = `count-${data.urgency}`;
    const statElement = document.getElementById(statId);
    if (statElement) {
        statElement.textContent = parseInt(statElement.textContent) + 1;
    }

    // Create the New History Item HTML
    const newItem = document.createElement('div');
    newItem.className = 'recent-check-item';
    newItem.style.animation = 'fadeIn 0.5s ease-out'; 

    // Truncate symptoms for display
    const displaySymptoms = originalSymptoms.length > 120 ? 
                            originalSymptoms.substring(0, 120) + '...' : 
                            originalSymptoms;

    newItem.innerHTML = `
        <div class="check-header">
            <div class="check-date">
                📅 Just Now
            </div>
            <span class="urgency-badge urgency-${data.urgency}">
                ${data.urgency.toUpperCase()}
            </span>
        </div>
        ${data.matched_symptom_names ? `
            <div style="margin-bottom: 0.8rem;">
                <small style="color: #666; font-weight: 600;">🔍 Detected: </small>
                <small style="color: #26a69a; font-weight: 500;">
                    ${escapeHtml(data.matched_symptom_names)}
                </small>
            </div>
        ` : ''}
        <div class="check-symptoms">
            ${escapeHtml(displaySymptoms)}
        </div>
        <button class="btn btn-outline btn-sm" onclick="viewCheck(${data.check_id})">
            👁️ View Full Analysis
        </button>
    `;

    // Prepend to the top of the list
    historyList.insertBefore(newItem, historyList.firstChild);
}

// Handle form submission
async function handleSubmit(e) {
    e.preventDefault();
    
    const symptoms = document.getElementById('symptoms').value.trim();
    const duration = document.getElementById('duration').value.trim();
    const age = document.getElementById('age').value;
    const additional = document.getElementById('additional').value.trim();
    
    if (!symptoms) {
        showAlert('Please describe your symptoms', 'error');
        return;
    }
    
    if (!duration) {
        showAlert('Please specify how long you have had these symptoms', 'error');
        return;
    }
    
    // Disable submit button and show processing
    const submitBtn = document.getElementById('submitBtn');
    const originalHTML = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span>⏳</span> Processing...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'check_symptoms');
        formData.append('symptoms', symptoms);
        formData.append('duration', duration);
        formData.append('age', age);
        formData.append('additional_info', additional);
        
        const response = await fetch('symptomChecker.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Display results immediately on the same page
            displayResults(data);
            updateHistoryListDynamically(data, symptoms);

            // Scroll smoothly to the results section
            const resultsContainer = document.getElementById('resultsContainer');
            if (resultsContainer) {
                resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            // Show success alert
            showAlert('✓ Analysis completed successfully!', 'success');
        } else {
            showAlert(data.message || 'Error processing your request', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Unable to connect to the server. Please try again.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHTML;
    }
}

// Display results
function displayResults(data) {
    const resultsContainer = document.getElementById('resultsContainer');
    
    if (!resultsContainer) return;
    
    // Determine urgency styling
    let urgencyClass = 'urgency-routine';
    let urgencyText = '✅ Routine Care';
    let urgencyIcon = 'ℹ️';
    let urgencyMessage = 'You can schedule a regular appointment with your doctor.';
    
    if (data.urgency === 'urgent') {
        urgencyClass = 'urgency-urgent';
        urgencyText = '⚠️ Urgent - Seek Medical Attention Soon';
        urgencyIcon = '⚠️';
        urgencyMessage = 'You should see a doctor within 24-48 hours.';
    } else if (data.urgency === 'emergency') {
        urgencyClass = 'urgency-emergency';
        urgencyText = '🚨 Emergency - Seek Immediate Medical Attention';
        urgencyIcon = '🚨';
        urgencyMessage = 'Call emergency services (999) or go to the nearest emergency room immediately!';
    }
    
    // Show detected scopes count
    let scopesInfo = '';
    if (data.detected_scopes && data.detected_scopes > 0) {
        scopesInfo = `<div style="background: rgba(38, 166, 154, 0.1); padding: 0.8rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.95rem;">
            <strong>🔍 Matched ${data.detected_scopes} symptom scope${data.detected_scopes > 1 ? 's' : ''} from our database</strong>
        </div>`;
    }
    
    // Create results HTML
    const resultsHTML = `
        <div class="results-card">
            <div class="results-header">
                <h3 class="results-title">
                    <span>💊</span> Database Analysis Results
                </h3>
                <div class="urgency-indicator ${urgencyClass}">
                    ${urgencyIcon} ${urgencyText}
                </div>
            </div>
            
            ${scopesInfo}
            
            <div class="urgency-message ${urgencyClass}">
                <strong>${urgencyMessage}</strong>
            </div>
            
            <div class="ai-response">
                ${formatMarkdown(data.response)}
            </div>
            
            <div class="results-disclaimer">
                <strong>⚠️ Important Reminder:</strong> This symptom checker is for informational purposes only and is not a medical diagnosis. It does not replace professional medical advice. If symptoms are serious or concerning, seek medical care immediately.
            </div>
            
            <div class="results-actions">
                <button class="btn btn-primary" onclick="window.location.href='appointment.php'">
                    <span>📅</span> Book Appointment
                </button>
                <button class="btn btn-outline" onclick="resetForm()">
                    <span>🔄</span> New Check
                </button>
            </div>
        </div>
    `;
    
    resultsContainer.innerHTML = resultsHTML;
    resultsContainer.classList.add('show');
}

// Format markdown-style text to HTML
function formatMarkdown(text) {
    let html = text;
    
    // Convert **bold** to <strong>
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    
    // Convert ### headers to h4 with specific styling (sub-sections) - teal color like "Related to your symptoms"
    html = html.replace(/^###\s+(.+)$/gm, function(match, title) {
        return `<h4 style="margin-top: 1.5rem; margin-bottom: 0.8rem; font-size: 1.1rem; color: #0d9488; font-weight: 700;">${title}</h4>`;
    });
    
    // Convert ## headers to h3 with specific styling (main sections with border)
    html = html.replace(/^##\s+(.+)$/gm, function(match, title) {
        return `<h3 style="margin-top: 2rem; margin-bottom: 1rem; font-size: 1.3rem; color: #1f2937; padding-top: 1.5rem; border-top: 2px solid #e5e7eb; font-weight: 600;">${title}</h3>`;
    });
    
    // Convert # headers to h2 and hide them
    html = html.replace(/^#\s+(.+)$/gm, '<h2 style="display:none;">$1</h2>');
    
    // Convert bullet points with * (keep them as list items for now)
    html = html.replace(/^\*\s+(.+)$/gm, '<li>$1</li>');
    
    // Wrap consecutive list items in ul tags
    html = html.replace(/(<li>.*?<\/li>\s*)+/gs, function(match) {
        return '<ul style="margin: 1rem 0; padding-left: 1.5rem; line-height: 1.8;">' + match + '</ul>';
    });
    
    // Convert line breaks to paragraphs
    const lines = html.split('\n');
    let result = [];
    let inParagraph = false;
    let paragraphContent = '';
    
    for (let line of lines) {
        line = line.trim();
        
        if (!line) {
            if (inParagraph && paragraphContent) {
                result.push(`<p style="margin: 0.8rem 0; line-height: 1.6;">${paragraphContent}</p>`);
                paragraphContent = '';
                inParagraph = false;
            }
            continue;
        }
        
        if (line.startsWith('<h') || line.startsWith('<ul') || line.startsWith('<ol') || line.startsWith('<li')) {
            if (inParagraph && paragraphContent) {
                result.push(`<p style="margin: 0.8rem 0; line-height: 1.6;">${paragraphContent}</p>`);
                paragraphContent = '';
                inParagraph = false;
            }
            result.push(line);
        } else {
            if (inParagraph) {
                paragraphContent += ' ' + line;
            } else {
                paragraphContent = line;
                inParagraph = true;
            }
        }
    }
    
    if (inParagraph && paragraphContent) {
        result.push(`<p style="margin: 0.8rem 0; line-height: 1.6;">${paragraphContent}</p>`);
    }
    
    html = result.join('\n');
    
    return html;
}

// View detailed check
async function viewCheck(checkId) {
    const modal = document.getElementById('detailModal');
    const modalBody = document.getElementById('modalBody');
    
    if (!modal || !modalBody) return;
    
    modalBody.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="spinner"></div><p>Loading details...</p></div>';
    modal.classList.add('show');
    
    try {
        const formData = new FormData();
        formData.append('action', 'view_check');
        formData.append('check_id', checkId);
        
        const response = await fetch('symptomChecker.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.success) {
            const check = data.check;
            
            let urgencyClass = 'urgency-routine';
            let urgencyText = 'Routine Care';
            let urgencyIcon = '✅';
            
            if (check.urgency_level === 'urgent') {
                urgencyClass = 'urgency-urgent';
                urgencyText = 'Urgent Care';
                urgencyIcon = '⚠️';
            } else if (check.urgency_level === 'emergency') {
                urgencyClass = 'urgency-emergency';
                urgencyText = 'Emergency';
                urgencyIcon = '🚨';
            }
            
            modalBody.innerHTML = `
                <div style="margin-bottom: 25px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 1rem;">
                        <span class="urgency-indicator ${urgencyClass}">
                            ${urgencyIcon} ${urgencyText}
                        </span>
                        <span style="color: #6b7280; font-size: 0.95rem;">
                            📅 ${formatDateTime(check.created_at)}
                        </span>
                    </div>
                    
                    <div class="detail-info-box">
                        <strong>🩺 Symptoms Described:</strong>
                        <p>${escapeHtml(check.symptoms)}</p>
                    </div>
                    
                    ${check.duration ? `
                        <div class="detail-info-box">
                            <strong>⏱️ Duration:</strong>
                            <p>${escapeHtml(check.duration)}</p>
                        </div>
                    ` : ''}
                    
                    ${check.age ? `
                        <div class="detail-info-box">
                            <strong>👤 Age:</strong>
                            <p>${check.age} years old</p>
                        </div>
                    ` : ''}
                    
                    ${check.additional_info ? `
                        <div class="detail-info-box">
                            <strong>📋 Additional Information:</strong>
                            <p>${escapeHtml(check.additional_info)}</p>
                        </div>
                    ` : ''}
                </div>
                
                <div style="border-top: 2px solid #e5e7eb; padding-top: 25px;">
                    <h3 style="color: #1f2937; margin-bottom: 20px; font-size: 1.3rem;">
                        💊 Database Analysis
                    </h3>
                    <div class="ai-response">
                        ${formatMarkdown(check.response)}
                    </div>
                </div>
                
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="window.location.href='appointment.php'" style="flex: 1; min-width: 200px;">
                        📅 Book an Appointment
                    </button>
                    <button class="btn-printRecord" id="btn-download-pdf" onclick="downloadCheckPDF(${check.id})" style="flex: 1; min-width: 200px;">
                        📄 Download PDF Report
                    </button>
                </div>
            `;
        } else {
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #ef4444;">
                    <p style="font-size: 3rem; margin-bottom: 15px;">⚠️</p>
                    <p style="font-size: 1.2rem; font-weight: 600;">${escapeHtml(data.message || 'Error loading details')}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #ef4444;">
                <p style="font-size: 3rem; margin-bottom: 15px;">❌</p>
                <p style="font-size: 1.2rem; font-weight: 600;">Network error. Please try again.</p>
            </div>
        `;
    }
}

// DownloadCheckPDF
async function downloadCheckPDF(checkId) {
    if (typeof window.jspdf === 'undefined') {
        showAlert('PDF library not loaded. Please refresh.', 'error');
        return;
    }

    const btn = document.getElementById('btn-download-pdf');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<span>⏳</span> Generating...';
        btn.disabled = true;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'view_check');
        formData.append('check_id', checkId);

        const response = await fetch('symptomChecker.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (!data.success) throw new Error(data.message);

        const check = data.check;
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');

        // --- THEME COLORS ---
        const brandColor = [0, 166, 126];    // Medical Green
        const lightGray = [240, 242, 245];   // Background Gray
        const darkText = [40, 40, 40];       // Dark Grey Text
        
        // Urgency Settings
        let urgencyColor = [25, 135, 84]; 
        let urgencyText = "ROUTINE CARE RECOMMENDED";
        
        if (check.urgency_level === 'urgent') {
            urgencyColor = [234, 88, 12]; 
            urgencyText = "URGENT CARE REQUIRED";
        } else if (check.urgency_level === 'emergency') {
            urgencyColor = [220, 53, 69]; 
            urgencyText = "🚨 EMERGENCY - SEEK HELP IMMEDIATELY";
        }

        // --- 1. HEADER SECTION ---
        doc.setFillColor(...brandColor);
        doc.rect(0, 0, 210, 40, 'F');

        doc.setTextColor(255, 255, 255);
        doc.setFontSize(22);
        doc.setFont("helvetica", "bold");
        doc.text('CarePlus', 15, 17);

        doc.setFontSize(10);
        doc.setFont("helvetica", "normal");
        doc.text('Symptom Assessment Record', 15, 24);

        // Metadata
        doc.setFontSize(9);
        doc.text(`Report ID: #${check.id}`, 195, 15, { align: 'right' });
        doc.text(`Date: ${new Date(check.created_at).toLocaleDateString()}`, 195, 20, { align: 'right' });

        // --- 2. URGENCY BANNER ---
        doc.setFillColor(...urgencyColor);
        doc.roundedRect(15, 45, 180, 14, 2, 2, 'F');
        
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(11);
        doc.setFont("helvetica", "bold");
        doc.text(urgencyText, 105, 53, { align: 'center' });

        // --- 3. PATIENT DETAILS ---
        // We clean the input data here too
        doc.autoTable({
            startY: 65,
            head: [['Category', 'Details']],
            body: [
                ['Patient Age', (check.age ? check.age + ' Years Old' : 'Not specified')],
                ['Duration', cleanText(check.duration || 'Not specified')],
                ['Symptoms', cleanText(check.symptoms)],
                ['Additional', cleanText(check.additional_info || 'None')]
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

        // --- 4. ANALYSIS & RECOMMENDATIONS ---
        let currentY = doc.lastAutoTable.finalY + 15;
        
        // Main Section Title
        doc.setFontSize(14);
        doc.setTextColor(...brandColor);
        doc.setFont("helvetica", "bold");
        doc.text('Analysis & Recommendations', 15, currentY);
        doc.setDrawColor(...brandColor);
        doc.setLineWidth(0.5);
        doc.line(15, currentY + 2, 195, currentY + 2);
        
        currentY += 10;

        // Clean raw text and split by new lines
        const rawText = check.response || '';
        const lines = rawText.split('\n');
        
        doc.setFontSize(10);
        doc.setTextColor(...darkText);

        lines.forEach(line => {
            if (!line.trim()) return; // Skip empty lines

            // 1. Identify Line Type BEFORE cleaning (based on Markdown chars)
            const isHeader = line.startsWith('#');
            const isBullet = line.trim().startsWith('*') || line.trim().startsWith('-');

            // 2. Clean the content (Whitelist approach)
            // This removes ALL weird symbols but keeps text
            let content = cleanText(line);

            if (!content) return; 

            // Page Break Logic
            if (currentY > 270) {
                doc.addPage();
                currentY = 20;
            }

            if (isHeader) {
                // --- HEADERS ---
                currentY += 5;
                if (currentY > 270) { doc.addPage(); currentY = 20; }
                
                doc.setFont("helvetica", "bold");
                doc.setTextColor(...brandColor); // Green Headers
                doc.setFontSize(12);
                doc.text(content, 15, currentY);
                
                // Reset to normal
                doc.setFont("helvetica", "normal");
                doc.setTextColor(...darkText);
                doc.setFontSize(10);
                currentY += 6;
                
            } else if (isBullet) {
                // --- BULLET POINTS ---
                // Manually add the bullet circle
                const bulletContent = content; 
                const splitBullet = doc.splitTextToSize(bulletContent, 175);
                
                // Draw bullet dot
                doc.text('•', 15, currentY);
                // Draw text indented
                doc.text(splitBullet, 20, currentY);
                
                currentY += (splitBullet.length * 5) + 2;
                
            } else {
                // --- NORMAL PARAGRAPHS ---
                const splitText = doc.splitTextToSize(content, 180);
                doc.text(splitText, 15, currentY);
                currentY += (splitText.length * 5) + 2;
            }
        });

        // --- 5. FOOTER (With hardcoded clean text) ---
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            const pageHeight = doc.internal.pageSize.height;
            
            doc.setDrawColor(200);
            doc.line(15, pageHeight - 15, 195, pageHeight - 15);

            doc.setFontSize(8);
            doc.setTextColor(150);
            // Hardcoded text ensures no "garbage" symbols appear in footer
            doc.text('MEDICAL DISCLAIMER: Generated by AI. Not a medical diagnosis.', 15, pageHeight - 10);
            doc.text('Always consult a doctor for professional advice.', 15, pageHeight - 6);
            doc.text(`Page ${i} of ${pageCount}`, 195, pageHeight - 10, { align: 'right' });
        }

        doc.save(`CarePlus_Report_${check.id}.pdf`);
        showAlert('✓ PDF Report downloaded successfully!', 'success');

    } catch (error) {
        console.error(error);
        showAlert('Error generating PDF.', 'error');
    } finally {
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
}

//Strict Text CLeaner
function cleanText(text) {
    if (!text) return '';
    
    // Remove #, *, - only from the Start of the string
    let clean = text.replace(/^[#*\-\s]+/, ''); 
    
    // Remove Markdown bold markers everywhere
    clean = clean.replace(/\*\*/g, '');

    // Allow ONLY letters, numbers, and common punctuation.
    clean = clean.replace(/[^a-zA-Z0-9\s.,!?:;()'"\/\-%\+°]/g, '');

    // Trim extra whitespace
    return clean.trim();
}

// Close modal
function closeModal() {
    const modal = document.getElementById('detailModal');
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

// Reset form
function resetForm() {
    const form = document.getElementById('symptomForm');
    if (form) {
        form.reset();
    }
    
    // Clear selected chips
    document.querySelectorAll('.symptom-chip.selected').forEach(chip => {
        chip.classList.remove('selected');
    });
    
    // Hide results
    const resultsContainer = document.getElementById('resultsContainer');
    if (resultsContainer) {
        resultsContainer.classList.remove('show');
        resultsContainer.innerHTML = '';
    }
    
    // Update character counter
    const charCount = document.getElementById('charCount');
    if (charCount) {
        charCount.textContent = '0';
    }
    
    const charCounter = document.querySelector('.char-counter');
    if (charCounter) {
        charCounter.classList.remove('warning', 'danger');
    }
    
    // Focus on symptoms textarea
    const symptomsTextarea = document.getElementById('symptoms');
    if (symptomsTextarea) {
        symptomsTextarea.focus();
    }
    
    // Scroll to top of form
    const symptomForm = document.getElementById('symptomForm');
    if (symptomForm) {
        symptomForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Show alert message
function showAlert(message, type = 'info') {
    // Remove existing alerts
    const existingAlerts = document.querySelectorAll('.temp-alert');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create alert element
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} temp-alert`;
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
    } else if (type === 'error') {
        alert.style.background = '#f8d7da';
        alert.style.color = '#721c24';
        alert.style.borderLeft = '4px solid #dc3545';
    }
    
    alert.textContent = message;
    document.body.appendChild(alert);
    
    // Auto-remove after 4 seconds
    setTimeout(() => {
        alert.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => alert.remove(), 300);
    }, 4000);
}

// Format date and time
function formatDateTime(dateString) {
    const date = new Date(dateString);
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

// Add animation styles if not present
if (!document.getElementById('symptom-checker-animations')) {
    const style = document.createElement('style');
    style.id = 'symptom-checker-animations';
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
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
}

// Export functions for global access
window.viewCheck = viewCheck;
window.closeModal = closeModal;
window.resetForm = resetForm;
window.downloadCheckPDF = downloadCheckPDF;