(function() {
    'use strict';

    // State management
    let currentFilter = {
        status: 'all',
        date: '',
        search: ''
    };

    let videoStream = null;
    let isScanning = false;

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        initializeFilters();
        initializeQRScanner();
        initializeAppointmentActions();
        initializeModal();
        replaceIconsWithEmojis();
    });

    // Replace all Lucide icons with emojis (except QR codes)
    function replaceIconsWithEmojis() {
        // User icons
        document.querySelectorAll('[data-lucide="user-circle"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">👤</span>';
        });

        // Phone icons
        document.querySelectorAll('[data-lucide="phone"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">📞</span>';
        });

        // Activity/Health icons
        document.querySelectorAll('[data-lucide="activity"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">💓</span>';
        });

        // Status icons
        document.querySelectorAll('[data-lucide="check-circle"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">✅</span>';
        });

        document.querySelectorAll('[data-lucide="clock"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">⏰</span>';
        });

        document.querySelectorAll('[data-lucide="x-circle"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">❌</span>';
        });

        // Chevron icons
        document.querySelectorAll('[data-lucide="chevron-right"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">➡️</span>';
        });

        // Calendar icons
        document.querySelectorAll('[data-lucide="calendar"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">📅</span>';
        });

        // Clipboard icons
        document.querySelectorAll('[data-lucide="clipboard"]').forEach(el => {
            el.outerHTML = '<span class="emoji-icon">📋</span>';
        });
    }

    // QR Scanner Functionality
    function initializeQRScanner() {
        const qrScanBtn = document.getElementById('qrScanBtn');
        const qrScannerSection = document.getElementById('qrScannerSection');
        const qrCloseBtn = document.getElementById('qrCloseBtn');
        const startCameraBtn = document.querySelector('.btn-start-camera');

        if (qrScanBtn) {
            qrScanBtn.addEventListener('click', function() {
                if (qrScannerSection.style.display === 'none') {
                    qrScannerSection.style.display = 'block';
                    qrScannerSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    qrScannerSection.style.display = 'none';
                    stopCamera();
                }
            });
        }

        if (qrCloseBtn) {
            qrCloseBtn.addEventListener('click', function() {
                qrScannerSection.style.display = 'none';
                stopCamera();
            });
        }

        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', startCamera);
        }
    }

    async function startCamera() {
        const placeholder = document.querySelector('.qr-scanner-placeholder');
        const startBtn = document.querySelector('.btn-start-camera');
        const instruction = document.querySelector('.qr-instruction');

        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showNotification('Camera access is not supported in this browser', 'error');
                return;
            }

            videoStream = await navigator.mediaDevices.getUserMedia({
                video: { 
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            });

            const video = document.createElement('video');
            video.id = 'qr-video';
            video.style.width = '100%';
            video.style.height = '100%';
            video.style.objectFit = 'cover';
            video.style.borderRadius = '24px';
            video.autoplay = true;
            video.playsInline = true;

            placeholder.innerHTML = '';
            placeholder.style.border = 'none';
            placeholder.appendChild(video);

            video.srcObject = videoStream;

            startBtn.innerHTML = '⏹️ Stop Camera';
            startBtn.onclick = stopCamera;
            instruction.textContent = 'Position the QR code within the frame';
            isScanning = true;

            startQRScanning(video);
            showNotification('Camera started successfully', 'success');

        } catch (error) {
            console.error('Camera error:', error);
            
            let errorMessage = 'Failed to access camera';
            if (error.name === 'NotAllowedError') {
                errorMessage = 'Camera access denied. Please allow camera permissions.';
            } else if (error.name === 'NotFoundError') {
                errorMessage = 'No camera found on this device';
            } else if (error.name === 'NotReadableError') {
                errorMessage = 'Camera is already in use by another application';
            }
            
            showNotification(errorMessage, 'error');
        }
    }

    function stopCamera() {
        const placeholder = document.querySelector('.qr-scanner-placeholder');
        const startBtn = document.querySelector('.btn-start-camera');
        const instruction = document.querySelector('.qr-instruction');
        const video = document.getElementById('qr-video');

        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }

        if (video) {
            video.remove();
        }

        placeholder.innerHTML = '<i data-lucide="qr-code" class="qr-icon-large"></i>';
        placeholder.style.border = '3px dashed var(--primary-blue)';
        
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        startBtn.innerHTML = '📷 Start Camera';
        startBtn.onclick = startCamera;
        instruction.textContent = 'Point camera at patient\'s QR code to check them in';
        isScanning = false;

        showNotification('Camera stopped', 'info');
    }

    function startQRScanning(video) {
        if (typeof jsQR === 'undefined') {
            console.warn('jsQR library not loaded. QR scanning will not work.');
            showNotification('QR scanning library not available', 'warning');
            return;
        }

        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');

        function scan() {
            if (!isScanning || !video.videoWidth) {
                if (isScanning) {
                    requestAnimationFrame(scan);
                }
                return;
            }

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);

            if (code) {
                handleQRCode(code.data);
            } else {
                requestAnimationFrame(scan);
            }
        }

        video.addEventListener('loadedmetadata', () => {
            scan();
        });
    }

    function handleQRCode(qrData) {
        // Clean the data 
        const cleanedQrData = qrData.trim();
        console.log('QR Code detected:', cleanedQrData);
        
        isScanning = false; // Pause scanner
        playBeep();

        // Find the card using an Attribute Selector 
        // We use filter to find the card to avoid CSS selector syntax errors
        const allCards = Array.from(document.querySelectorAll('.appointment-card-new'));
        const appointmentCard = allCards.find(card => 
            card.dataset.qrCode === cleanedQrData || 
            card.dataset.qrCode.toLowerCase() === cleanedQrData.toLowerCase()
        );
        
        if (appointmentCard) {
            const appointmentId = appointmentCard.dataset.appointmentId;
            const patientName = appointmentCard.dataset.patientFname + ' ' + appointmentCard.dataset.patientLname;
            
            showNotification(`Found appointment for ${patientName}`, 'success');
            
            // Highlight the card visually
            appointmentCard.style.transition = 'all 0.5s ease';
            appointmentCard.style.border = '3px solid var(--success-green)';
            appointmentCard.style.backgroundColor = '#f0fff4';
            
            // Scroll to the card
            appointmentCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Open the detail modal after a short delay
            setTimeout(() => {
                viewAppointmentDetails(appointmentId);
                // Reset card styling
                appointmentCard.style.border = '';
                appointmentCard.style.backgroundColor = '';
                
            }, 800);

        } else {
            showNotification('No matching appointment found for this QR code', 'warning');
            console.warn('QR Data matched no card:', cleanedQrData);
            
            // Restart scanning after a delay if not found
            setTimeout(() => {
                isScanning = true;
            }, 3000);
        }
    }

    async function handleQRCode(qrData) {
        const cleanedQrData = qrData.trim();
        console.log('QR Code detected:', cleanedQrData);
        
        isScanning = false;
        playBeep();

        try {
            showLoading();
            
            const response = await fetch('checkin_qr.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    qr_code: cleanedQrData
                })
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const result = await response.json();
            hideLoading();

            if (result.success) {

                showNotification(`✅ ${result.message}`, 'success');
                
                if (result.data) {
                    console.log('Check-in details:', result.data);

                    const allCards = Array.from(document.querySelectorAll('.appointment-card-new'));
                    const appointmentCard = allCards.find(card => 
                        card.dataset.appointmentId == result.data.appointment_id
                    );
                    
                    if (appointmentCard) {
                        appointmentCard.style.transition = 'all 0.5s ease';
                        appointmentCard.style.border = '3px solid var(--success-green)';
                        appointmentCard.style.backgroundColor = '#f0fff4';
                        appointmentCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        
                        setTimeout(() => {
                            appointmentCard.style.border = '';
                            appointmentCard.style.backgroundColor = '';
                        }, 3000);
                    }
                }
                
                setTimeout(() => {
                    location.reload();
                }, 2000);

            } else {

                showNotification(`⚠️ ${result.message}`, 'warning');
                
                setTimeout(() => {
                    isScanning = true;
                    const video = document.getElementById('qr-video');
                    if (video) {
                        startQRScanning(video);
                    }
                }, 3000);
            }

        } catch (error) {
            hideLoading();
            console.error('Check-in error:', error);
            showNotification('❌ Network error. Please try again.', 'error');

            setTimeout(() => {
                isScanning = true;
                const video = document.getElementById('qr-video');
                if (video) {
                    startQRScanning(video);
                }
            }, 3000);
        }
    }

    function playBeep() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        } catch (e) {
            console.log('Could not play beep sound');
        }
    }

    //Fulter Functionality
    function initializeFilters() {
        const statusFilter = document.getElementById('statusFilter');
        const dateFilter = document.getElementById('dateFilter');
        const searchInput = document.getElementById('searchInput');
        const clearFiltersBtn = document.getElementById('clearFiltersBtn');

        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                currentFilter.status = this.value;
                filterAppointments();
                updateClearButton();
            });
        }

        if (dateFilter) {
            dateFilter.addEventListener('change', function() {
                currentFilter.date = this.value;
                filterAppointments();
                updateClearButton();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', debounce(function() {
                currentFilter.search = this.value.toLowerCase();
                filterAppointments();
                updateClearButton();
            }, 300));
        }

        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function() {
                currentFilter = { status: 'all', date: '', search: '' };
                
                if (statusFilter) statusFilter.value = 'all';
                if (dateFilter) dateFilter.value = '';
                if (searchInput) searchInput.value = '';
                
                filterAppointments();
                updateClearButton();
            });
        }
    }

    function updateClearButton() {
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (!clearBtn) return;

        const hasFilters = currentFilter.status !== 'all' || 
                          currentFilter.date !== '' || 
                          currentFilter.search !== '';
        
        clearBtn.style.display = hasFilters ? 'block' : 'none';
    }

    function filterAppointments() {
        const cards = document.querySelectorAll('.appointment-card-new');
        const emptyState = document.getElementById('emptyState');
        let visibleCount = 0;

        cards.forEach(card => {
            const status = card.dataset.status;
            const date = card.dataset.date;
            const patientName = card.dataset.patientName?.toLowerCase() || '';
            const phone = card.dataset.phone?.toLowerCase() || '';
            
            let shouldShow = true;

            if (currentFilter.status !== 'all' && status !== currentFilter.status) {
                shouldShow = false;
            }

            if (currentFilter.date && date !== currentFilter.date) {
                shouldShow = false;
            }

            if (currentFilter.search && 
                !patientName.includes(currentFilter.search) && 
                !phone.includes(currentFilter.search)) {
                shouldShow = false;
            }

            if (shouldShow) {
                card.style.display = 'flex';
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 10);
                visibleCount++;
            } else {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.display = 'none';
                }, 300);
            }
        });

        if (emptyState) {
            emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    // Appointment Actions
    function initializeAppointmentActions() {
        document.querySelectorAll('.btn-confirm').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const appointmentId = this.dataset.appointmentId;
                confirmAppointment(appointmentId);
            });
        });

        document.querySelectorAll('.btn-complete').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const appointmentId = this.dataset.appointmentId;
                completeAppointment(appointmentId);
            });
        });

        document.querySelectorAll('.btn-cancel').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const appointmentId = this.dataset.appointmentId;
                cancelAppointment(appointmentId);
            });
        });

        document.querySelectorAll('.btn-view').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const appointmentId = this.dataset.appointmentId;
                viewAppointmentDetails(appointmentId);
            });
        });

        document.querySelectorAll('.appointment-card-new').forEach(card => {
            card.addEventListener('click', function() {
                const appointmentId = this.dataset.appointmentId;
                viewAppointmentDetails(appointmentId);
            });
        });
    }

    function confirmAppointment(appointmentId) {
        if (!confirm('Are you sure you want to confirm this appointment?')) return;

        showLoading();

        updateAppointmentStatus(appointmentId, 'confirmed', null)
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('✅ Appointment confirmed successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to confirm appointment', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
            });
    }

    function completeAppointment(appointmentId) {
        if (!confirm('Mark this appointment as completed?')) return;

        showLoading();

        updateAppointmentStatus(appointmentId, 'completed', null)
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('✅ Appointment marked as completed!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to complete appointment', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
            });
    }

    function cancelAppointment(appointmentId) {
        const reason = prompt('Please provide a reason for cancellation:');
        if (!reason) return;

        showLoading();

        updateAppointmentStatus(appointmentId, 'cancelled', reason)
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('❌ Appointment cancelled successfully', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification(data.message || 'Failed to cancel appointment', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showNotification('An error occurred. Please try again.', 'error');
            });
    }

    async function updateAppointmentStatus(appointmentId, status, notes) {
        try {
            const response = await fetch('update_appointment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    appointment_id: appointmentId,
                    status: status,
                    notes: notes
                })
            });
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            return await response.json();
        } catch (error) {
            console.error('Update error:', error);
            throw error;
        }
    }

    // Modal Functionality
    function initializeModal() {
        const modal = document.getElementById('appointmentModal');
        const modalCloseBtn = document.getElementById('modalCloseBtn');
        const modalBackdrop = document.getElementById('modalBackdrop');

        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', closeModal);
        }

        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', closeModal);
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
                closeModal();
            }
        });
    }

    function viewAppointmentDetails(appointmentId) {
        const card = document.querySelector(`[data-appointment-id="${appointmentId}"]`);
        if (!card) return;

        const appointment = {
            id: appointmentId,
            patient_id: card.dataset.patientId,
            patient_name: card.dataset.patientFname + ' ' + card.dataset.patientLname,
            ic_number: card.dataset.icNumber,
            phone: card.dataset.phone,
            email: card.dataset.email,
            blood_type: card.dataset.bloodType,
            age: card.dataset.age,
            appointment_date: card.dataset.date,
            appointment_time: card.dataset.appointmentTime,
            status: card.dataset.status,
            symptoms: card.dataset.symptoms,
            notes: card.dataset.notes,
            qr_code: card.querySelector('.qr-code-text')?.textContent || 'N/A'
        };

        populateModal(appointment);
        openModal();
    }

    function populateModal(appointment) {
    // Set Appointment ID
    const modalIdElement = document.getElementById('modalAppointmentId');
    if (modalIdElement) {
        // We force it to show the ID even if other data is missing
        modalIdElement.textContent = '#' + (appointment.id || 'N/A');
        console.log("Setting Modal ID to:", appointment.id); // This helps you debug in console
    }

    // Patient Info
    document.getElementById('modalPatientName').textContent = appointment.patient_name || 'N/A';
    document.getElementById('modalIcNumber').textContent = appointment.ic_number || 'N/A';
    document.getElementById('modalPhone').textContent = appointment.phone || 'N/A';
    document.getElementById('modalEmail').textContent = appointment.email || 'N/A';
    document.getElementById('modalAge').textContent = (appointment.age || 'N/A') + (appointment.age !== 'N/A' ? ' years' : '');
    document.getElementById('modalBloodType').textContent = appointment.blood_type || 'N/A';
    
    // Appointment Info
    document.getElementById('modalDate').textContent = formatDate(appointment.appointment_date);
    document.getElementById('modalTime').textContent = formatTime(appointment.appointment_time);
    
    // Status Badge Logic
    const statusBadge = document.getElementById('modalStatus');
    if (statusBadge) {
        statusBadge.textContent = appointment.status.charAt(0).toUpperCase() + appointment.status.slice(1);
        statusBadge.className = `status-badge-new status-${appointment.status}`;
    }

    // QR Code
    const qrElement = document.getElementById('modalQrCode');
    if (qrElement) {
        qrElement.textContent = appointment.qr_code || 'N/A';
    }
    
    // Medical Info 
    document.getElementById('modalSymptoms').textContent = appointment.symptoms || 'No symptoms recorded';
    document.getElementById('modalNotes').textContent = appointment.notes || 'No notes';
    
    // Modal Action
    const modalActions = document.getElementById('modalActions');
    modalActions.innerHTML = ''; // Clear old buttons
    
    if (appointment.status === 'pending') {
        modalActions.innerHTML = `
            <button class="btn-action btn-confirm" onclick="window.confirmAppointmentFromModal(${appointment.id})">
                <span class="emoji-icon">✅</span> Confirm Appointment
            </button>
            <button class="btn-action btn-cancel" onclick="window.cancelAppointmentFromModal(${appointment.id})">
                <span class="emoji-icon">❌</span> Cancel Appointment
            </button>
        `;
    } else if (appointment.status === 'confirmed') {
        modalActions.innerHTML = `
            <button class="btn-action btn-complete" onclick="window.completeAppointmentFromModal(${appointment.id})">
                <span class="emoji-icon">✅</span> Mark as Completed
            </button>
            <button class="btn-action btn-cancel" onclick="window.cancelAppointmentFromModal(${appointment.id})">
                <span class="emoji-icon">❌</span> Cancel Appointment
            </button>
        `;
    } else if (appointment.status === 'completed') {
        modalActions.innerHTML = `
            <button class="btn-action btn-medical-records" onclick="window.viewMedicalRecords(${appointment.patient_id})">
                <span class="emoji-icon">📋</span> View Medical Records
            </button>
        `;
    }
}

    function openModal() {
        const modal = document.getElementById('appointmentModal');
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal() {
        const modal = document.getElementById('appointmentModal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Modal action functions (exposed globally)
    window.confirmAppointmentFromModal = function(appointmentId) {
        closeModal();
        confirmAppointment(appointmentId);
    };

    window.completeAppointmentFromModal = function(appointmentId) {
        closeModal();
        completeAppointment(appointmentId);
    };

    window.cancelAppointmentFromModal = function(appointmentId) {
        closeModal();
        cancelAppointment(appointmentId);
    };

    window.viewMedicalRecords = function(patientId) {
        window.location.href = `medicalRecords.php?patient_id=${patientId}`;
    };

    // Utility Functions
    function showLoading() {
        let loader = document.getElementById('globalLoader');
        if (!loader) {
            loader = document.createElement('div');
            loader.id = 'globalLoader';
            loader.className = 'loading-overlay';
            loader.innerHTML = '<div class="loading-spinner"></div><p>⏳ Processing...</p>';
            document.body.appendChild(loader);
        }
        loader.style.display = 'flex';
    }

    function hideLoading() {
        const loader = document.getElementById('globalLoader');
        if (loader) {
            loader.style.display = 'none';
        }
    }

    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        return date.toLocaleDateString('en-US', options);
    }

    function formatTime(timeString) {
        const [hours, minutes] = timeString.split(':');
        const date = new Date();
        date.setHours(hours);
        date.setMinutes(minutes);
        return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    // Export functions for global access
    window.confirmAppointment = confirmAppointment;
    window.completeAppointment = completeAppointment;
    window.cancelAppointment = cancelAppointment;
    window.viewAppointmentDetails = viewAppointmentDetails;

})();